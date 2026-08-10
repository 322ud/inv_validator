<?php

declare(strict_types=1);

namespace OCA\KoSITValidator\Service;

use OCA\KoSITValidator\Exception\AdministrationException;
use OCP\Http\Client\IClientService;

class ValidatorAdminService {
    private const RELEASE_API = 'https://api.github.com/repos/itplr-kosit/validator/releases/latest';
    private const RELEASE_PAGE = 'https://github.com/itplr-kosit/validator/releases';
    private const MAX_DOWNLOAD_BYTES = 150 * 1024 * 1024;
    private const MAX_SCENARIO_BYTES = 2 * 1024 * 1024;
    private const SCENARIO_NAMESPACE = 'http://www.xoev.de/de/validator/framework/1/scenarios';

    public function __construct(
        private ValidatorConfigService $config,
        private IClientService $clientService
    ) {}

    /** @return array<string, mixed> */
    public function getStatus(bool $checkUpdates = true): array {
        $jar = $this->config->getValidatorJar();
        $installedVersion = $this->readInstalledVersion($jar);
        $release = null;
        $updateError = null;

        if ($checkUpdates) {
            try {
                $release = $this->fetchLatestRelease();
            } catch (\Throwable $e) {
                $updateError = $e->getMessage();
            }
        }

        $latestVersion = $release['version'] ?? null;

        return [
            'paths' => [
                ['label' => 'App-Verzeichnis', 'path' => $this->config->getAppDirectory(), 'state' => 'available'],
                ['label' => 'PHP', 'path' => $this->config->getPhpBinary(), 'state' => $this->fileState($this->config->getPhpBinary(), true)],
                ['label' => 'Java', 'key' => 'javaBinary', 'path' => $this->config->getJavaBinary(), 'state' => $this->fileState($this->config->getJavaBinary(), true)],
                ['label' => 'Validator-JAR', 'key' => 'validatorJar', 'path' => $jar, 'state' => $this->fileState($jar)],
                ['label' => 'Repository', 'key' => 'repositoryPath', 'path' => $this->config->getRepositoryPath(), 'state' => $this->directoryState($this->config->getRepositoryPath())],
                ['label' => 'Szenario-Datei', 'key' => 'scenarioFile', 'path' => $this->config->getScenarioFile(), 'state' => $this->fileState($this->config->getScenarioFile())],
                ['label' => 'Temporäres Verzeichnis', 'key' => 'tempDirectory', 'path' => $this->config->getTempDirectory(), 'state' => $this->directoryState($this->config->getTempDirectory(), true)],
            ],
            'installed' => is_file($jar),
            'installedVersion' => $installedVersion,
            'latestVersion' => $latestVersion,
            'updateAvailable' => $installedVersion !== null
                && $latestVersion !== null
                && version_compare($latestVersion, $installedVersion, '>'),
            'releaseUrl' => self::RELEASE_PAGE,
            'updateError' => $updateError,
        ];
    }

    /** @return array<string, mixed> */
    public function installLatest(): array {
        $release = $this->fetchLatestRelease();
        $asset = $release['asset'];
        $target = $this->config->getValidatorJar();
        $targetDirectory = dirname($target);

        if (!is_dir($targetDirectory)
            && !mkdir($targetDirectory, 0750, true)
            && !is_dir($targetDirectory)) {
            throw new AdministrationException(
                'Installationsverzeichnis konnte nicht angelegt werden: ' . $targetDirectory
            );
        }

        if (!is_writable($targetDirectory)) {
            throw new AdministrationException(
                'Keine Schreibberechtigung für das Installationsverzeichnis: ' . $targetDirectory
            );
        }

        $lockPath = $targetDirectory . '/.kosit-validator-install.lock';
        $lock = fopen($lockPath, 'c');

        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new AdministrationException('Eine Installation läuft bereits.');
        }

        $temporary = null;

