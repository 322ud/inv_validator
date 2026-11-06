<?php

declare(strict_types=1);

namespace OCA\KoSITValidator\Service;

use OCA\KoSITValidator\Exception\AdministrationException;
use OCP\Http\Client\IClientService;

class ValidatorAdminService
{
    private const VALIDATOR_RELEASE_API = 'https://api.github.com/repos/itplr-kosit/validator/releases/latest';
    private const VALIDATOR_RELEASE_PAGE = 'https://github.com/itplr-kosit/validator/releases';
    private const CONFIGURATION_RELEASE_API = 'https://api.github.com/repos/itplr-kosit/validator-configuration-xrechnung/releases/latest';
    private const CONFIGURATION_RELEASE_PAGE = 'https://github.com/itplr-kosit/validator-configuration-xrechnung/releases';
    private const MAX_DOWNLOAD_BYTES = 150 * 1024 * 1024;
    private const MAX_EXTRACTED_BYTES = 300 * 1024 * 1024;
    private const MAX_ARCHIVE_ENTRIES = 20000;
    private const SCENARIO_NAMESPACE = 'http://www.xoev.de/de/validator/framework/1/scenarios';
    private const VALIDATOR_FILENAME_PATTERN = '/^validator-([0-9]+(?:\.[0-9]+)+(?:[-+][A-Za-z0-9.-]+)?)-standalone\.jar$/';

    private int $maxDownloadBytes;
    private PdfSupportService $pdfSupportService;
    private string $validatorRoot;

    public function __construct(
        private ValidatorConfigService $config,
        private IClientService $clientService,
        ?int $maxDownloadBytes = null,
        ?PdfSupportService $pdfSupportService = null,
        ?string $validatorRoot = null
    ) {
        $this->maxDownloadBytes = $maxDownloadBytes ?? self::MAX_DOWNLOAD_BYTES;
        $this->pdfSupportService = $pdfSupportService ?? new PdfSupportService($config);
        $this->validatorRoot = rtrim($validatorRoot ?? '/opt/kosit', '/');
    }

    /** @return array<string, mixed> */
    public function getStatus(bool $checkUpdates = true, bool $checkValidator = true, bool $checkConfiguration = true): array
    {
        $jar = $this->config->getValidatorJar();
        $repository = $this->config->getRepositoryPath();
        $installedValidatorVersion = $this->readInstalledValidatorVersion($jar);
        $installedConfiguration = $this->readInstalledConfiguration($repository);
        $validatorRelease = null;
        $configurationRelease = null;
        $validatorError = null;
        $configurationError = null;

        if ($checkUpdates && $checkValidator) {
            try {
                $validatorRelease = $this->fetchLatestValidatorRelease();
            } catch (\Throwable $e) {
                $validatorError = $e->getMessage();
            }
        }
        if ($checkUpdates && $checkConfiguration) {
            try {
                $configurationRelease = $this->fetchLatestConfigurationRelease();
            } catch (\Throwable $e) {
                $configurationError = $e->getMessage();
            }
        }

        $latestValidatorVersion = $validatorRelease['version'] ?? null;
        $latestConfigurationVersion = $configurationRelease['release'] ?? null;
        $installedConfigurationVersion = $installedConfiguration['release'] ?? null;

        return [
            'paths' => [
                ['label' => 'App-Verzeichnis', 'path' => $this->config->getAppDirectory(), 'state' => 'available'],
                ['label' => 'PHP', 'path' => $this->config->getPhpBinary(), 'state' => $this->fileState($this->config->getPhpBinary(), true)],
                ['label' => 'Java', 'key' => 'javaBinary', 'path' => $this->config->getJavaBinary(), 'state' => $this->fileState($this->config->getJavaBinary(), true)],
                ['label' => 'Python (optional)', 'key' => 'pythonBinary', 'path' => $this->config->getPythonBinary(), 'state' => $this->fileState($this->config->getPythonBinary(), true)],
                ['label' => 'Validator-JAR', 'path' => $jar, 'state' => $this->fileState($jar)],
                ['label' => 'XRechnung-Repository', 'path' => $repository, 'state' => $this->directoryState($repository)],
                ['label' => 'Szenario-Datei', 'path' => $this->config->getScenarioFile(), 'state' => $this->fileState($this->config->getScenarioFile())],
                ['label' => 'Temporäres Verzeichnis', 'path' => $this->config->getTempDirectory(), 'state' => $this->directoryState($this->config->getTempDirectory(), true)],
            ],
            'validator' => [
                'installed' => $installedValidatorVersion !== null,
                'installedVersion' => $installedValidatorVersion,
                'latestVersion' => $latestValidatorVersion,
                'updateAvailable' => $installedValidatorVersion !== null
                    && $latestValidatorVersion !== null
                    && version_compare($latestValidatorVersion, $installedValidatorVersion, '>'),
                'releaseUrl' => self::VALIDATOR_RELEASE_PAGE,
                'updateError' => $validatorError,
            ],
            'configuration' => [
                'installed' => is_dir($repository)
                    && is_file($repository . '/scenarios.xml')
                    && is_dir($repository . '/resources'),
                'installedVersion' => $installedConfigurationVersion,
                'installedXRechnungVersion' => $installedConfiguration['xrechnung'] ?? null,
                'latestVersion' => $latestConfigurationVersion,
                'latestXRechnungVersion' => $configurationRelease['xrechnung'] ?? null,
                'updateAvailable' => $installedConfigurationVersion !== null
                    && $latestConfigurationVersion !== null
                    && strcmp($latestConfigurationVersion, $installedConfigurationVersion) > 0,
                'releaseUrl' => self::CONFIGURATION_RELEASE_PAGE,
                'updateError' => $configurationError,
            ],
            'pdfSupport' => $this->pdfSupportService->getStatus(),
        ];
    }

