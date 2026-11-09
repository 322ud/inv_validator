<?php

declare(strict_types=1);

namespace OCA\INValidator\Service;

use OCA\INValidator\Exception\ValidationException;

class KoSITValidatorService
{
    private const MAX_REPORT_BYTES = 10 * 1024 * 1024;

    private ValidatorProcessRunner $processRunner;

    public function __construct(
        private ValidatorConfigService $config,
        ?ValidatorProcessRunner $processRunner = null
    ) {
        $this->processRunner = $processRunner ?? new ValidatorProcessRunner();
    }

    public function validateXml(string $xmlContent): ValidationResult
    {
        $this->validateEnvironment();

        $tempDir = $this->createTempDirectory();
        $lock = null;

        try {
            $lock = $this->acquireExecutionLock();
            $xmlPath = $this->writeXmlFile($xmlContent, $tempDir);
            $command = $this->buildCommand($xmlPath, $tempDir);
            $processResult = $this->processRunner->run($command);

            return $this->buildValidationResult($processResult, $tempDir);
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }

            $this->removeDirectory($tempDir);
        }
    }

    /** @return resource */
    private function acquireExecutionLock()
    {
        $lockPath = $this->config->getTempDirectory() . '/validator.lock';
        $lock = fopen($lockPath, 'c');

        if ($lock === false) {
            throw new ValidationException('Validator lock could not be created');
        }

        chmod($lockPath, 0600);

        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new ValidationException('Validator is already processing another document');
        }

        return $lock;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;

            if (is_link($path)) {
                unlink($path);
                continue;
            }

            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            if (is_file($path)) {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    private function validateEnvironment(): void
    {
        $javaBinary = $this->config->getJavaBinary();
        $validatorJar = $this->config->getValidatorJar();
        $repositoryPath = $this->config->getRepositoryPath();
        $scenarioFile = $this->config->getScenarioFile();

        if (!file_exists($javaBinary)) {
            throw new ValidationException(
                "Java-Binary nicht gefunden: {$javaBinary}"
            );
        }

        if (!is_executable($javaBinary)) {
            throw new ValidationException(
                "Java-Binary nicht ausführbar: {$javaBinary}"
            );
        }

        if (!is_file($validatorJar)) {
            throw new ValidationException(
                "Validator-JAR nicht gefunden: {$validatorJar}"
            );
        }

        try {
            $this->config->assertValidatorJarIsSafe($validatorJar);
        } catch (\InvalidArgumentException $e) {
            throw new ValidationException($e->getMessage(), 0, $e);
        }

        if (!is_dir($repositoryPath)) {
            throw new ValidationException(
                "Repository nicht gefunden: {$repositoryPath}"
            );
        }

        if (!is_file($scenarioFile)) {
            throw new ValidationException(
                "Szenario-Datei nicht gefunden: {$scenarioFile}"
            );
        }
    }

    private function createTempDirectory(): string
    {
        $tempDir = $this->config->getTempDirectory()
        . '/inv-validator-'
        . bin2hex(random_bytes(16));

        if (!mkdir($tempDir, 0700, true) && !is_dir($tempDir)) {
            throw new ValidationException('Could not create temporary directory');
        }

        return $tempDir;
    }

    private function writeXmlFile(
        string $xmlContent,
        string $tempDir
    ): string {
        $xmlFile = $tempDir . '/document.xml';

        if (file_put_contents($xmlFile, $xmlContent) === false) {
            throw new ValidationException(
                "XML-Datei konnte nicht geschrieben werden"
            );
        }

        return $xmlFile;
    }

    /** @return list<string> */
    private function buildCommand(string $xmlFile, string $tempDir): array
    {
        $configuredRepository = $this->config->getRepositoryPath();
        $repository = realpath($configuredRepository) ?: $configuredRepository;

        return [
            $this->config->getJavaBinary(),
            '-Xmx512m',
            '-XX:ActiveProcessorCount=1',
            '-jar',
            $this->config->getValidatorJar(),
            '-s',
            $repository . '/scenarios.xml',
            '-r',
            $repository,
            '-o',
            $tempDir,
            '-h',
            $xmlFile,
        ];
    }

    /** @param array{exitCode: int, stdout: string, stderr: string} $processResult */
    private function buildValidationResult(
        array $processResult,
        string $tempDir
    ): ValidationResult {
        $reports = $this->findReportFiles($tempDir);

        if ($reports['xml'] === null) {
            throw new ValidationException(sprintf(
                'Validator produced no XML report (exit code %d): %s',
                $processResult['exitCode'],
                substr(trim($processResult['stderr']), -4096)
            ));
        }

        $valid = $this->readReportValidity($reports['xml']);

        $reportHtml = null;

        if (
            $reports['html'] !== null &&
            is_readable($reports['html'])
        ) {
            $reportSize = filesize($reports['html']);

            if ($reportSize === false || $reportSize > self::MAX_REPORT_BYTES) {
                throw new ValidationException('HTML report exceeds the size limit');
            }

            $reportHtml = file_get_contents($reports['html']);

            if ($reportHtml === false) {
                $reportHtml = null;
            }
        }

        return new ValidationResult(
            $valid,
            $processResult['exitCode'],
            $processResult['stdout'],
            $processResult['stderr'],
            $reportHtml
        );
    }

    private function readReportValidity(string $reportPath): bool
    {
        $reportSize = filesize($reportPath);

        if ($reportSize === false || $reportSize > self::MAX_REPORT_BYTES) {
            throw new ValidationException('XML report exceeds the size limit');
        }

        $reportXml = file_get_contents($reportPath);

        if ($reportXml === false) {
            throw new ValidationException('Validator XML report could not be read');
        }

        if (preg_match('/<!DOCTYPE\s/i', $reportXml) === 1) {
            throw new ValidationException('Validator report contains a DOCTYPE declaration');
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $document = new \DOMDocument();
            $loaded = $document->loadXML(
                $reportXml,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
            );

            if (!$loaded) {
                $errors = libxml_get_errors();
                $detail = isset($errors[0])
                    ? trim($errors[0]->message) . ' at line ' . $errors[0]->line
                    : 'unknown XML parsing error';

                throw new ValidationException(
                    'Validator produced an unreadable XML report: ' . $detail
                );
            }

            $xpath = new \DOMXPath($document);
            // Use an internal prefix that cannot be shadowed by prefixes declared
            // in an untrusted validator report.
            $xpath->registerNamespace('varl', 'http://www.xoev.de/de/validator/varl/1');

            $acceptedNodes = $xpath->query(
                '/varl:report/varl:assessment/varl:accept',
                null,
                false
            );
            $rejectedNodes = $xpath->query(
                '/varl:report/varl:assessment/varl:reject',
                null,
                false
            );

            if ($acceptedNodes === false || $rejectedNodes === false) {
                throw new ValidationException('Validator report assessment could not be queried');
            }

            $accepted = $acceptedNodes->length > 0;
            $rejected = $rejectedNodes->length > 0;

            if ($accepted && $rejected) {
                throw new ValidationException('Validator report contains conflicting assessments');
            }

            if ($accepted) {
                return true;
            }

            if ($rejected) {
                return false;
            }

            throw new ValidationException('Validator report contains no assessment');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @return array{html: ?string, xml: ?string} */
    private function findReportFiles(string $tempDir): array
    {
        $result = [
            'html' => null,
            'xml' => null,
        ];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $tempDir,
                \FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $ext = strtolower($file->getExtension());
            $filename = strtolower($file->getFilename());

            if ($ext === 'html' && $result['html'] === null) {
                $result['html'] = $file->getPathname();
            }

            if (
                $ext === 'xml' &&
                str_contains($filename, 'report') &&
                $result['xml'] === null
            ) {
                $result['xml'] = $file->getPathname();
            }
        }

        return $result;
    }
}