        try {
            $body = $this->download($asset['url']);
            $this->verifyDownload($body, $asset['digest'] ?? null);

            $temporary = tempnam($targetDirectory, '.validator-');
            if ($temporary === false) {
                throw new AdministrationException('Temporäre Installationsdatei konnte nicht angelegt werden.');
            }

            if (file_put_contents($temporary, $body, LOCK_EX) !== strlen($body)) {
                throw new AdministrationException('Validator-JAR konnte nicht vollständig geschrieben werden.');
            }

            chmod($temporary, 0640);

            if (!rename($temporary, $target)) {
                throw new AdministrationException('Validator-JAR konnte nicht aktiviert werden.');
            }
            $temporary = null;
            $this->writeVersionMarker($target, $release['version']);
        } finally {
            if ($temporary !== null && is_file($temporary)) {
                unlink($temporary);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $status = $this->getStatus(false);
        $status['latestVersion'] = $release['version'];
        $status['updateAvailable'] = $status['installedVersion'] !== null
            && version_compare($release['version'], $status['installedVersion'], '>');

        return $status;
    }

    /** @return array{path: string, content: string, checksum: string, writable: bool} */
    public function readScenario(): array {
        $path = $this->config->getScenarioFile();

        if (!is_file($path) || !is_readable($path)) {
            throw new AdministrationException('Szenario-Datei kann nicht gelesen werden: ' . $path);
        }

        $size = filesize($path);
        if ($size === false || $size > self::MAX_SCENARIO_BYTES) {
            throw new AdministrationException('Szenario-Datei ist größer als 2 MB.');
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new AdministrationException('Szenario-Datei konnte nicht geladen werden.');
        }

        return [
            'path' => $path,
            'content' => $content,
            'checksum' => hash('sha256', $content),
            'writable' => is_writable($path) && is_writable(dirname($path)),
        ];
    }

    public function saveScenario(mixed $content, mixed $checksum): void {
        if (!is_string($content) || $content === '') {
            throw new \InvalidArgumentException('Der Inhalt der Szenario-Datei darf nicht leer sein.');
        }
        if (strlen($content) > self::MAX_SCENARIO_BYTES) {
            throw new \InvalidArgumentException('Szenario-Datei ist größer als 2 MB.');
        }
        if (!is_string($checksum) || preg_match('/^[a-f0-9]{64}$/', $checksum) !== 1) {
            throw new \InvalidArgumentException('Ungültiger Änderungsstand der Szenario-Datei.');
        }

        $this->validateScenarioXml($content);

        $path = $this->config->getScenarioFile();
        if (!is_file($path) || !is_readable($path)) {
            throw new AdministrationException('Szenario-Datei kann nicht gelesen werden: ' . $path);
        }
        if (!is_writable($path) || !is_writable(dirname($path))) {
            throw new AdministrationException('Keine Schreibberechtigung für die Szenario-Datei: ' . $path);
        }

        $lock = fopen($path, 'r');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new AdministrationException('Szenario-Datei konnte nicht gesperrt werden.');
        }

        $temporary = null;
        try {
            $current = file_get_contents($path);
            if ($current === false) {
                throw new AdministrationException('Szenario-Datei konnte nicht erneut gelesen werden.');
            }
            if (!hash_equals($checksum, hash('sha256', $current))) {
                throw new AdministrationException(
                    'Die Szenario-Datei wurde zwischenzeitlich geändert. Bitte neu laden.'
                );
            }

            $temporary = tempnam(dirname($path), '.scenario-');
            if ($temporary === false) {
                throw new AdministrationException('Temporäre Szenario-Datei konnte nicht angelegt werden.');
            }
            if (file_put_contents($temporary, $content, LOCK_EX) !== strlen($content)) {
                throw new AdministrationException('Szenario-Datei konnte nicht vollständig geschrieben werden.');
            }

            $mode = fileperms($path);
            chmod($temporary, $mode === false ? 0640 : ($mode & 0777));
            if (!rename($temporary, $path)) {
                throw new AdministrationException('Szenario-Datei konnte nicht aktiviert werden.');
            }
            $temporary = null;
        } finally {
            if ($temporary !== null && is_file($temporary)) {
                unlink($temporary);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function validateScenarioXml(string $content): void {
        if (preg_match('/<!DOCTYPE\s/i', $content) === 1) {
            throw new \InvalidArgumentException('DOCTYPE-Deklarationen sind nicht erlaubt.');
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $document = new \DOMDocument();
            $loaded = $document->loadXML($content, LIBXML_NONET | LIBXML_NOBLANKS);
            $error = libxml_get_last_error();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (!$loaded) {
            $detail = $error instanceof \LibXMLError ? trim($error->message) : 'unbekannter XML-Fehler';
            throw new \InvalidArgumentException('Ungültiges XML: ' . $detail);
        }

        $root = $document->documentElement;
        if ($root === null
            || $root->localName !== 'scenarios'
            || $root->namespaceURI !== self::SCENARIO_NAMESPACE) {
            throw new \InvalidArgumentException(
                'Das Wurzelelement muss „scenarios“ im KoSIT-Szenario-Namensraum sein.'
            );
        }
    }

    /** @return array{version: string, asset: array{url: string, digest: ?string}} */
    private function fetchLatestRelease(): array {
        $response = $this->clientService->newClient()->get(self::RELEASE_API, [
            'connect_timeout' => 10,
            'timeout' => 20,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'Nextcloud-KoSIT-Validator',
                'X-GitHub-Api-Version' => '2022-11-28',
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new AdministrationException(
                'GitHub-Release-Prüfung fehlgeschlagen (HTTP ' . $response->getStatusCode() . ').'
            );
        }

        $data = json_decode((string)$response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        $tag = is_array($data) ? (string)($data['tag_name'] ?? '') : '';
        $version = ltrim($tag, 'vV');

        if (!preg_match('/^[0-9]+(?:\.[0-9]+)+(?:[-+][A-Za-z0-9.-]+)?$/', $version)) {
            throw new AdministrationException('GitHub lieferte keine gültige Release-Version.');
        }

        $expectedName = 'validator-' . $version . '-standalone.jar';
        foreach (($data['assets'] ?? []) as $asset) {
            if (!is_array($asset) || ($asset['name'] ?? null) !== $expectedName) {
                continue;
            }

            $url = (string)($asset['browser_download_url'] ?? '');
            if (!str_starts_with($url, 'https://github.com/itplr-kosit/validator/releases/download/')) {
                throw new AdministrationException('GitHub lieferte eine unerwartete Download-Adresse.');
            }

            return [
                'version' => $version,
                'asset' => [
                    'url' => $url,
                    'digest' => isset($asset['digest']) ? (string)$asset['digest'] : null,
                ],
            ];
        }

        throw new AdministrationException('Standalone-JAR im neuesten GitHub-Release nicht gefunden.');
    }

    private function download(string $url): string {
        $response = $this->clientService->newClient()->get($url, [
            'connect_timeout' => 15,
            'timeout' => 120,
            'headers' => ['User-Agent' => 'Nextcloud-KoSIT-Validator'],
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new AdministrationException(
                'Validator-Download fehlgeschlagen (HTTP ' . $response->getStatusCode() . ').'
            );
        }

        $body = (string)$response->getBody();
        if ($body === '' || strlen($body) > self::MAX_DOWNLOAD_BYTES) {
            throw new AdministrationException('Validator-Download ist leer oder überschreitet 150 MB.');
        }

        return $body;
    }

    private function verifyDownload(string $body, ?string $digest): void {
        if (!str_starts_with($body, "PK\x03\x04")) {
            throw new AdministrationException('Der Download ist keine gültige JAR-Datei.');
        }

        if ($digest !== null && str_starts_with($digest, 'sha256:')) {
            $expected = substr($digest, 7);
            if (!hash_equals(strtolower($expected), hash('sha256', $body))) {
                throw new AdministrationException('SHA-256-Prüfung des Downloads fehlgeschlagen.');
            }
        }
    }

    private function readInstalledVersion(string $jar): ?string {
        if (!is_file($jar)) {
            return null;
        }

        $marker = $this->readVersionMarker($jar);
        if ($marker !== null) {
            return $marker;
        }

        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            if ($zip->open($jar) === true) {
                try {
                    $manifest = $zip->getFromName('META-INF/MANIFEST.MF');
                } finally {
                    $zip->close();
                }

                if (is_string($manifest)
                    && preg_match('/^(?:Implementation-Version|Bundle-Version):\s*(\S+)/mi', $manifest, $matches) === 1) {
                    return ltrim(trim($matches[1]), 'vV');
                }
            }
        }

        return preg_match(
            '/validator-([0-9]+(?:\.[0-9]+)+(?:[-+][A-Za-z0-9.-]+)?)-standalone\.jar$/',
            basename($jar),
            $matches
        ) === 1 ? $matches[1] : null;
    }

    private function writeVersionMarker(string $jar, string $version): void {
        $metadata = json_encode([
            'version' => $version,
            'size' => filesize($jar),
            'modified' => filemtime($jar),
        ], JSON_THROW_ON_ERROR);

        if (file_put_contents($jar . '.version', $metadata, LOCK_EX) === false) {
            throw new AdministrationException('Versionsinformation konnte nicht gespeichert werden.');
        }

        chmod($jar . '.version', 0640);
    }

    private function readVersionMarker(string $jar): ?string {
        $marker = @file_get_contents($jar . '.version');
        if ($marker === false) {
            return null;
        }

        try {
            $metadata = json_decode($marker, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $version = is_array($metadata) ? ($metadata['version'] ?? null) : null;
        if (!is_string($version)
            || !preg_match('/^[0-9]+(?:\.[0-9]+)+(?:[-+][A-Za-z0-9.-]+)?$/', $version)
            || ($metadata['size'] ?? null) !== filesize($jar)
            || ($metadata['modified'] ?? null) !== filemtime($jar)) {
            return null;
        }

        return $version;
    }

    private function fileState(string $path, bool $executable = false): string {
        if (!is_file($path)) {
            return 'missing';
        }

        return !$executable || is_executable($path) ? 'available' : 'not-executable';
    }

    private function directoryState(string $path, bool $mayBeCreated = false): string {
        if (is_dir($path)) {
            return 'available';
        }

        return $mayBeCreated && is_writable(dirname($path)) ? 'creatable' : 'missing';
    }
}