    /** @return array<string, mixed> */
    public function getAdminStatus(): array
    {
        $status = $this->getStatus(true);
        $status['autoUpdates'] = $this->config->getAutoUpdates();
        $status['checks'] = (new SystemCheckService($this->config))->check($status);
        $status['managedComponents'] = (new ComponentRemovalService($this->config))->pending();
        return $status;
    }

    /** @return array<string, mixed> */
    public function updateOnStart(): array
    {
        $settings = $this->config->getAutoUpdates();
        $status = $this->getStatus(
            $settings['validator'] || $settings['configuration'],
            $settings['validator'],
            $settings['configuration']
        );
        $validator = $settings['validator'] && $status['validator']['updateAvailable'];
        $configuration = $settings['configuration'] && $status['configuration']['updateAvailable'];
        return $validator || $configuration ? $this->installLatest($validator, $configuration) : $status;
    }

    /** @return array<string, bool|string|null> */
    public function getPdfSupportStatus(): array
    {
        return $this->pdfSupportService->getStatus();
    }

    /** @return array<string, mixed> */
    public function installLatest(bool $validator = true, bool $configuration = true): array
    {
        if ($configuration && !class_exists(\ZipArchive::class)) {
            throw new AdministrationException('Die PHP-Erweiterung ZipArchive wird für die Installation benötigt.');
        }

        if (!$validator && !$configuration) {
            return $this->getStatus(false);
        }
        $validatorRelease = $validator ? $this->fetchLatestValidatorRelease() : null;
        $configurationRelease = $configuration ? $this->fetchLatestConfigurationRelease() : null;
        if ($validatorRelease !== null) {
            $this->assertCompatibleValidator($validatorRelease['version']);
        }

        $jar = $this->config->getValidatorJar();
        $repository = $this->config->getRepositoryPath();
        $jarDirectory = dirname($jar);
        $repositoryParent = dirname($repository);
        $releaseDirectory = $repositoryParent . '/releases/' . ($configurationRelease['release'] ?? '');

        $this->ensureDirectory($jarDirectory);
        if ($validator) {
            $this->assertValidatorLinkLocation($jar);
            $this->assertExistingValidatorLinkIsManaged($jar);
        }
        if ($configuration) {
            $this->ensureDirectory($repositoryParent);
            $this->ensureDirectory(dirname($releaseDirectory));
        }

        if ($configuration && file_exists($repository) && !is_link($repository)) {
            throw new AdministrationException(
                'Das konfigurierte XRechnung-Repository existiert und wird nicht von der App verwaltet: ' . $repository
            );
        }

        $lockPath = $jarDirectory . '/.kosit-install.lock';
        $lock = fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new AdministrationException('Eine Installation läuft bereits.');
        }

        $registry = $this->config->getInstallationRegistry();
        $temporaryJar = null;
        $temporaryValidatorLink = null;
        $temporaryArchive = null;
        $stagingDirectory = null;
        $temporaryLink = null;

