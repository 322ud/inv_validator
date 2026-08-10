<?php

declare(strict_types=1);

namespace OCA\KoSITValidator\Service;

use OCA\KoSITValidator\Exception\ValidationException;

class KoSITValidatorService {
    public function __construct(
        private ValidatorConfigService $config
    ) {}

    public function validateXml(string $xmlContent): ValidationResult {
        $this->validateEnvironment();

        $tempDir = $this->createTempDirectory();

        try {
            $xmlPath = $this->writeXmlFile($xmlContent, $tempDir);
            $command = $this->buildCommand($xmlPath, $tempDir);
            $processResult = $this->runCommand($command);

            return $this->buildValidationResult($processResult, $tempDir);
        } finally {
            $this->removeDirectory($tempDir);
        }
    }

    private function removeDirectory(string $dir): void {
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

    private function createTempDirectory(): string {
        $tempDir = $this->config->getTempDirectory()
        . '/kosit-validator-'
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

    private function buildCommand(string $xmlFile, string $tempDir): array {
        return [
            $this->config->getJavaBinary(),
            '-Xmx512m',
            '-XX:ActiveProcessorCount=1',
            '-jar',
            $this->config->getValidatorJar(),
            '-s',
            $this->config->getScenarioFile(),
            '-r',
            $this->config->getRepositoryPath(),
            '-o',
            $tempDir,
            $xmlFile,
        ];
    }

    private function runCommand(array $command): array {
        $process = proc_open(
            $command,
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        if (!is_resource($process)) {
            throw new ValidationException('Validator process could not be started');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';

        $start = time();
        $timeout = 60;

        while (true) {
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';

            $status = proc_get_status($process);

            if (!$status['running']) {
                break;
            }

            if ((time() - $start) > $timeout) {
                proc_terminate($process);

                fclose($pipes[1]);
                fclose($pipes[2]);

                proc_close($process);

                throw new ValidationException('Validator process timed out');
            }

            usleep(100000);
        }

        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'exitCode' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    private function buildValidationResult(
        array $processResult,
        string $tempDir
    ): ValidationResult {
        $reports = $this->findReportFiles($tempDir);

        $reportHtml = null;

        if (
            $reports['html'] !== null &&
            is_readable($reports['html'])
        ) {
            $reportHtml = file_get_contents($reports['html']);

            if ($reportHtml === false) {
                $reportHtml = null;
            }
        }

        return new ValidationResult(
            $processResult['exitCode'] === 0,
            $processResult['exitCode'],
            $processResult['stdout'],
            $processResult['stderr'],
            $reports['html'],
            $reports['xml'],
            $reportHtml
        );
    }

    private function findReportFiles(string $tempDir): array {
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
