<?php

declare(strict_types=1);

namespace OCA\KoSITValidator\Service;

use OCP\IConfig;

class ValidatorConfigService {
    public function __construct(
        private IConfig $config
    ) {}

    public function getJavaBinary(): string {
        return $this->config->getSystemValueString(
            'kosit_validator_java_binary',
            '/usr/bin/java'
        );
    }

    public function getValidatorJar(): string {
        return $this->config->getSystemValueString(
            'kosit_validator_jar',
            '/opt/kosit/validator-1.6.2-standalone.jar'
        );
    }

    public function getRepositoryPath(): string {
        return $this->config->getSystemValueString(
            'kosit_validator_repository',
            '/opt/kosit'
        );
    }

    public function getScenarioFile(): string {
        return $this->config->getSystemValueString(
            'kosit_validator_scenarios',
            dirname(__DIR__, 2) . '/config/scenarios.xml'
        );
    }

    public function getTempDirectory(): string {
        return $this->config->getSystemValueString(
            'kosit_validator_temp_directory',
            sys_get_temp_dir() . '/nextcloud-kosit-validator'
        );
    }
}