        try {
            $installedValidatorVersion = $this->readInstalledValidatorVersion($jar);
            $installValidator = $validatorRelease !== null && ($installedValidatorVersion === null
                || version_compare($validatorRelease['version'], $installedValidatorVersion, '>'));
            $installedConfiguration = $this->readInstalledConfiguration($repository);
            $installConfiguration = $configurationRelease !== null && (($installedConfiguration['release'] ?? null) === null
                || strcmp($configurationRelease['release'], (string)$installedConfiguration['release']) > 0);

            if ($installValidator) {
                $jarBody = $this->download($validatorRelease['asset']['url'], 'Validator');
                $this->verifyZipContainer($jarBody, $validatorRelease['asset']['digest'], 'JAR');
                $temporaryJar = $this->writeTemporaryFile($jarDirectory, '.validator-', $jarBody, 0640);
                $validatorFilename = 'validator-' . $validatorRelease['version'] . '-standalone.jar';
                $validatorReleasePath = $jarDirectory . '/' . $validatorFilename;
                if (file_exists($validatorReleasePath) || is_link($validatorReleasePath)) {
                    if (
                        !is_file($validatorReleasePath)
                        || is_link($validatorReleasePath)
                        || !hash_equals(hash('sha256', $jarBody), (string)hash_file('sha256', $validatorReleasePath))
                    ) {
                        throw new AdministrationException(
                            'Das Ziel für das Validator-Release ist bereits belegt: ' . $validatorReleasePath
                        );
                    }
                    $registry->prepare('validator', $validatorRelease['version'], $validatorReleasePath, null);
                    unlink($temporaryJar);
                } else {
                    $registry->prepare('validator', $validatorRelease['version'], $validatorReleasePath, ['' => hash('sha256', $jarBody)]);
                    if (!rename($temporaryJar, $validatorReleasePath)) {
                        throw new AdministrationException('Validator-Release konnte nicht bereitgestellt werden.');
                    }
                }
                $temporaryJar = null;

                $temporaryValidatorLink = $jarDirectory . '/.validator-current-' . bin2hex(random_bytes(8));
                if (!symlink($validatorFilename, $temporaryValidatorLink)) {
                    throw new AdministrationException('Validator-Symlink konnte nicht vorbereitet werden.');
                }
                if ($this->readInstalledValidatorVersion($temporaryValidatorLink) !== $validatorRelease['version']) {
                    throw new AdministrationException('Validator-Symlink verweist nicht auf ein kontrolliertes Release.');
                }
            }

            if ($installConfiguration) {
                if (is_dir($releaseDirectory)) {
                    $existing = $this->readInstalledConfiguration($releaseDirectory);
                    if (($existing['release'] ?? null) !== $configurationRelease['release']) {
                        throw new AdministrationException(
                            'Das Zielverzeichnis des XRechnung-Releases ist bereits belegt: ' . $releaseDirectory
                        );
                    }
                    $registry->prepare('configuration', $configurationRelease['release'], $releaseDirectory, null);
                } else {
                    $archiveBody = $this->download($configurationRelease['asset']['url'], 'XRechnung-Konfiguration');
                    $this->verifyZipContainer($archiveBody, $configurationRelease['asset']['digest'], 'ZIP');
                    $temporaryArchive = $this->writeTemporaryFile(
                        dirname($releaseDirectory),
                        '.xrechnung-',
                        $archiveBody,
                        0640
                    );
                    $stagingDirectory = dirname($releaseDirectory) . '/.xrechnung-' . bin2hex(random_bytes(12));
                    $this->extractConfigurationArchive($temporaryArchive, $stagingDirectory);
                    $this->validateConfigurationDirectory($stagingDirectory);
                    $this->writeConfigurationMarker($stagingDirectory, $configurationRelease);
                    $registry->prepare('configuration', $configurationRelease['release'], $releaseDirectory, $registry->manifest($stagingDirectory));
                    if (!rename($stagingDirectory, $releaseDirectory)) {
                        throw new AdministrationException('Das XRechnung-Release konnte nicht bereitgestellt werden.');
                    }
                    $stagingDirectory = null;
                }

                $temporaryLink = $repositoryParent . '/.current-' . bin2hex(random_bytes(8));
                if (!symlink($releaseDirectory, $temporaryLink)) {
                    throw new AdministrationException('Das XRechnung-Release konnte nicht aktiviert werden.');
                }
            }

            if ($installValidator) {
                $registry->prepareLink('validator', $jar, $validatorReleasePath);
                if (!rename($temporaryValidatorLink, $jar)) {
                    throw new AdministrationException('Validator-JAR konnte nicht aktiviert werden.');
                }
                $temporaryValidatorLink = null;
                $registry->activated('validator', $jar, $validatorReleasePath);
            }

            if ($installConfiguration) {
                $registry->prepareLink('configuration', $repository, $releaseDirectory);
                if (!rename($temporaryLink, $repository)) {
                    throw new AdministrationException('Das XRechnung-Release konnte nicht aktiviert werden.');
                }
                $temporaryLink = null;
                $registry->activated('configuration', $repository, $releaseDirectory);
            }
        } finally {
            foreach ([$temporaryJar, $temporaryValidatorLink, $temporaryArchive, $temporaryLink] as $temporary) {
                if (is_string($temporary) && (is_file($temporary) || is_link($temporary))) {
                    unlink($temporary);
                }
            }
            if ($stagingDirectory !== null && is_dir($stagingDirectory)) {
                $this->removeDirectory($stagingDirectory);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $status = $this->getStatus(false);
        if ($validatorRelease !== null) {
            $status['validator']['latestVersion'] = $validatorRelease['version'];
            $status['validator']['updateAvailable'] = false;
        }
        if ($configurationRelease !== null) {
            $status['configuration']['latestVersion'] = $configurationRelease['release'];
            $status['configuration']['latestXRechnungVersion'] = $configurationRelease['xrechnung'];
            $status['configuration']['updateAvailable'] = false;
        }
        return $status;
    }

    /** @return array{version: string, asset: array{url: string, digest: ?string}} */
    private function fetchLatestValidatorRelease(): array
    {
        $data = $this->fetchReleaseData(self::VALIDATOR_RELEASE_API);
        $version = ltrim($data['tag_name'], 'vV');
        if (!preg_match('/^[0-9]+(?:\.[0-9]+)+(?:[-+][A-Za-z0-9.-]+)?$/', $version)) {
            throw new AdministrationException('GitHub lieferte keine gültige Validator-Version.');
        }

        $expectedName = 'validator-' . $version . '-standalone.jar';
        return [
            'version' => $version,
            'asset' => $this->findReleaseAsset($data, $expectedName, 'validator'),
        ];
    }

    /** @return array{release: string, xrechnung: string, asset: array{url: string, digest: ?string}} */
    private function fetchLatestConfigurationRelease(): array
    {
        $data = $this->fetchReleaseData(self::CONFIGURATION_RELEASE_API);
        if (preg_match('/^(?:v|release-)?([0-9]{4}-[0-9]{2}-[0-9]{2})$/', $data['tag_name'], $tagMatches) !== 1) {
            throw new AdministrationException('GitHub lieferte keine gültige XRechnung-Konfigurationsversion.');
        }
        $release = $tagMatches[1];
        $xrechnungVersion = null;
        $candidate = null;
        foreach ($data['assets'] as $asset) {
            if (!is_array($asset) || !is_string($asset['name'] ?? null)) {
                continue;
            }
            if (
                preg_match(
                    '/^xrechnung-([0-9]+\.[0-9]+\.[0-9]+)-validator-configuration-([0-9]{4}-[0-9]{2}-[0-9]{2})\.zip$/',
                    $asset['name'],
                    $assetMatches
                ) !== 1 || $assetMatches[2] !== $release
            ) {
                continue;
            }
            if ($candidate !== null) {
                throw new AdministrationException('GitHub lieferte mehrere passende XRechnung-Konfigurationspakete.');
            }
            $candidate = $this->releaseAsset($asset, $asset['name'], 'validator-configuration-xrechnung');
            $xrechnungVersion = $assetMatches[1];
        }
        if ($candidate === null || $xrechnungVersion === null) {
            throw new AdministrationException('XRechnung-Konfigurationspaket im neuesten GitHub-Release nicht gefunden.');
        }
        return ['release' => $release, 'xrechnung' => $xrechnungVersion, 'asset' => $candidate];
    }

    /** @return array{tag_name: string, assets: list<mixed>} */
    private function fetchReleaseData(string $url): array
    {
        $response = $this->clientService->newClient()->get($url, [
            'connect_timeout' => 10,
            'timeout' => 20,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'Nextcloud-KoSIT-Validator',
                'X-GitHub-Api-Version' => '2022-11-28',
            ],
        ]);
        if ($response->getStatusCode() !== 200) {
            throw new AdministrationException('GitHub-Release-Prüfung fehlgeschlagen (HTTP ' . $response->getStatusCode() . ').');
        }
        try {
            $data = json_decode((string)$response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new AdministrationException('GitHub lieferte ungültige Release-Daten.', 0, $e);
        }
        if (!is_array($data) || !is_string($data['tag_name'] ?? null) || !is_array($data['assets'] ?? null)) {
            throw new AdministrationException('GitHub lieferte ungültige Release-Daten.');
        }
        return ['tag_name' => $data['tag_name'], 'assets' => array_values($data['assets'])];
    }

    /** @param array{tag_name: string, assets: list<mixed>} $data
     *  @return array{url: string, digest: ?string}
     */
    private function findReleaseAsset(array $data, string $expectedName, string $repository): array
    {
        foreach ($data['assets'] as $asset) {
            if (is_array($asset) && ($asset['name'] ?? null) === $expectedName) {
                return $this->releaseAsset($asset, $expectedName, $repository);
            }
        }
        throw new AdministrationException('Standalone-JAR im neuesten GitHub-Release nicht gefunden.');
    }

    /** @param array<string, mixed> $asset
     *  @return array{url: string, digest: ?string}
     */
    private function releaseAsset(array $asset, string $expectedName, string $repository): array
    {
        $url = $asset['browser_download_url'] ?? null;
        $digest = $asset['digest'] ?? null;
        if (!is_string($url) || ($digest !== null && !is_string($digest))) {
            throw new AdministrationException('GitHub lieferte ungültige Release-Daten.');
        }
        $parts = parse_url($url);
        if (
            !is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? null) !== 'github.com'
            || isset($parts['port'], $parts['user'], $parts['pass'])
            || !isset($parts['path'])
            || !str_starts_with($parts['path'], '/itplr-kosit/' . $repository . '/releases/download/')
            || basename($parts['path']) !== $expectedName
        ) {
            throw new AdministrationException('GitHub lieferte eine unerwartete Download-Adresse.');
        }
        return ['url' => $url, 'digest' => $digest];
    }

