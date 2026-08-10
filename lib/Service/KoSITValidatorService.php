<?php

declare(strict_types=1);

namespace OCA\KoSITValidator\Service;

use OCA\KoSITValidator\Exception\ValidationException;

class KoSITValidatorService {
    private const PROCESS_TIMEOUT_SECONDS = 60;
    private const MAX_PROCESS_OUTPUT_BYTES = 1024 * 1024;
    private const MAX_REPORT_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private ValidatorConfigService $config
    ) {}

    public function validateXml(string $xmlContent): ValidationResult {
        $this->validateEnvironment();

        $tempDir = $this->createTempDirectory();
        $lock = null;

        try {
            $lock = $this->acquireExecutionLock();
            $xmlPath = $this->writeXmlFile($xmlContent, $tempDir);
            $command = $this->buildCommand($xmlPath, $tempDir);
            $processResult = $this->runCommand($command);

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
    private function acquireExecutionLock() {
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
            '-h',
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

        $start = microtime(true);
        $lastStatus = null;

        while (true) {
            $this->appendProcessOutput($stdout, stream_get_contents($pipes[1]));
            $this->appendProcessOutput($stderr, stream_get_contents($pipes[2]));

            $status = proc_get_status($process);
            $lastStatus = $status;

            if (!$status['running']) {
                break;
            }

            if ((microtime(true) - $start) >= self::PROCESS_TIMEOUT_SECONDS) {
                proc_terminate($process);

                $graceDeadline = microtime(true) + 2.0;
                do {
                    usleep(100000);
                    $status = proc_get_status($process);
                } while ($status['running'] && microtime(true) < $graceDeadline);

                if ($status['running']) {
                    proc_terminate($process, 9);
                }

                fclose($pipes[1]);
                fclose($pipes[2]);

                proc_close($process);

                throw new ValidationException('Validator process timed out');
            }

            usleep(100000);
        }

        $this->appendProcessOutput($stdout, stream_get_contents($pipes[1]));
        $this->appendProcessOutput($stderr, stream_get_contents($pipes[2]));

        fclose($pipes[1]);
        fclose($pipes[2]);

        $closeExitCode = proc_close($process);
        $statusExitCode = is_array($lastStatus) ? $lastStatus['exitcode'] : -1;
        $exitCode = $statusExitCode >= 0 ? $statusExitCode : $closeExitCode;

        return [
            'exitCode' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    private function appendProcessOutput(string &$target, string|false $chunk): void {
        if ($chunk === false || $chunk === '') {
            return;
        }

        $remaining = self::MAX_PROCESS_OUTPUT_BYTES - strlen($target);

        if ($remaining > 0) {
            $target .= substr($chunk, 0, $remaining);
        }
    }

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

    private function readReportValidity(string $reportPath): bool {
        $reportSize = filesize($reportPath);

        if ($reportSize === false || $reportSize > self::MAX_REPORT_BYTES) {
            throw new ValidationException('XML report exceeds the size limit');
        }

        $reportXml = file_get_contents($reportPath);

        if ($reportXml === false) {
            throw new ValidationException('Validator XML report could not be read');
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
                $fallbackValidity = $this->readRawReportValidity($reportXml);

                if ($fallbackValidity !== null) {
                    return $fallbackValidity;
                }

                $errors = libxml_get_errors();
                $detail = isset($errors[0])
                    ? trim($errors[0]->message) . ' at line ' . $errors[0]->line
                    : 'unknown XML parsing error';

                throw new ValidationException(
                    'Validator produced an unreadable XML report: ' . $detail
                );
            }

            $xpath = new \DOMXPath($document);
            $xpath->registerNamespace('rep', 'http://www.xoev.de/de/validator/varl/1');

            if ($xpath->query('/rep:report/rep:assessment/rep:accept')?->length > 0) {
                return true;
            }

            if ($xpath->query('/rep:report/rep:assessment/rep:reject')?->length > 0) {
                return false;
            }

            throw new ValidationException('Validator report contains no assessment');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function readRawReportValidity(string $reportXml): ?bool {
        if (preg_match(
            '~<rep:assessment\b[^>]*>.*?<rep:accept\b~s',
            $reportXml
        ) === 1) {
            return true;
        }

        if (preg_match(
            '~<rep:assessment\b[^>]*>.*?<rep:reject\b~s',
            $reportXml
        ) === 1) {
            return false;
        }

        return null;
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
