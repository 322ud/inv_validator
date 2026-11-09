<?php

declare(strict_types=1);

namespace OCA\INValidator\Service;

/** Diagnostics run in the web server's PHP context, without installing components. */
class SystemCheckService
{
    public function __construct(private ValidatorConfigService $config)
    {
    }

    /** @param array<string, mixed> $status
     *  @return list<array<string, mixed>>
     */
    public function check(array $status): array
    {
        $processes = function_exists('proc_open');
        $javaVersion = null;
        $javaOk = false;
        $javaDetail = $this->config->getJavaBinary();
        if ($processes && is_executable($javaDetail)) {
            try {
                $result = (new ValidatorProcessRunner(5.0, 0.5, 65536))->run([$javaDetail, '-version']);
                if (preg_match('/version\s+"([^\"]+)"/', $result['stdout'] . $result['stderr'], $matches)) {
                    $javaVersion = $matches[1];
                    $major = str_starts_with($javaVersion, '1.') ? (int)substr($javaVersion, 2) : (int)$javaVersion;
                    $javaOk = $result['exitCode'] === 0 && $major >= 11;
                }
            } catch (\Throwable) {
                // A failed executable is a diagnostic result, not an admin-page error.
            }
        }
        $jar = $this->config->getValidatorJar();
        $jarOk = is_file($jar) && is_readable($jar);
        try {
            $this->config->assertValidatorJarIsSafe($jar);
        } catch (\InvalidArgumentException) {
            $jarOk = false;
        }
        $scenario = $this->config->getScenarioFile();
        $scenarioOk = is_file($scenario) && is_readable($scenario);
        if ($scenarioOk) {
            $previous = libxml_use_internal_errors(true);
            try {
                $content = file_get_contents($scenario);
                $document = new \DOMDocument();
                $scenarioOk = $content !== false && !preg_match('/<!DOCTYPE\s/i', $content)
                    && $document->loadXML($content, LIBXML_NONET)
                    && $document->documentElement?->localName === 'scenarios'
                    && $document->documentElement->namespaceURI === 'http://www.xoev.de/de/validator/framework/1/scenarios';
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
        }
        $resources = $this->config->getRepositoryPath() . '/resources';
        $scenarioOk = $scenarioOk && is_dir($resources) && is_readable($resources);
        $pdf = $status['pdfSupport'];
        $rows = [
            $this->row('php', 'PHP-Prozessstart', $processes, PHP_VERSION, $processes ? 'proc_open verfügbar' : 'proc_open nicht verfügbar', 'php'),
            $this->row('java', 'Java (mindestens 11)', $javaOk, $javaVersion, $javaDetail, 'java'),
            $this->row('validator', 'KoSIT-Validator', $jarOk, $status['validator']['installedVersion'], $jar, 'components'),
            $this->row(
                'configuration',
                'XRechnung-Konfiguration',
                $scenarioOk,
                $status['configuration']['installedXRechnungVersion'] ?? null,
                $scenario,
                'components'
            ),
            $this->row(
                'temp',
                'Temporäres Verzeichnis',
                $this->probeWritableDirectory($this->config->getTempDirectory()),
                null,
                $this->config->getTempDirectory(),
                'paths'
            ),
            $this->row(
                'validator-write',
                'Validator-Installation',
                $this->probeWritableDirectory(dirname($jar)),
                null,
                dirname($jar),
                'paths'
            ),
            $this->row(
                'configuration-write',
                'Konfigurationsinstallation',
                $this->probeWritableDirectory(dirname($this->config->getRepositoryPath()) . '/releases')
                    && $this->probeWritableDirectory(dirname($this->config->getRepositoryPath())),
                null,
                dirname($this->config->getRepositoryPath()),
                'paths'
            ),
            $this->row(
                'zip',
                'PHP ZipArchive (Installation/Updates)',
                class_exists(\ZipArchive::class),
                class_exists(\ZipArchive::class) ? (phpversion('zip') ?: null) : null,
                class_exists(\ZipArchive::class) ? 'Verfügbar' : 'Nicht verfügbar',
                'php'
            ),
            $this->row(
                'python',
                'Python (optional für PDF)',
                $pdf['pythonAvailable'],
                $pdf['pythonVersion'],
                $this->config->getPythonBinary(),
                'pdf'
            ),
            $this->row(
                'pymupdf',
                'PyMuPDF (optional für PDF)',
                $pdf['available'],
                $pdf['pymupdfVersion'],
                $pdf['reason'] ?? 'PDF-Unterstützung verfügbar',
                'pdf'
            ),
        ];
        foreach ($rows as &$row) {
            if (in_array($row['id'], ['validator', 'configuration'], true)) {
                $component = $status[$row['id']];
                $row['updateError'] = $component['updateError'];
                $row['action'] = $component['updateError'] !== null ? null
                    : ($component['updateAvailable'] ? 'update' : (!$component['installed'] && !$row['ok'] ? 'install' : null));
                $row['latestVersion'] = $component['latestVersion'];
                if ($component['updateError'] !== null) {
                    $row['help'] = 'updates';
                }
            }
        }
        unset($row);
        return $rows;
    }

    /** @return array<string, mixed> */
    private function row(string $id, string $label, bool $ok, ?string $version, string $detail, string $help): array
    {
        return compact('id', 'label', 'ok', 'version', 'detail', 'help');
    }

    /** Create and remove a private probe tree when the target does not yet exist. */
    private function probeWritableDirectory(string $directory): bool
    {
        $parent = $directory;
        while (!file_exists($parent) && !is_link($parent) && dirname($parent) !== $parent) {
            $parent = dirname($parent);
        }
        if (!is_dir($parent) || !is_writable($parent) || !is_executable($parent)) {
            return false;
        }
        $probeDirectory = null;
        $probeFile = null;
        try {
            if ($parent !== $directory) {
                $probeDirectory = $parent . '/.inv-validator-check-' . bin2hex(random_bytes(8));
                if (!@mkdir($probeDirectory, 0700)) {
                    return false;
                }
                $parent = $probeDirectory;
            }
            $probeFile = $parent . '/.inv-validator-check-' . bin2hex(random_bytes(8));
            $handle = @fopen($probeFile, 'x');
            if ($handle === false) {
                return false;
            }
            $written = fwrite($handle, 'check');
            fclose($handle);
            return $written === 5;
        } finally {
            if ($probeFile !== null && is_file($probeFile)) {
                unlink($probeFile);
            }
            if ($probeDirectory !== null && is_dir($probeDirectory)) {
                rmdir($probeDirectory);
            }
        }
    }
}