    private function download(string $url, string $label): string
    {
        $response = $this->clientService->newClient()->get($url, [
            'connect_timeout' => 15,
            'timeout' => 120,
            'headers' => ['User-Agent' => 'Nextcloud-KoSIT-Validator'],
        ]);
        if ($response->getStatusCode() !== 200) {
            throw new AdministrationException($label . '-Download fehlgeschlagen (HTTP ' . $response->getStatusCode() . ').');
        }
        $body = (string)$response->getBody();
        if ($body === '' || strlen($body) > $this->maxDownloadBytes) {
            throw new AdministrationException($label . '-Download ist leer oder überschreitet 150 MB.');
        }
        return $body;
    }

    private function verifyZipContainer(string $body, ?string $digest, string $type): void
    {
        if (!str_starts_with($body, "PK\x03\x04")) {
            throw new AdministrationException('Der Download ist keine gültige ' . $type . '-Datei.');
        }
        if ($digest !== null) {
            if (preg_match('/^sha256:([a-f0-9]{64})$/i', $digest, $matches) !== 1) {
                throw new AdministrationException('Der Download enthält keine gültige SHA-256-Prüfsumme.');
            }
            if (!hash_equals(strtolower($matches[1]), hash('sha256', $body))) {
                throw new AdministrationException('SHA-256-Prüfung des Downloads fehlgeschlagen.');
            }
        }
    }

