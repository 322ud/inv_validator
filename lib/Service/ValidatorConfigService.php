<?php

namespace OCA\KoSITValidator\Service;

class ValidatorConfigService {

    public function getJavaBinary(): string {
        return '/usr/bin/java';
    }

    public function getValidatorJar(): string {
        return '/opt/kosit/validator-1.6.2-standalone.jar';
    }

    public function getRepositoryPath(): string {
        return '/opt/kosit';
    }

    public function getScenarioFile(): string {
        return '/opt/kosit/scenarios.xml';
    }

    public function getTempDirectory(): string {
        return sys_get_temp_dir() . '/nextcloud-kosit-validator';
    }
}
