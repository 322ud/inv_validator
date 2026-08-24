<?php

declare(strict_types=1);

namespace OCA\KoSITValidator\Tests\Unit\Service;

use OCA\KoSITValidator\Exception\AdministrationException;
use OCA\KoSITValidator\Service\ValidatorAdminService;
use OCA\KoSITValidator\Service\ValidatorConfigService;
use OCA\KoSITValidator\Tests\Support\FakeHttpResponse;
use OCA\KoSITValidator\Tests\Support\QueuedHttpClientService;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

final class ValidatorAdminInstallationTest extends TestCase {
    private const VALIDATOR_API = 'https://api.github.com/repos/itplr-kosit/validator/releases/latest';
    private const CONFIGURATION_API = 'https://api.github.com/repos/itplr-kosit/validator-configuration-xrechnung/releases/latest';
    private const VALIDATOR_URL = 'https://github.com/itplr-kosit/validator/releases/download/v1.6.2/validator-1.6.2-standalone.jar';
    private const CONFIGURATION_URL = 'https://github.com/itplr-kosit/validator-configuration-xrechnung/releases/download/v2026-01-31/xrechnung-3.0.2-validator-configuration-2026-01-31.zip';

    private string $temporaryDirectory;
    private string $jarPath;
    private string $oldJarPath;
    private string $repositoryPath;
    private ValidatorConfigService $config;

    protected function setUp(): void {
        $this->temporaryDirectory = sys_get_temp_dir() . '/kosit-install-test-' . bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory, 0700, true);
        $this->jarPath = $this->temporaryDirectory . '/validator-standalone.jar';
        $this->oldJarPath = $this->temporaryDirectory . '/validator-1.6.1-standalone.jar';
        $this->repositoryPath = $this->temporaryDirectory . '/xrechnung/current';
        file_put_contents($this->oldJarPath, 'old validator');
        symlink(basename($this->oldJarPath), $this->jarPath);

