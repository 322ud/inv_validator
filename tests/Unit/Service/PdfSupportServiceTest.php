<?php

declare(strict_types=1);

namespace OCA\INValidator\Tests\Unit\Service;

use OCA\INValidator\Service\PdfSupportService;
use OCA\INValidator\Service\ValidatorConfigService;
use PHPUnit\Framework\TestCase;

final class PdfSupportServiceTest extends TestCase
{
    public function testReportsMissingPythonWithoutStartingAProcess(): void
    {
        $config = $this->createStub(ValidatorConfigService::class);
        $config->method('getPythonBinary')->willReturn('/missing/python');

        $status = (new PdfSupportService($config))->getStatus();

        self::assertFalse($status['available']);
        self::assertFalse($status['pythonAvailable']);
        self::assertStringContainsString('nicht gefunden', $status['reason']);
    }

    public function testReportsPythonAndPymupdfVersions(): void
    {
        $config = $this->createStub(ValidatorConfigService::class);
        $config->method('getPythonBinary')->willReturn(
            dirname(__DIR__, 2) . '/fixtures/fake-python.php'
        );

        $status = (new PdfSupportService($config))->getStatus();

        self::assertTrue($status['available']);
        self::assertSame('3.12.1', $status['pythonVersion']);
        self::assertSame('1.26.0', $status['pymupdfVersion']);
        self::assertNull($status['reason']);
    }

    public function testReportsWorkingPythonWhenPymupdfIsMissing(): void
    {
        $config = $this->createStub(ValidatorConfigService::class);
        $config->method('getPythonBinary')->willReturn(
            dirname(__DIR__, 2) . '/fixtures/fake-python-without-pymupdf.php'
        );

        $status = (new PdfSupportService($config))->getStatus();

        self::assertFalse($status['available']);
        self::assertTrue($status['pythonAvailable']);
        self::assertSame('3.12.1', $status['pythonVersion']);
        self::assertFalse($status['pymupdfAvailable']);
        self::assertStringContainsString('nicht installiert', $status['reason']);
    }
}
