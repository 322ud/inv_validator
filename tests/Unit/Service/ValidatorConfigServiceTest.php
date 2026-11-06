<?php

declare(strict_types=1);

namespace OCA\KoSITValidator\Tests\Unit\Service;

use OCA\KoSITValidator\Service\ValidatorConfigService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValidatorConfigServiceTest extends TestCase {
    public function testAutomaticUpdatesDefaultToOffAndPersistIndependently(): void {
        $values = [];
        $app = $this->createMock(IAppConfig::class);
        $app->method('getAppValueString')->willReturnCallback(static function (string $key, string $default) use (&$values): string { return $values[$key] ?? $default; });
        $app->method('setAppValueString')->willReturnCallback(static function (string $key, string $value) use (&$values): void { $values[$key] = $value; });
        $service = new ValidatorConfigService($this->createStub(IConfig::class), $app);
        self::assertSame(['validator' => false, 'configuration' => false], $service->getAutoUpdates());
        $service->saveAutoUpdates(true, false);
        self::assertSame(['auto_update_validator' => 'yes', 'auto_update_configuration' => 'no'], $values);
        self::assertSame(['validator' => true, 'configuration' => false], $service->getAutoUpdates());
    }

    public function testEditableAppConfigurationTakesPrecedenceOverSystemConfiguration(): void {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getAppValueString')->with('java_binary', '')->willReturn('/from-app');
        $systemConfig = $this->createMock(IConfig::class);
        $systemConfig->expects(self::never())->method('getSystemValueString');

        $service = new ValidatorConfigService($systemConfig, $appConfig);

        self::assertSame('/from-app', $service->getJavaBinary());
    }

    public function testInfrastructurePathComesFromSystemConfiguration(): void {
        $systemConfig = $this->createMock(IConfig::class);
        $systemConfig->expects(self::once())
            ->method('getSystemValueString')
            ->with('kosit_validator_repository', '/opt/kosit/xrechnung/current')
            ->willReturn('/from-system');

        $service = new ValidatorConfigService($systemConfig, $this->createStub(IAppConfig::class));

        self::assertSame('/from-system', $service->getRepositoryPath());
    }

    public function testValidatorUsesOnlyTheVersionNeutralDefaultWhenUnconfigured(): void {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->expects(self::never())->method('getAppValueString');
        $systemConfig = $this->createMock(IConfig::class);
        $systemConfig->expects(self::once())
            ->method('getSystemValueString')
            ->with('kosit_validator_jar', '/opt/kosit/validator-standalone.jar')
            ->willReturn('/opt/kosit/validator-standalone.jar');

        $service = new ValidatorConfigService($systemConfig, $appConfig);

        self::assertSame('/opt/kosit/validator-standalone.jar', $service->getValidatorJar());
    }

    public function testSavePathsNormalizesAndPersistsEveryPath(): void {
        $stored = [];
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->expects(self::exactly(2))->method('setAppValueString')
            ->willReturnCallback(static function (string $key, string $value) use (&$stored): void {
                $stored[$key] = $value;
            });
        $service = new ValidatorConfigService($this->createStub(IConfig::class), $appConfig);

        $service->savePaths([
            'javaBinary' => ' /usr/bin/java/ ',
            'pythonBinary' => ' /usr/bin/python3/ ',
        ]);

        self::assertSame([
            'java_binary' => '/usr/bin/java',
            'python_binary' => '/usr/bin/python3',
        ], $stored);
    }

    public function testScenarioFileIsDerivedFromRepository(): void {
        $systemConfig = $this->createStub(IConfig::class);
        $systemConfig->method('getSystemValueString')->with(
            'kosit_validator_repository',
            '/opt/kosit/xrechnung/current'
        )->willReturn('/opt/kosit/xrechnung/current');
        $service = new ValidatorConfigService($systemConfig, $this->createStub(IAppConfig::class));

        self::assertSame('/opt/kosit/xrechnung/current/scenarios.xml', $service->getScenarioFile());
    }

    public function testAcceptsControlledValidatorSymlinkWithinManagedRoot(): void {
        $root = sys_get_temp_dir() . '/kosit-validator-root-' . bin2hex(random_bytes(8));
        mkdir($root, 0700, true);
        file_put_contents($root . '/validator-1.6.3-standalone.jar', 'validator');
        symlink('validator-1.6.3-standalone.jar', $root . '/validator-standalone.jar');
        $service = new ValidatorConfigService(
            $this->createStub(IConfig::class),
            $this->createStub(IAppConfig::class),
            $root
        );

        try {
            $service->assertValidatorJarIsSafe($root . '/validator-standalone.jar');
            self::addToAssertionCount(1);
        } finally {
            unlink($root . '/validator-standalone.jar');
            unlink($root . '/validator-1.6.3-standalone.jar');
            rmdir($root);
        }
    }

    public function testRejectsValidatorSymlinkOutsideManagedRootAtRuntime(): void {
        $root = sys_get_temp_dir() . '/kosit-validator-root-' . bin2hex(random_bytes(8));
        $outsideDirectory = sys_get_temp_dir() . '/kosit-validator-outside-' . bin2hex(random_bytes(8));
        $outside = $outsideDirectory . '/validator-1.6.3-standalone.jar';
        mkdir($root, 0700, true);
        mkdir($outsideDirectory, 0700, true);
        file_put_contents($outside, 'validator');
        symlink($outside, $root . '/validator-standalone.jar');
        $service = new ValidatorConfigService(
            $this->createStub(IConfig::class),
            $this->createStub(IAppConfig::class),
            $root
        );

        try {
            $this->expectException(\InvalidArgumentException::class);
            $service->assertValidatorJarIsSafe($root . '/validator-standalone.jar');
        } finally {
            unlink($root . '/validator-standalone.jar');
            unlink($outside);
            rmdir($outsideDirectory);
            rmdir($root);
        }
    }

    #[DataProvider('invalidPathProvider')]
    public function testRejectsInvalidPaths(mixed $path): void {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->expects(self::never())->method('setAppValueString');
        $service = new ValidatorConfigService($this->createStub(IConfig::class), $appConfig);

        $this->expectException(\InvalidArgumentException::class);
        $service->savePaths([
            'javaBinary' => $path,
            'pythonBinary' => '/usr/bin/python3',
        ]);
    }

    public static function invalidPathProvider(): iterable {
        yield 'empty' => [''];
        yield 'relative' => ['usr/bin/java'];
        yield 'parent traversal' => ['/usr/../bin/java'];
        yield 'null byte' => ["/usr/bin/java\0suffix"];
        yield 'not a string' => [42];
        yield 'too long' => ['/' . str_repeat('a', 4096)];
    }

    public function testInvalidLaterPathDoesNotPersistEarlierValues(): void {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->expects(self::never())->method('setAppValueString');
        $service = new ValidatorConfigService($this->createStub(IConfig::class), $appConfig);

        try {
            $service->savePaths([
                'javaBinary' => '/usr/bin/java',
                'pythonBinary' => 'relative',
            ]);
            self::fail('Expected an invalid path exception');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }
}