    private function extractConfigurationArchive(string $archive, string $target): void
    {
        if (!mkdir($target, 0750, true) && !is_dir($target)) {
            throw new AdministrationException('Temporäres XRechnung-Verzeichnis konnte nicht angelegt werden.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new AdministrationException('XRechnung-Konfigurationspaket konnte nicht geöffnet werden.');
        }
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ARCHIVE_ENTRIES) {
                throw new AdministrationException('XRechnung-Konfigurationspaket enthält zu viele oder keine Dateien.');
            }
            $totalSize = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if ($stat === false) {
                    throw new AdministrationException('XRechnung-Konfigurationspaket enthält einen ungültigen Eintrag.');
                }
                $name = str_replace('\\', '/', $stat['name']);
                if (
                    $name === '' || str_starts_with($name, '/') || str_contains($name, "\0")
                    || in_array('..', explode('/', $name), true)
                ) {
                    throw new AdministrationException('XRechnung-Konfigurationspaket enthält einen unsicheren Pfad.');
                }
                $attributes = 0;
                if (
                    $zip->getExternalAttributesIndex($index, $operationsSystem, $attributes)
                    && (($attributes >> 16) & 0170000) === 0120000
                ) {
                    throw new AdministrationException('Symbolische Links im XRechnung-Konfigurationspaket sind nicht erlaubt.');
                }
                $size = $stat['size'];
                $totalSize += $size;
                if ($totalSize > self::MAX_EXTRACTED_BYTES) {
                    throw new AdministrationException('XRechnung-Konfigurationspaket ist entpackt zu groß.');
                }
                $destination = $target . '/' . rtrim($name, '/');
                if (str_ends_with($name, '/')) {
                    $this->ensureDirectory($destination);
                    continue;
                }
                $this->ensureDirectory(dirname($destination));
                $input = $zip->getStream($stat['name']);
                $output = fopen($destination, 'xb');
                if ($input === false || $output === false) {
                    if (is_resource($input)) {
                        fclose($input);
                    }
                    if (is_resource($output)) {
                        fclose($output);
                    }
                    throw new AdministrationException('XRechnung-Konfigurationspaket konnte nicht entpackt werden.');
                }
                $written = stream_copy_to_stream($input, $output, $size + 1);
                fclose($input);
                fclose($output);
                if ($written !== $size) {
                    throw new AdministrationException('XRechnung-Konfigurationspaket wurde unvollständig entpackt.');
                }
                chmod($destination, 0640);
            }
        } finally {
            $zip->close();
        }
    }

    private function validateConfigurationDirectory(string $directory): void
    {
        $scenario = $directory . '/scenarios.xml';
        if (!is_file($scenario) || !is_dir($directory . '/resources')) {
            throw new AdministrationException('XRechnung-Konfigurationspaket enthält nicht scenarios.xml und resources/.');
        }
        $scenarioContent = file_get_contents($scenario);
        if ($scenarioContent === false || preg_match('/<!DOCTYPE\s/i', $scenarioContent) === 1) {
            throw new AdministrationException('XRechnung-Konfigurationspaket enthält eine unsichere Szenariodatei.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            $loaded = $document->loadXML($scenarioContent, LIBXML_NONET | LIBXML_NOBLANKS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $document->documentElement;
        if (!$loaded || $root === null || $root->localName !== 'scenarios' || $root->namespaceURI !== self::SCENARIO_NAMESPACE) {
            throw new AdministrationException('XRechnung-Konfigurationspaket enthält keine gültige KoSIT-Szenariodatei.');
        }
    }

    /** @param array{release: string, xrechnung: string, asset: array{url: string, digest: ?string}} $release */
    private function writeConfigurationMarker(string $directory, array $release): void
    {
        $metadata = json_encode([
            'release' => $release['release'],
            'xrechnung' => $release['xrechnung'],
            'scenarioSha256' => hash_file('sha256', $directory . '/scenarios.xml'),
        ], JSON_THROW_ON_ERROR);
        if (file_put_contents($directory . '/.kosit-xrechnung-version', $metadata, LOCK_EX) === false) {
            throw new AdministrationException('Versionsinformation der XRechnung-Konfiguration konnte nicht gespeichert werden.');
        }
        chmod($directory . '/.kosit-xrechnung-version', 0640);
    }

    /** @return array{release: string, xrechnung: string}|null */
    private function readInstalledConfiguration(string $repository): ?array
    {
        if (!is_file($repository . '/scenarios.xml') || !is_dir($repository . '/resources')) {
            return null;
        }
        $marker = @file_get_contents($repository . '/.kosit-xrechnung-version');
        if ($marker === false) {
            return null;
        }
        try {
            $metadata = json_decode($marker, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (
            !is_array($metadata)
            || !is_string($metadata['release'] ?? null)
            || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $metadata['release']) !== 1
            || !is_string($metadata['xrechnung'] ?? null)
            || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $metadata['xrechnung']) !== 1
            || !is_string($metadata['scenarioSha256'] ?? null)
            || !hash_equals($metadata['scenarioSha256'], (string)hash_file('sha256', $repository . '/scenarios.xml'))
        ) {
            return null;
        }
        return ['release' => $metadata['release'], 'xrechnung' => $metadata['xrechnung']];
    }

    private function assertCompatibleValidator(string $version): void
    {
        if (version_compare($version, '1.6.0', '<') || version_compare($version, '2.0.0', '>=')) {
            throw new AdministrationException('Die App unterstützt derzeit nur KoSIT-Validator 1.6.x.');
        }
    }

    private function writeTemporaryFile(string $directory, string $prefix, string $body, int $mode): string
    {
        $path = tempnam($directory, $prefix);
        if ($path === false || file_put_contents($path, $body, LOCK_EX) !== strlen($body)) {
            throw new AdministrationException('Temporäre Installationsdatei konnte nicht vollständig geschrieben werden.');
        }
        chmod($path, $mode);
        return $path;
    }

    private function assertValidatorLinkLocation(string $jar): void
    {
        if (basename($jar) !== 'validator-standalone.jar' || !$this->isWithinValidatorRoot(dirname($jar))) {
            throw new AdministrationException(
                'Der verwaltete Validator-Symlink muss innerhalb von ' . $this->validatorRoot
                . ' liegen und validator-standalone.jar heißen.'
            );
        }
    }

    private function assertExistingValidatorLinkIsManaged(string $jar): void
    {
        if (is_link($jar)) {
            if ($this->readInstalledValidatorVersion($jar) === null) {
                throw new AdministrationException(
                    'Der konfigurierte Validator-Symlink verweist nicht auf ein kontrolliertes Release innerhalb von '
                    . $this->validatorRoot . ': ' . $jar
                );
            }
            return;
        }

        if (file_exists($jar)) {
            throw new AdministrationException(
                'Der konfigurierte Validator-Pfad existiert und ist kein von der App verwalteter Symlink: ' . $jar
            );
        }
    }

    private function readInstalledValidatorVersion(string $jar): ?string
    {
        if (!is_link($jar) || !$this->isWithinValidatorRoot(dirname($jar))) {
            return null;
        }

        $linkTarget = readlink($jar);
        if ($linkTarget === false) {
            return null;
        }

        $targetPath = str_starts_with($linkTarget, '/')
            ? $linkTarget
            : dirname($jar) . '/' . $linkTarget;
        if (is_link($targetPath)) {
            return null;
        }

        $resolvedTarget = realpath($targetPath);
        if (
            $resolvedTarget === false
            || !is_file($resolvedTarget)
            || !$this->isWithinValidatorRoot($resolvedTarget)
            || preg_match(self::VALIDATOR_FILENAME_PATTERN, basename($resolvedTarget), $matches) !== 1
        ) {
            return null;
        }

        return $matches[1];
    }

    private function isWithinValidatorRoot(string $path): bool
    {
        $root = realpath($this->validatorRoot);
        $resolved = realpath($path);
        if ($root === false || $resolved === false) {
            return false;
        }

        return $resolved === $root || str_starts_with($resolved, $root . DIRECTORY_SEPARATOR);
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new AdministrationException('Verzeichnis konnte nicht angelegt werden: ' . $directory);
        }
        if (!is_writable($directory)) {
            throw new AdministrationException('Keine Schreibberechtigung für das Verzeichnis: ' . $directory);
        }
    }

    private function removeDirectory(string $directory): void
    {
        $iterator = new \FilesystemIterator(
            $directory,
            \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO
        );
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            $path = $item->getPathname();
            if ($item->isLink() || $item->isFile()) {
                unlink($path);
            } elseif ($item->isDir()) {
                $this->removeDirectory($path);
            }
        }
        rmdir($directory);
    }

    private function fileState(string $path, bool $executable = false): string
    {
        if (!is_file($path)) {
            return 'missing';
        }
        return !$executable || is_executable($path) ? 'available' : 'not-executable';
    }

    private function directoryState(string $path, bool $mayBeCreated = false): string
    {
        if (is_dir($path)) {
            return 'available';
        }
        return $mayBeCreated && is_writable(dirname($path)) ? 'creatable' : 'missing';
    }
}
