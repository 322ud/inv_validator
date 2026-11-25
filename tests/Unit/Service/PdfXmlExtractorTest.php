<?php

declare(strict_types=1);

namespace OCA\INValidator\Tests\Unit\Service;

use OCA\INValidator\Exception\DocumentInputException;
use OCA\INValidator\Service\PdfSupportService;
use OCA\INValidator\Service\PdfXmlExtractor;
use OCA\INValidator\Service\ValidatorConfigService;
use PHPUnit\Framework\TestCase;
use OCP\ITempManager;

final class PdfXmlExtractorTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/kosit-pdf-test-' . bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory, 0700, true);
    }

    private function tempManager(): ITempManager
    {
        $manager = $this->createStub(ITempManager::class);
        $manager->method('getTempBaseDir')->willReturn($this->temporaryDirectory);
        $manager->method('getTemporaryFolder')->willReturnCallback(function (): string {
            $directory = $this->temporaryDirectory . '/oc_tmp_' . bin2hex(random_bytes(8));
            mkdir($directory, 0700);
            return $directory . '/';
        });
        return $manager;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temporaryDirectory . '/*') ?: [] as $path) {
            if (is_dir($path)) {
                foreach (glob($path . '/*') ?: [] as $file) {
                    unlink($file);
                }
                rmdir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($this->temporaryDirectory);
    }

    public function testExtractsXmlAndRemovesTemporaryOutput(): void
    {
        $input = $this->temporaryDirectory . '/invoice.pdf';
        file_put_contents($input, 'fake-pdf:<invoice/>');

        $extractor = new PdfXmlExtractor(
            $this->config(),
            $this->availableSupport(),
            $this->tempManager()
        );

        self::assertSame('<invoice/>', $extractor->extract($input));
        self::assertSame([$input], glob($this->temporaryDirectory . '/*'));
    }

    public function testRejectsPdfWhenOptionalSupportIsUnavailable(): void
    {
        $support = $this->createStub(PdfSupportService::class);
        $support->method('getStatus')->willReturn([
            'available' => false,
            'pythonAvailable' => false,
            'pythonVersion' => null,
            'pymupdfAvailable' => false,
            'pymupdfVersion' => null,
            'reason' => 'missing',
        ]);

        $this->expectException(DocumentInputException::class);
        $this->expectExceptionMessage('nicht verfügbar');
        (new PdfXmlExtractor($this->config(), $support, $this->tempManager()))->extract('/unused.pdf');
    }

    public function testReportsFailureToAllocateNextcloudTemporaryFolder(): void
    {
        $manager = $this->createStub(ITempManager::class);
        $manager->method('getTemporaryFolder')->willReturn(false);
        $this->expectException(\OCA\INValidator\Exception\ValidationException::class);
        $this->expectExceptionMessage('Nextcloud konnte kein temporäres Arbeitsverzeichnis anlegen.');
        (new PdfXmlExtractor($this->config(), $this->availableSupport(), $manager))->extract('/unused.pdf');
    }

    private function config(): ValidatorConfigService
    {
        $config = $this->createStub(ValidatorConfigService::class);
        $config->method('getPythonBinary')->willReturn(
            dirname(__DIR__, 2) . '/fixtures/fake-python.php'
        );
        $config->method('getPdfExtractorScript')->willReturn('/extractor.py');

        return $config;
    }

    private function availableSupport(): PdfSupportService
    {
        $support = $this->createStub(PdfSupportService::class);
        $support->method('getStatus')->willReturn([
            'available' => true,
            'pythonAvailable' => true,
            'pythonVersion' => '3.12.1',
            'pymupdfAvailable' => true,
            'pymupdfVersion' => '1.26.0',
            'reason' => null,
        ]);

        return $support;
    }
}
