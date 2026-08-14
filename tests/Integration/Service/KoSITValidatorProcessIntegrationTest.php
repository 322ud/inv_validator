<?php

declare(strict_types=1);

namespace OCA\KoSITValidator\Tests\Integration\Service;

use OCA\KoSITValidator\Exception\ValidationException;
use OCA\KoSITValidator\Service\KoSITValidatorService;
use OCA\KoSITValidator\Service\ValidatorConfigService;
use OCA\KoSITValidator\Service\ValidatorProcessRunner;
use PHPUnit\Framework\TestCase;

final class KoSITValidatorProcessIntegrationTest extends TestCase {
    private const VALIDATOR_FIXTURE = __DIR__ . '/../../fixtures/fake-validator.php';
    private const LOCK_FIXTURE = __DIR__ . '/../../fixtures/hold-lock.php';

    private string $temporaryDirectory;
    private KoSITValidatorService $service;

    protected function setUp(): void {
        // The spaces also verify that proc_open receives paths as separate arguments.
        $this->temporaryDirectory = sys_get_temp_dir() . '/kosit process test-' . bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory, 0700, true);
        mkdir($this->temporaryDirectory . '/repository');
        file_put_contents($this->temporaryDirectory . '/validator.jar', 'fixture');
        file_put_contents($this->temporaryDirectory . '/repository/scenarios.xml', '<scenarios/>');

        $config = $this->createStub(ValidatorConfigService::class);
        $config->method('getJavaBinary')->willReturn(self::VALIDATOR_FIXTURE);
        $config->method('getValidatorJar')->willReturn($this->temporaryDirectory . '/validator.jar');
        $config->method('getRepositoryPath')->willReturn($this->temporaryDirectory . '/repository');
        $config->method('getScenarioFile')->willReturn($this->temporaryDirectory . '/repository/scenarios.xml');
        $config->method('getTempDirectory')->willReturn($this->temporaryDirectory);

        $this->service = new KoSITValidatorService(
            $config,
            new ValidatorProcessRunner(timeoutSeconds: 0.2, terminationGraceSeconds: 0.05)
        );
    }

    protected function tearDown(): void {
        $this->removeDirectory($this->temporaryDirectory);
    }

    public function testRunsValidatorAndReturnsReport(): void {
        $result = $this->service->validateXml('<accept/>');

        self::assertTrue($result->isValid());
        self::assertSame(0, $result->getExitCode());
        self::assertSame('fixture stdout', $result->getStdout());
        self::assertSame('fixture stderr', $result->getStderr());
        self::assertSame('<html><body>fixture report</body></html>', $result->getReportHtml());
        $this->assertNoWorkingDirectoriesRemain();
    }

    public function testKeepsExitCodeSeparateFromRejectedAssessment(): void {
        $result = $this->service->validateXml('<reject/><exit-code/>');

        self::assertFalse($result->isValid());
        self::assertSame(7, $result->getExitCode());
    }

    public function testMissingReportIncludesExitCodeAndCleansUp(): void {
        try {
            $this->service->validateXml('<no-report/>');
            self::fail('Expected a missing report exception');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('exit code 9', $exception->getMessage());
            self::assertStringContainsString('fixture produced no report', $exception->getMessage());
        }

        $this->assertNoWorkingDirectoriesRemain();
        $this->assertLockIsAvailable();
    }

    public function testTimeoutCleansUpAndReleasesLock(): void {
        $started = microtime(true);

        try {
            $this->service->validateXml('<sleep/>');
            self::fail('Expected a process timeout');
        } catch (ValidationException $exception) {
            self::assertSame('Validator process timed out', $exception->getMessage());
            self::assertLessThan(1.0, microtime(true) - $started);
        }

        $this->assertNoWorkingDirectoriesRemain();
        $this->assertLockIsAvailable();
    }

    public function testConcurrentProcessIsRejectedAndLockCanBeReused(): void {
        $process = proc_open(
            [PHP_BINARY, self::LOCK_FIXTURE, $this->temporaryDirectory . '/validator.lock'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);

        try {
            self::assertSame("locked\n", fgets($pipes[1]));

            try {
                $this->service->validateXml('<accept/>');
                self::fail('Expected the concurrent validation to be rejected');
            } catch (ValidationException $exception) {
                self::assertSame(
                    'Validator is already processing another document',
                    $exception->getMessage()
                );
            }
        } finally {
            proc_terminate($process);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }

        self::assertTrue($this->service->validateXml('<accept/>')->isValid());
    }

    private function assertNoWorkingDirectoriesRemain(): void {
        self::assertSame([], glob($this->temporaryDirectory . '/kosit-validator-*') ?: []);
    }

    private function assertLockIsAvailable(): void {
        $lock = fopen($this->temporaryDirectory . '/validator.lock', 'c');
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
