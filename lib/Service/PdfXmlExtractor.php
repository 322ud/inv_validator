<?php

declare(strict_types=1);

namespace OCA\INValidator\Service;

use OCA\INValidator\Exception\DocumentInputException;
use OCA\INValidator\Exception\ValidationException;
use OCP\ITempManager;

class PdfXmlExtractor
{
    private const MAX_XML_BYTES = 5 * 1024 * 1024;

    private ValidatorProcessRunner $processRunner;

    public function __construct(
        private ValidatorConfigService $config,
        private PdfSupportService $supportService,
        private ITempManager $tempManager,
        ?ValidatorProcessRunner $processRunner = null
    ) {
        $this->processRunner = $processRunner ?? new ValidatorProcessRunner(30.0, 1.0, 65536);
    }

    public function extract(string $pdfPath): string
    {
        if (!$this->supportService->getStatus()['available']) {
            throw new DocumentInputException(
                'PDF-Eingabe ist auf diesem Server nicht verfügbar.',
                415
            );
        }

        $tempDir = $this->createTempDirectory();
        $outputPath = $tempDir . '/invoice.xml';

        try {
            $result = $this->processRunner->run([
                $this->config->getPythonBinary(),
                '-I',
                '-s',
                $this->config->getPdfExtractorScript(),
                $pdfPath,
                $outputPath,
            ]);

            if ($result['exitCode'] !== 0) {
                throw $this->exceptionForExitCode($result['exitCode']);
            }

            if (!is_file($outputPath) || is_link($outputPath)) {
                throw new ValidationException('PDF extractor produced no XML file');
            }

            $size = filesize($outputPath);
            if ($size === false || $size > self::MAX_XML_BYTES) {
                throw new DocumentInputException('Eingebettete XML-Datei ist zu groß.', 413);
            }

            $xml = file_get_contents($outputPath);
            if ($xml === false) {
                throw new ValidationException('Extracted XML file could not be read');
            }

            return $xml;
        } finally {
            $this->removeDirectory($tempDir);
        }
    }

    private function createTempDirectory(): string
    {
        $directory = $this->tempManager->getTemporaryFolder('inv-validator');
        if ($directory === false) {
            throw new ValidationException('Nextcloud konnte kein temporäres Arbeitsverzeichnis anlegen.');
        }
        return rtrim($directory, '/');
    }

    private function exceptionForExitCode(int $exitCode): \Throwable
    {
        return match ($exitCode) {
            10 => new DocumentInputException('PDF enthält keine eingebettete XML-Datei.', 422),
            11 => new DocumentInputException('PDF enthält mehrere mögliche Rechnungs-XML-Dateien.', 422),
            12 => new DocumentInputException('Eingebettete XML-Datei ist zu groß.', 413),
            13 => new DocumentInputException('PDF enthält zu viele eingebettete Dateien.', 422),
            14 => new DocumentInputException('Verschlüsselte PDFs werden nicht unterstützt.', 422),
            15 => new DocumentInputException('Ungültiger PDF-Inhalt.', 400),
            20 => new DocumentInputException('PDF-Eingabe ist auf diesem Server nicht verfügbar.', 415),
            default => new ValidationException('PDF extraction failed with exit code ' . $exitCode),
        };
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_file($path) || is_link($path)) {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
