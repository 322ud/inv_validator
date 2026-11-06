<?php

declare(strict_types=1);

namespace OCA\KoSITValidator\Tests\Unit\Service;

use OCA\KoSITValidator\Service\SystemCheckService;
use OCA\KoSITValidator\Service\ValidatorConfigService;
use PHPUnit\Framework\TestCase;

final class SystemCheckServiceTest extends TestCase
{
    public function testChecksProcessesFilesWritesAndComponentActions(): void
    {
        $root = sys_get_temp_dir() . '/system-check-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $java = $root . '/java';
        file_put_contents($java, "#!/bin/sh\necho 'openjdk version \"17.0.1\"' >&2\n");
        chmod($java, 0700);
        file_put_contents($root . '/validator.jar', 'jar');
        file_put_contents($root . '/scenarios.xml', '<scenarios xmlns="http://www.xoev.de/de/validator/framework/1/scenarios"/>');
        mkdir($root . '/resources');
        $config = $this->createStub(ValidatorConfigService::class);
        $config->method('getJavaBinary')->willReturn($java);
        $config->method('getValidatorJar')->willReturn($root . '/validator.jar');
        $config->method('getRepositoryPath')->willReturn($root);
        $config->method('getScenarioFile')->willReturn($root . '/scenarios.xml');
        $config->method('getTempDirectory')->willReturn($root . '/missing/temp');
        $config->method('getPythonBinary')->willReturn('/missing/python');
        $status = [
            'validator' => ['installedVersion' => '1.6.1', 'installed' => true, 'latestVersion' => '1.6.2', 'updateAvailable' => true, 'updateError' => null],
            'configuration' => ['installedVersion' => null, 'installed' => false, 'latestVersion' => '2026-01-31', 'updateAvailable' => false, 'updateError' => 'offline'],
            'pdfSupport' => ['available' => false, 'pythonAvailable' => false, 'pythonVersion' => null, 'pymupdfVersion' => null, 'reason' => 'Python fehlt.'],
        ];
        try {
            $service = new SystemCheckService($config);
            $checks = array_column($service->check($status), null, 'id');
            self::assertTrue($checks['java']['ok']);
            self::assertSame('17.0.1', $checks['java']['version']);
            self::assertTrue($checks['temp']['ok']);
            self::assertDirectoryDoesNotExist($root . '/missing');
            self::assertSame('update', $checks['validator']['action']);
            self::assertTrue($checks['configuration']['ok']);
            self::assertNull($checks['configuration']['action']);
            self::assertSame('updates', $checks['configuration']['help']);
            self::assertFalse($checks['python']['ok']);
            self::assertSame([], glob($root . '/.pruefexrechnung-check-*'));

            file_put_contents($java, "#!/bin/sh\necho 'openjdk version \"1.8.0\"' >&2\n");
            file_put_contents($root . '/scenarios.xml', '<broken');
            $checks = array_column($service->check($status), null, 'id');
            self::assertFalse($checks['java']['ok']);
            self::assertFalse($checks['configuration']['ok']);
        } finally {
            unlink($java);
            unlink($root . '/validator.jar');
            unlink($root . '/scenarios.xml');
            rmdir($root . '/resources');
            rmdir($root);
        }
    }
}
