<?php

declare(strict_types=1);

namespace OCA\KoSITValidator\Service;

use OCP\AppFramework\Services\IAppConfig;
use OCP\IConfig;

class ValidatorConfigService
{
    private const DEFAULT_VALIDATOR_JAR = '/opt/kosit/validator-1.6.2-standalone.jar';

    private const PATH_KEYS = [
        'javaBinary' => 'java_binary',
        'validatorJar' => 'validator_jar',
        'repositoryPath' => 'repository_path',
        'scenarioFile' => 'scenario_file',
        'tempDirectory' => 'temp_directory',
    ];

    public function __construct(
        private IConfig $config,
        private IAppConfig $appConfig
    ) {
    }

    public function getJavaBinary(): string
    {
        $configured = $this->getConfiguredPath(
            self::PATH_KEYS['javaBinary'],
            'kosit_validator_java_binary',
            ''
        );

        if ($configured !== '') {
            return $configured;
        }

        return $this->findExecutable('java') ?? '/usr/bin/java';
    }

    public function getValidatorJar(): string
    {
        $configured = $this->getConfiguredPath(
            self::PATH_KEYS['validatorJar'],
            'kosit_validator_jar',
            ''
        );

        if ($configured !== '') {
            return $configured;
        }

        $jars = glob('/opt/kosit/validator-*-standalone.jar') ?: [];
        usort($jars, static function (string $left, string $right): int {
            return version_compare(
                self::versionFromFilename($right) ?? '0',
                self::versionFromFilename($left) ?? '0'
            );
        });

        return $jars[0] ?? self::DEFAULT_VALIDATOR_JAR;
    }

    public function getRepositoryPath(): string
    {
        return $this->getConfiguredPath(
            self::PATH_KEYS['repositoryPath'],
            'kosit_validator_repository',
            '/opt/kosit'
        );
    }

    public function getScenarioFile(): string
    {
        return $this->getConfiguredPath(
            self::PATH_KEYS['scenarioFile'],
            'kosit_validator_scenarios',
            dirname(__DIR__, 2) . '/config/EN16931_(CII)-szenario.xml'
        );
    }

    public function getTempDirectory(): string
    {
        return $this->getConfiguredPath(
            self::PATH_KEYS['tempDirectory'],
            'kosit_validator_temp_directory',
            sys_get_temp_dir() . '/nextcloud-kosit-validator'
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

    /** @param array<string, mixed> $paths */
    public function savePaths(array $paths): void
    {
        $labels = [
            'javaBinary' => 'Java',
            'validatorJar' => 'Validator-JAR',
            'repositoryPath' => 'Repository',
            'scenarioFile' => 'Szenario-Datei',
            'tempDirectory' => 'Temporäres Verzeichnis',
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

    private static function versionFromFilename(string $path): ?string
    {
        return preg_match(
            '/validator-([0-9]+(?:\.[0-9]+)+(?:[-+][A-Za-z0-9.-]+)?)-standalone\.jar$/',
            basename($path),
            $matches
        ) === 1 ? $matches[1] : null;
    }
}