        $this->config = $this->createStub(ValidatorConfigService::class);
        $this->config->method('getValidatorJar')->willReturn($this->jarPath);
        $this->config->method('getAppDirectory')->willReturn($this->temporaryDirectory);
        $this->config->method('getPhpBinary')->willReturn(PHP_BINARY);
        $this->config->method('getJavaBinary')->willReturn(PHP_BINARY);
        $this->config->method('getPythonBinary')->willReturn(PHP_BINARY);
        $this->config->method('getRepositoryPath')->willReturn($this->repositoryPath);
        $this->config->method('getScenarioFile')->willReturn($this->repositoryPath . '/scenarios.xml');
        $this->config->method('getTempDirectory')->willReturn($this->temporaryDirectory);
    }

    protected function tearDown(): void {
        $this->removeDirectory($this->temporaryDirectory);
    }

    public function testStatusReportsBothAvailableUpdates(): void {
        $this->createInstalledConfiguration('2025-07-10', '3.0.2');
        [$service, $client] = $this->serviceWith([
            new FakeHttpResponse(200, $this->validatorReleaseJson()),
            new FakeHttpResponse(200, $this->configurationReleaseJson()),
        ]);

        $status = $service->getStatus(true);

        self::assertSame('1.6.1', $status['validator']['installedVersion']);
        self::assertSame('1.6.2', $status['validator']['latestVersion']);
        self::assertTrue($status['validator']['updateAvailable']);
        self::assertSame('2025-07-10', $status['configuration']['installedVersion']);
        self::assertSame('2026-01-31', $status['configuration']['latestVersion']);
        self::assertSame('3.0.2', $status['configuration']['latestXRechnungVersion']);
        self::assertTrue($status['configuration']['updateAvailable']);
        $paths = array_column($status['paths'], null, 'label');
        self::assertArrayHasKey('key', $paths['Java']);
        self::assertArrayHasKey('key', $paths['Python (optional)']);
        self::assertArrayNotHasKey('key', $paths['Validator-JAR']);
        self::assertArrayNotHasKey('key', $paths['XRechnung-Repository']);
        self::assertArrayNotHasKey('key', $paths['Temporäres Verzeichnis']);
        self::assertSame(self::VALIDATOR_API, $client->requests[0]['url']);
        self::assertSame(self::CONFIGURATION_API, $client->requests[1]['url']);
    }

    public function testStatusKeepsReleaseErrorsSeparate(): void {
        [$service] = $this->serviceWith([
            new \RuntimeException('validator offline'),
            new \RuntimeException('configuration offline'),
        ]);

        $status = $service->getStatus(true);

        self::assertSame('validator offline', $status['validator']['updateError']);
        self::assertSame('configuration offline', $status['configuration']['updateError']);
    }

    public function testRejectsUnexpectedConfigurationAssetUrl(): void {
        [$service] = $this->serviceWith([
            new FakeHttpResponse(200, $this->validatorReleaseJson()),
            new FakeHttpResponse(200, $this->configurationReleaseJson(
                'https://example.test/xrechnung-3.0.2-validator-configuration-2026-01-31.zip'
            )),
        ]);

        $status = $service->getStatus(true);

        self::assertStringContainsString('unerwartete Download-Adresse', $status['configuration']['updateError']);
    }

    public function testRejectsUncontrolledValidatorAssetName(): void {
        [$service] = $this->serviceWith([
            new FakeHttpResponse(200, json_encode([
                'tag_name' => 'v1.6.2',
                'assets' => [[
                    'name' => 'validator-latest-standalone.jar',
                    'browser_download_url' => self::VALIDATOR_URL,
                ]],
            ], JSON_THROW_ON_ERROR)),
            new FakeHttpResponse(200, $this->configurationReleaseJson()),
        ]);

        $status = $service->getStatus(true);

        self::assertStringContainsString('Standalone-JAR', $status['validator']['updateError']);
    }

    public function testManualConfigurationIsReportedWithoutInventingVersion(): void {
        mkdir($this->repositoryPath . '/resources', 0700, true);
        file_put_contents($this->repositoryPath . '/scenarios.xml', '<scenarios/>');
        [$service] = $this->serviceWith([]);

        $status = $service->getStatus(false);

        self::assertTrue($status['configuration']['installed']);
        self::assertNull($status['configuration']['installedVersion']);
    }

    public function testInstallRequiresZipArchive(): void {
        if (class_exists(\ZipArchive::class)) {
            self::markTestSkipped('This assertion applies only when ext-zip is unavailable.');
        }
        [$service, $client] = $this->serviceWith([]);

        $this->expectException(AdministrationException::class);
        $this->expectExceptionMessage('ZipArchive');
        try {
            $service->installLatest();
        } finally {
            self::assertSame([], $client->requests);
        }
    }

    #[RequiresPhpExtension('zip')]
    public function testInstallsValidatorAndConfigurationTogether(): void {
        $jar = "PK\x03\x04new validator";
        $configuration = $this->configurationArchive();
        [$service] = $this->serviceWith([
            new FakeHttpResponse(200, $this->validatorReleaseJson()),
            new FakeHttpResponse(200, $this->configurationReleaseJson()),
            new FakeHttpResponse(200, $jar),
            new FakeHttpResponse(200, $configuration),
        ]);

        $status = $service->installLatest();

        $newJarPath = $this->temporaryDirectory . '/validator-1.6.2-standalone.jar';
        self::assertTrue(is_link($this->jarPath));
        self::assertSame(basename($newJarPath), readlink($this->jarPath));
        self::assertSame($jar, file_get_contents($newJarPath));
        self::assertSame($jar, file_get_contents($this->jarPath));
        self::assertFileExists($this->oldJarPath);
        self::assertSame([], glob($this->temporaryDirectory . '/*.version'));
        self::assertTrue(is_link($this->repositoryPath));
        self::assertFileExists($this->repositoryPath . '/scenarios.xml');
        self::assertDirectoryExists($this->repositoryPath . '/resources');
        self::assertSame('1.6.2', $status['validator']['installedVersion']);
        self::assertSame('2026-01-31', $status['configuration']['installedVersion']);
        self::assertSame('3.0.2', $status['configuration']['installedXRechnungVersion']);
    }

    #[RequiresPhpExtension('zip')]
    public function testDoesNotSwitchSymlinkBeforeIntegrityCheckSucceeds(): void {
        $configuration = $this->configurationArchive();
        [$service] = $this->serviceWith([
            new FakeHttpResponse(200, $this->validatorReleaseJson('sha256:' . str_repeat('0', 64))),
            new FakeHttpResponse(200, $this->configurationReleaseJson()),
            new FakeHttpResponse(200, "PK\x03\x04new validator"),
            new FakeHttpResponse(200, $configuration),
        ]);

        try {
            $service->installLatest();
            self::fail('Expected the integrity check to reject the Validator download.');
        } catch (AdministrationException $e) {
            self::assertStringContainsString('SHA-256', $e->getMessage());
        }

        self::assertSame(basename($this->oldJarPath), readlink($this->jarPath));
        self::assertFileDoesNotExist($this->temporaryDirectory . '/validator-1.6.2-standalone.jar');
    }

    #[RequiresPhpExtension('zip')]
    public function testRejectsRealFileAtNeutralValidatorPathWithoutMigration(): void {
        unlink($this->jarPath);
        file_put_contents($this->jarPath, 'legacy neutral validator');
        file_put_contents($this->jarPath . '.version', '{"version":"1.6.1"}');
        [$service] = $this->serviceWith([
            new FakeHttpResponse(200, $this->validatorReleaseJson()),
            new FakeHttpResponse(200, $this->configurationReleaseJson()),
        ]);

        $status = $service->getStatus(false);
        self::assertFalse($status['validator']['installed']);
        self::assertNull($status['validator']['installedVersion']);

        $this->expectException(AdministrationException::class);
        $this->expectExceptionMessage('kein von der App verwalteter Symlink');
        $service->installLatest();
    }

    #[RequiresPhpExtension('zip')]
    public function testRejectsValidatorSymlinkOutsideManagedRoot(): void {
        $outsideDirectory = sys_get_temp_dir() . '/kosit-outside-' . bin2hex(random_bytes(8));
        mkdir($outsideDirectory, 0700, true);
        $outsideJar = $outsideDirectory . '/validator-1.6.1-standalone.jar';
        file_put_contents($outsideJar, 'outside validator');
        unlink($this->jarPath);
        symlink($outsideJar, $this->jarPath);
        [$service] = $this->serviceWith([
            new FakeHttpResponse(200, $this->validatorReleaseJson()),
            new FakeHttpResponse(200, $this->configurationReleaseJson()),
        ]);

        try {
            $status = $service->getStatus(false);
            self::assertFalse($status['validator']['installed']);
            $service->installLatest();
            self::fail('Expected the external symlink target to be rejected.');
        } catch (AdministrationException $e) {
            self::assertStringContainsString('kontrolliertes Release', $e->getMessage());
        } finally {
            unlink($outsideJar);
            rmdir($outsideDirectory);
        }
    }

    #[RequiresPhpExtension('zip')]
    public function testAcceptsReleaseStyleScenarioDocument(): void {
        $scenario = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<!-- This is the source file for creating scenarios.xml -->
<scenarios xmlns="http://www.xoev.de/de/validator/framework/1/scenarios"
  frameworkVersion="1.0.0">
  <name>Validator Configuration XRechnung 3.0.2</name>
  <scenario>
    <name>EN16931 XRechnung (UBL Invoice)</name>
  </scenario>
</scenarios>
XML;
        [$service] = $this->serviceWith([
            new FakeHttpResponse(200, $this->validatorReleaseJson()),
            new FakeHttpResponse(200, $this->configurationReleaseJson()),
            new FakeHttpResponse(200, "PK\x03\x04new validator"),
            new FakeHttpResponse(200, $this->configurationArchive($scenario)),
        ]);

        $status = $service->installLatest();

        self::assertSame('2026-01-31', $status['configuration']['installedVersion']);
        self::assertSame($scenario, file_get_contents($this->repositoryPath . '/scenarios.xml'));
    }

    /** @param list<FakeHttpResponse|\Throwable> $responses
     *  @return array{ValidatorAdminService, QueuedHttpClientService}
     */
    private function serviceWith(array $responses): array {
        $client = new QueuedHttpClientService($responses);
        return [new ValidatorAdminService($this->config, $client, null, null, $this->temporaryDirectory), $client];
    }

    private function validatorReleaseJson(?string $digest = null): string {
        return json_encode([
            'tag_name' => 'v1.6.2',
            'assets' => [[
                'name' => 'validator-1.6.2-standalone.jar',
                'browser_download_url' => self::VALIDATOR_URL,
                'digest' => $digest,
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    private function configurationReleaseJson(?string $url = null): string {
        return json_encode([
            'tag_name' => 'v2026-01-31',
            'assets' => [[
                'name' => 'xrechnung-3.0.2-validator-configuration-2026-01-31.zip',
                'browser_download_url' => $url ?? self::CONFIGURATION_URL,
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    private function configurationArchive(?string $scenario = null): string {
        $path = $this->temporaryDirectory . '/configuration.zip';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString(
            'scenarios.xml',
            $scenario ?? '<scenarios xmlns="http://www.xoev.de/de/validator/framework/1/scenarios"/>'
        );
        $zip->addFromString('resources/report.xsl', '<xsl/>');
        $zip->close();
        $body = file_get_contents($path);
        self::assertIsString($body);
        unlink($path);
        return $body;
    }

    private function createInstalledConfiguration(string $release, string $xrechnung): void {
        $directory = $this->temporaryDirectory . '/xrechnung/releases/' . $release;
        mkdir($directory . '/resources', 0700, true);
        $scenario = '<scenarios xmlns="http://www.xoev.de/de/validator/framework/1/scenarios"/>';
        file_put_contents($directory . '/scenarios.xml', $scenario);
        file_put_contents($directory . '/.kosit-xrechnung-version', json_encode([
            'release' => $release,
            'xrechnung' => $xrechnung,
            'scenarioSha256' => hash('sha256', $scenario),
        ], JSON_THROW_ON_ERROR));
        symlink($directory, $this->repositoryPath);
    }

    private function removeDirectory(string $directory): void {
        if (!is_dir($directory)) {
            return;
        }
        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $item) {
            if ($item->isLink() || $item->isFile()) {
                unlink($item->getPathname());
            } else {
                $this->removeDirectory($item->getPathname());
            }
        }
        rmdir($directory);
    }
}
