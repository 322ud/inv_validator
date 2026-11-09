<?php

declare(strict_types=1);

namespace OCA\INValidator\Service;

use OCP\AppFramework\Services\IAppConfig;
use OCP\IConfig;

class ValidatorConfigService
{
    private const DEFAULT_VALIDATOR_ROOT = '/opt/kosit';
    private const DEFAULT_VALIDATOR_JAR = '/opt/kosit/validator-standalone.jar';
    private const VALIDATOR_FILENAME_PATTERN = '/^validator-[0-9]+(?:\.[0-9]+)+(?:[-+][A-Za-z0-9.-]+)?-standalone\.jar$/';

    private const PATH_KEYS = [
        'javaBinary' => 'java_binary',
        'pythonBinary' => 'python_binary',
    ];

    private string $validatorRoot;

    public function __construct(
        private IConfig $config,
        private IAppConfig $appConfig,
        ?string $validatorRoot = null
    ) {
        $this->validatorRoot = rtrim($validatorRoot ?? self::DEFAULT_VALIDATOR_ROOT, '/');
    }

    public function getInstallationRegistry(): ComponentInstallationRegistry
    {
        return new ComponentInstallationRegistry($this->appConfig);
    }

    public function getJavaBinary(): string
    {
        $configured = $this->getConfiguredPath(
            self::PATH_KEYS['javaBinary'],
            'inv_validator_java_binary',
            ''
        );

        if ($configured !== '') {
            return $configured;
        }

        return $this->findExecutable('java') ?? '/usr/bin/java';
    }

    public function getValidatorJar(): string
    {
        return $this->config->getSystemValueString(
            'inv_validator_jar',
            self::DEFAULT_VALIDATOR_JAR
        );
    }

    public function assertValidatorJarIsSafe(string $jar): void
    {
        if (!is_link($jar)) {
            return;
        }

        $linkTarget = readlink($jar);
        if ($linkTarget === false) {
            throw new \InvalidArgumentException('Validator-Symlink konnte nicht gelesen werden.');
        }

        $targetPath = str_starts_with($linkTarget, '/')
            ? $linkTarget
            : dirname($jar) . '/' . $linkTarget;
        $root = realpath($this->validatorRoot);
        $linkDirectory = realpath(dirname($jar));
        $target = realpath($targetPath);
        if (
            basename($jar) !== 'validator-standalone.jar'
            || $root === false
            || $linkDirectory === false
            || $target === false
            || is_link($targetPath)
            || !is_file($target)
            || !$this->pathIsWithin($linkDirectory, $root)
            || !$this->pathIsWithin($target, $root)
            || preg_match(self::VALIDATOR_FILENAME_PATTERN, basename($target)) !== 1
        ) {
            throw new \InvalidArgumentException(
                'Validator-Symlink verweist nicht auf ein kontrolliertes Release innerhalb von '
                . $this->validatorRoot . '.'
            );
        }
    }

    public function getPythonBinary(): string
    {
        $configured = $this->getConfiguredPath(
            self::PATH_KEYS['pythonBinary'],
            'inv_validator_python_binary',
            ''
        );

        if ($configured !== '') {
            return $configured;
        }

        return $this->findExecutable('python3') ?? '/usr/bin/python3';
    }

    public function getPdfExtractorScript(): string
    {
        return $this->getAppDirectory() . '/scripts/extract_embedded_xml.py';
    }

    public function getRepositoryPath(): string
    {
        return $this->config->getSystemValueString(
            'inv_validator_repository',
            '/opt/kosit/xrechnung/current'
        );
    }

    public function getScenarioFile(): string
    {
        return $this->getRepositoryPath() . '/scenarios.xml';
    }

    public function getTempDirectory(): string
    {
        return $this->config->getSystemValueString(
            'inv_validator_temp_directory',
            sys_get_temp_dir() . '/nextcloud-inv-validator'
        );
    }

    public function getPhpBinary(): string
    {
        return PHP_BINARY;
    }

    public function getAppDirectory(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return array{validator: bool, configuration: bool} */
    public function getAutoUpdates(): array
    {
        return [
            'validator' => $this->appConfig->getAppValueString('auto_update_validator', 'no') === 'yes',
            'configuration' => $this->appConfig->getAppValueString('auto_update_configuration', 'no') === 'yes',
        ];
    }

    public function saveAutoUpdates(bool $validator, bool $configuration): void
    {
        $this->appConfig->setAppValueString('auto_update_validator', $validator ? 'yes' : 'no');
        $this->appConfig->setAppValueString('auto_update_configuration', $configuration ? 'yes' : 'no');
    }

    /** @param array<string, mixed> $paths */
    public function savePaths(array $paths): void
    {
        $labels = [
            'javaBinary' => 'Java',
            'pythonBinary' => 'Python',
        ];

        $normalized = [];
        foreach (self::PATH_KEYS as $inputKey => $configKey) {
            $normalized[$configKey] = $this->normalizeAbsolutePath(
                $labels[$inputKey],
                $paths[$inputKey] ?? null
            );
        }

        foreach ($normalized as $configKey => $value) {
            $this->appConfig->setAppValueString($configKey, $value);
        }
    }

    private function getConfiguredPath(
        string $appKey,
        string $systemKey,
        string $default
    ): string {
        $stored = $this->appConfig->getAppValueString($appKey, '');

        if ($stored !== '') {
            return $stored;
        }

        return $this->config->getSystemValueString($systemKey, $default);
    }

    private function normalizeAbsolutePath(string $label, mixed $value): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException($label . ': ungültiger Pfad.');
        }

        $value = trim($value);
        if ($value === '' || !str_starts_with($value, '/')) {
            throw new \InvalidArgumentException($label . ': ein absoluter Pfad ist erforderlich.');
        }

        if (str_contains($value, "\0") || strlen($value) > 4096) {
            throw new \InvalidArgumentException($label . ': ungültiger Pfad.');
        }

        $segments = explode('/', $value);
        if (in_array('..', $segments, true)) {
            throw new \InvalidArgumentException($label . ': „..“ ist im Pfad nicht erlaubt.');
        }

        return $value === '/' ? $value : rtrim($value, '/');
    }

    private function findExecutable(string $name): ?string
    {
        $path = getenv('PATH');

        if (!is_string($path) || $path === '') {
            return null;
        }

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            if ($directory === '') {
                continue;
            }

            $candidate = rtrim($directory, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR . $name;

            if (is_file($candidate) && is_executable($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
        }

        return null;
    }

    private function pathIsWithin(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }
}
