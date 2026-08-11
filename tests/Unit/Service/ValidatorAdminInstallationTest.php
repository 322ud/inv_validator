<?php

declare(strict_types=1);

namespace OCA\KoSITValidator\Tests\Unit\Service;

use OCA\KoSITValidator\Exception\AdministrationException;
use OCA\KoSITValidator\Service\ValidatorAdminService;
use OCA\KoSITValidator\Service\ValidatorConfigService;
use OCA\KoSITValidator\Tests\Support\FakeHttpResponse;
use OCA\KoSITValidator\Tests\Support\QueuedHttpClientService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValidatorAdminInstallationTest extends TestCase {
    private const RELEASE_API = 'https://api.github.com/repos/itplr-kosit/validator/releases/latest';
    private const DOWNLOAD_URL = 'https://github.com/itplr-kosit/validator/releases/download/v2.0.0/validator-2.0.0-standalone.jar';

    private string $temporaryDirectory;
    private string $jarPath;
    private ValidatorConfigService $config;

    protected function setUp(): void {
        $this->temporaryDirectory = sys_get_temp_dir() . '/kosit-install-test-' . bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory, 0700, true);
        mkdir($this->temporaryDirectory . '/repository');
        $this->jarPath = $this->temporaryDirectory . '/validator.jar';
        file_put_contents($this->jarPath, 'old validator');
        file_put_contents($this->temporaryDirectory . '/scenario.xml', '<scenarios/>');

        $this->config = $this->createStub(ValidatorConfigService::class);
        $this->config->method('getValidatorJar')->willReturn($this->jarPath);
        $this->config->method('getAppDirectory')->willReturn($this->temporaryDirectory);
        $this->config->method('getPhpBinary')->willReturn(PHP_BINARY);
        $this->config->method('getJavaBinary')->willReturn(PHP_BINARY);
        $this->config->method('getRepositoryPath')->willReturn($this->temporaryDirectory . '/repository');
        $this->config->method('getScenarioFile')->willReturn($this->temporaryDirectory . '/scenario.xml');
        $this->config->method('getTempDirectory')->willReturn($this->temporaryDirectory);
    }

    protected function tearDown(): void {
        $this->removeDirectory($this->temporaryDirectory);
    }

    public function testStatusReportsAvailableUpdateAndUsesExpectedRequestOptions(): void {
        $this->writeVersionMarker('1.5.0');
        [$service, $client] = $this->serviceWith([
            new FakeHttpResponse(200, $this->releaseJson()),
        ]);

        $status = $service->getStatus(true);

        self::assertSame('1.5.0', $status['installedVersion']);
        self::assertSame('2.0.0', $status['latestVersion']);
        self::assertTrue($status['updateAvailable']);
        self::assertNull($status['updateError']);
        self::assertSame(self::RELEASE_API, $client->requests[0]['url']);
        self::assertSame(10, $client->requests[0]['options']['connect_timeout']);
        self::assertSame(20, $client->requests[0]['options']['timeout']);
        self::assertSame('application/vnd.github+json', $client->requests[0]['options']['headers']['Accept']);
    }

    public function testStatusTurnsNetworkFailureIntoUpdateError(): void {
        [$service] = $this->serviceWith([new \RuntimeException('network unavailable')]);

        $status = $service->getStatus(true);

        self::assertNull($status['latestVersion']);
        self::assertFalse($status['updateAvailable']);
        self::assertSame('network unavailable', $status['updateError']);
    }

    #[DataProvider('invalidReleaseProvider')]
    public function testRejectsInvalidReleaseResponses(int $statusCode, string $body, string $message): void {
        [$service] = $this->serviceWith([new FakeHttpResponse($statusCode, $body)]);

        $status = $service->getStatus(true);

        self::assertNull($status['latestVersion']);
        self::assertStringContainsString($message, $status['updateError']);
    }

    public static function invalidReleaseProvider(): iterable {
        yield 'http error' => [503, '{}', 'HTTP 503'];
        yield 'malformed json' => [200, '{', 'ungültige Release-Daten'];
        yield 'invalid version' => [200, json_encode([
            'tag_name' => 'nightly',
            'assets' => [],
        ], JSON_THROW_ON_ERROR), 'keine gültige Release-Version'];
        yield 'invalid tag type' => [200, json_encode([
            'tag_name' => ['v2.0.0'],
            'assets' => [],
        ], JSON_THROW_ON_ERROR), 'ungültige Release-Daten'];
        yield 'invalid assets type' => [200, json_encode([
            'tag_name' => 'v2.0.0',
            'assets' => 'not-an-array',
        ], JSON_THROW_ON_ERROR), 'ungültige Release-Daten'];
        yield 'missing asset' => [200, json_encode([
            'tag_name' => 'v2.0.0',
            'assets' => [],
        ], JSON_THROW_ON_ERROR), 'Standalone-JAR'];
        yield 'foreign host' => [200, self::releaseJsonStatic(
            'https://github.com.evil.example/itplr-kosit/validator/releases/download/v2.0.0/validator-2.0.0-standalone.jar'
        ), 'unerwartete Download-Adresse'];
        yield 'userinfo' => [200, self::releaseJsonStatic(
            'https://attacker@github.com/itplr-kosit/validator/releases/download/v2.0.0/validator-2.0.0-standalone.jar'
        ), 'unerwartete Download-Adresse'];
        yield 'non-standard port' => [200, self::releaseJsonStatic(
            'https://github.com:444/itplr-kosit/validator/releases/download/v2.0.0/validator-2.0.0-standalone.jar'
        ), 'unerwartete Download-Adresse'];
        yield 'wrong filename' => [200, self::releaseJsonStatic(
            'https://github.com/itplr-kosit/validator/releases/download/v2.0.0/other.jar'
        ), 'unerwartete Download-Adresse'];
        yield 'invalid url type' => [200, json_encode([
            'tag_name' => 'v2.0.0',
            'assets' => [[
                'name' => 'validator-2.0.0-standalone.jar',
                'browser_download_url' => ['unexpected'],
            ]],
        ], JSON_THROW_ON_ERROR), 'ungültige Release-Daten'];
        yield 'invalid digest type' => [200, json_encode([
            'tag_name' => 'v2.0.0',
            'assets' => [[
                'name' => 'validator-2.0.0-standalone.jar',
                'browser_download_url' => self::DOWNLOAD_URL,
                'digest' => ['unexpected'],
            ]],
        ], JSON_THROW_ON_ERROR), 'ungültige Release-Daten'];
    }

    public function testInstallAtomicallyReplacesJarAndWritesVersionMarker(): void {
        $jar = "PK\x03\x04new validator jar";
        $digest = 'sha256:' . hash('sha256', $jar);
        [$service, $client] = $this->serviceWith([
            new FakeHttpResponse(200, $this->releaseJson(digest: $digest)),
            new FakeHttpResponse(200, $jar),
        ]);

        $status = $service->installLatest();

        self::assertSame($jar, file_get_contents($this->jarPath));
        self::assertSame(0640, fileperms($this->jarPath) & 0777);
        self::assertSame('2.0.0', $status['installedVersion']);
        self::assertSame('2.0.0', $status['latestVersion']);
        self::assertFalse($status['updateAvailable']);
        self::assertFileExists($this->jarPath . '.version');
        self::assertSame([], glob($this->temporaryDirectory . '/.validator-*') ?: []);
        self::assertSame(self::DOWNLOAD_URL, $client->requests[1]['url']);
        self::assertSame(15, $client->requests[1]['options']['connect_timeout']);
        self::assertSame(120, $client->requests[1]['options']['timeout']);
    }

    #[DataProvider('invalidDownloadProvider')]
    public function testDownloadFailuresLeaveExistingInstallationUntouched(
        string $body,
        ?string $digest,
        string $message,
        ?int $maxDownloadBytes = null
    ): void {
        $original = file_get_contents($this->jarPath);
        [$service] = $this->serviceWith([
            new FakeHttpResponse(200, $this->releaseJson(digest: $digest)),
            new FakeHttpResponse(200, $body),
        ], $maxDownloadBytes);

        try {
            $service->installLatest();
            self::fail('Expected the download to be rejected');
        } catch (AdministrationException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }

        self::assertSame($original, file_get_contents($this->jarPath));
        self::assertFileDoesNotExist($this->jarPath . '.version');
        self::assertSame([], glob($this->temporaryDirectory . '/.validator-*') ?: []);
        $this->assertInstallLockIsAvailable();
    }

    public static function invalidDownloadProvider(): iterable {
        yield 'empty body' => ['', null, 'leer oder überschreitet'];
        yield 'over size limit' => ["PK\x03\x04" . str_repeat('x', 20), null, 'überschreitet', 16];
        yield 'not a jar' => ['plain text', null, 'keine gültige JAR-Datei'];
        yield 'checksum mismatch' => ["PK\x03\x04jar", 'sha256:' . str_repeat('0', 64), 'SHA-256-Prüfung'];
        yield 'unsupported digest' => ["PK\x03\x04jar", 'sha512:' . str_repeat('0', 128), 'keine gültige SHA-256-Prüfsumme'];
        yield 'malformed digest' => ["PK\x03\x04jar", 'sha256:abcd', 'keine gültige SHA-256-Prüfsumme'];
    }

    public function testDownloadHttpErrorLeavesExistingInstallationUntouched(): void {
        $original = file_get_contents($this->jarPath);
        [$service] = $this->serviceWith([
            new FakeHttpResponse(200, $this->releaseJson()),
            new FakeHttpResponse(502, 'gateway error'),
        ]);

        try {
            $service->installLatest();
            self::fail('Expected the download to fail');
        } catch (AdministrationException $exception) {
            self::assertStringContainsString('HTTP 502', $exception->getMessage());
        }

        self::assertSame($original, file_get_contents($this->jarPath));
        $this->assertInstallLockIsAvailable();
    }

    public function testReleaseNetworkErrorLeavesExistingInstallationUntouched(): void {
        $original = file_get_contents($this->jarPath);
        [$service] = $this->serviceWith([new \RuntimeException('offline')]);

        try {
            $service->installLatest();
            self::fail('Expected the release request to fail');
        } catch (\RuntimeException $exception) {
            self::assertSame('offline', $exception->getMessage());
        }

        self::assertSame($original, file_get_contents($this->jarPath));
    }

    public function testDownloadNetworkErrorLeavesExistingInstallationUntouchedAndReleasesLock(): void {
        $original = file_get_contents($this->jarPath);
        [$service] = $this->serviceWith([
            new FakeHttpResponse(200, $this->releaseJson()),
            new \RuntimeException('download interrupted'),
        ]);

        try {
            $service->installLatest();
            self::fail('Expected the download request to fail');
        } catch (\RuntimeException $exception) {
            self::assertSame('download interrupted', $exception->getMessage());
        }

        self::assertSame($original, file_get_contents($this->jarPath));
        $this->assertInstallLockIsAvailable();
    }

    public function testEqualInstalledAndLatestVersionDoesNotOfferUpdate(): void {
        $this->writeVersionMarker('2.0.0');
        [$service] = $this->serviceWith([
            new FakeHttpResponse(200, $this->releaseJson()),
        ]);

        $status = $service->getStatus(true);

        self::assertSame('2.0.0', $status['installedVersion']);
        self::assertSame('2.0.0', $status['latestVersion']);
        self::assertFalse($status['updateAvailable']);
    }

    public function testConcurrentInstallationIsRejectedBeforeDownload(): void {
        $original = file_get_contents($this->jarPath);
        [$service, $client] = $this->serviceWith([
            new FakeHttpResponse(200, $this->releaseJson()),
        ]);
        $process = proc_open(
            [
                PHP_BINARY,
                dirname(__DIR__, 2) . '/fixtures/hold-lock.php',
                $this->temporaryDirectory . '/.kosit-validator-install.lock',
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);

        try {
            self::assertSame("locked\n", fgets($pipes[1]));
            $this->expectException(AdministrationException::class);
            $this->expectExceptionMessage('Installation läuft bereits');
            $service->installLatest();
        } finally {
            proc_terminate($process);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            self::assertSame($original, file_get_contents($this->jarPath));
            self::assertCount(1, $client->requests);
        }
    }

    public function testVersionMarkerIsIgnoredAfterJarWasModified(): void {
        $jar = "PK\x03\x04new validator jar";
        [$service] = $this->serviceWith([
            new FakeHttpResponse(200, $this->releaseJson()),
            new FakeHttpResponse(200, $jar),
        ]);
        $service->installLatest();

        file_put_contents($this->jarPath, $jar . 'tampered');
        clearstatcache(true, $this->jarPath);

        self::assertNull($service->getStatus(false)['installedVersion']);
    }

    /**
     * @param list<FakeHttpResponse|\Throwable> $responses
     * @return array{ValidatorAdminService, QueuedHttpClientService}
     */
    private function serviceWith(array $responses, ?int $maxDownloadBytes = null): array {
        $client = new QueuedHttpClientService($responses);

        return [
            new ValidatorAdminService($this->config, $client, $maxDownloadBytes),
            $client,
        ];
    }

    private function releaseJson(?string $digest = null): string {
        return self::releaseJsonStatic(self::DOWNLOAD_URL, $digest);
    }

    private static function releaseJsonStatic(string $url, ?string $digest = null): string {
        $asset = [
            'name' => 'validator-2.0.0-standalone.jar',
            'browser_download_url' => $url,
        ];
        if ($digest !== null) {
            $asset['digest'] = $digest;
        }

        return json_encode([
            'tag_name' => 'v2.0.0',
            'assets' => [$asset],
        ], JSON_THROW_ON_ERROR);
    }

    private function writeVersionMarker(string $version): void {
        file_put_contents($this->jarPath . '.version', json_encode([
            'version' => $version,
            'size' => filesize($this->jarPath),
            'modified' => filemtime($this->jarPath),
        ], JSON_THROW_ON_ERROR));
    }

    private function assertInstallLockIsAvailable(): void {
        $lock = fopen($this->temporaryDirectory . '/.kosit-validator-install.lock', 'c');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    private function removeDirectory(string $directory): void {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}
