<?php

declare(strict_types=1);

namespace OCA\INValidator\Tests\Unit\Service;

use OCA\INValidator\Exception\ValidationException;
use OCA\INValidator\Service\ValidatorProcessRunner;
use PHPUnit\Framework\TestCase;

final class ValidatorProcessRunnerTest extends TestCase {
    private const FIXTURE = __DIR__ . '/../../fixtures/process.php';

    public function testCapturesOutputAndExitCode(): void {
        $result = (new ValidatorProcessRunner())->run([
            PHP_BINARY,
            self::FIXTURE,
            'exit',
            'standard output',
            'standard error',
            '7',
        ]);

        self::assertSame(7, $result['exitCode']);
        self::assertSame('standard output', $result['stdout']);
        self::assertSame('standard error', $result['stderr']);
    }

    public function testDrainsBothPipesAndLimitsEachOutput(): void {
        $result = (new ValidatorProcessRunner(maxOutputBytes: 1024))->run([
            PHP_BINARY,
            self::FIXTURE,
            'large-output',
            '100000',
        ]);

        self::assertSame(0, $result['exitCode']);
        self::assertSame(1024, strlen($result['stdout']));
        self::assertSame(1024, strlen($result['stderr']));
        self::assertSame(str_repeat('o', 1024), $result['stdout']);
        self::assertSame(str_repeat('e', 1024), $result['stderr']);
    }

    public function testTimeoutEscalatesWhenProcessIgnoresSigterm(): void {
        $marker = sys_get_temp_dir() . '/kosit-term-' . bin2hex(random_bytes(8));
        $runner = new ValidatorProcessRunner(
            timeoutSeconds: 0.1,
            terminationGraceSeconds: 0.1
        );
        $started = microtime(true);

        try {
            $runner->run([PHP_BINARY, self::FIXTURE, 'ignore-term', $marker]);
            self::fail('Expected a process timeout');
        } catch (ValidationException $exception) {
            self::assertSame('Validator process timed out', $exception->getMessage());
            self::assertLessThan(1.0, microtime(true) - $started);
            self::assertFileExists($marker);
        } finally {
            if (is_file($marker)) {
                unlink($marker);
            }
        }
    }
}
