<?php

declare(strict_types=1);

namespace OCA\KoSITValidator\Tests\Unit\Service;

use OCA\KoSITValidator\Service\ValidatorConfigService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValidatorConfigServiceTest extends TestCase {
    public function testAppConfigurationTakesPrecedenceOverSystemConfiguration(): void {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getAppValueString')->with('repository_path', '')->willReturn('/from-app');
        $systemConfig = $this->createMock(IConfig::class);
        $systemConfig->expects(self::never())->method('getSystemValueString');

        $service = new ValidatorConfigService($systemConfig, $appConfig);

        self::assertSame('/from-app', $service->getRepositoryPath());
    }

    public function testEmptyAppConfigurationFallsBackToSystemConfiguration(): void {
        $appConfig = $this->createStub(IAppConfig::class);
        $appConfig->method('getAppValueString')->willReturn('');
        $systemConfig = $this->createMock(IConfig::class);
        $systemConfig->expects(self::once())
            ->method('getSystemValueString')
            ->with('kosit_validator_repository', '/opt/kosit/xrechnung/current')
            ->willReturn('/from-system');

        $service = new ValidatorConfigService($systemConfig, $appConfig);

        self::assertSame('/from-system', $service->getRepositoryPath());
    }

    public function testSavePathsNormalizesAndPersistsEveryPath(): void {
        $stored = [];
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->expects(self::exactly(5))->method('setAppValueString')
            ->willReturnCallback(static function (string $key, string $value) use (&$stored): void {
                $stored[$key] = $value;
            });
        $service = new ValidatorConfigService($this->createStub(IConfig::class), $appConfig);

        $service->savePaths([
            'javaBinary' => ' /usr/bin/java/ ',
            'pythonBinary' => ' /usr/bin/python3/ ',
            'validatorJar' => '/opt/kosit/validator.jar/',
            'repositoryPath' => '/opt/kosit/',
            'tempDirectory' => '/',
        ]);

        self::assertSame([
            'java_binary' => '/usr/bin/java',
            'python_binary' => '/usr/bin/python3',
            'validator_jar' => '/opt/kosit/validator.jar',
            'repository_path' => '/opt/kosit',
            'temp_directory' => '/',
        ], $stored);
    }

    public function testScenarioFileIsDerivedFromRepository(): void {
        $appConfig = $this->createStub(IAppConfig::class);
        $appConfig->method('getAppValueString')->with('repository_path', '')->willReturn('/opt/kosit/xrechnung/current');
        $service = new ValidatorConfigService($this->createStub(IConfig::class), $appConfig);

        self::assertSame('/opt/kosit/xrechnung/current/scenarios.xml', $service->getScenarioFile());
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
            'validatorJar' => '/validator.jar',
            'repositoryPath' => '/repository',
            'tempDirectory' => '/tmp/kosit',
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
                'pythonBinary' => '/usr/bin/python3',
                'validatorJar' => '/validator.jar',
                'repositoryPath' => '/repository',
                'tempDirectory' => 'relative',
            ]);
            self::fail('Expected an invalid path exception');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }
}
