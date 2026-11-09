<?php

declare(strict_types=1);

namespace OCA\INValidator\Tests\EndToEnd;

use OCA\INValidator\Service\KoSITValidatorService;
use OCA\INValidator\Service\ValidatorConfigService;
use OCA\INValidator\Service\ValidatorProcessRunner;
use PHPUnit\Framework\TestCase;

final class KoSITValidatorSmokeTest extends TestCase {
    private string $temporaryDirectory = '';
    private KoSITValidatorService $service;
    private string $validInvoice;

    protected function setUp(): void {
        $javaBinary = $this->requiredPath('KOSIT_JAVA_BINARY');
        $validatorJar = $this->requiredPath('KOSIT_VALIDATOR_JAR');
        $repositoryPath = $this->requiredPath('KOSIT_REPOSITORY');
        $scenarioFile = $this->requiredPath('KOSIT_SCENARIO_FILE');
        $invoicePath = $this->requiredPath('KOSIT_VALID_INVOICE');

        $invoice = file_get_contents($invoicePath);
        self::assertNotFalse($invoice);
        $this->validInvoice = $invoice;

        $this->temporaryDirectory = sys_get_temp_dir()
            . '/kosit end-to-end test-'
            . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->temporaryDirectory, 0700, true));

        $config = $this->createStub(ValidatorConfigService::class);
        $config->method('getJavaBinary')->willReturn($javaBinary);
        $config->method('getValidatorJar')->willReturn($validatorJar);
        $config->method('getRepositoryPath')->willReturn($repositoryPath);
        $config->method('getScenarioFile')->willReturn($scenarioFile);
        $config->method('getTempDirectory')->willReturn($this->temporaryDirectory);

        $this->service = new KoSITValidatorService(
            $config,
            new ValidatorProcessRunner(timeoutSeconds: 120.0)
        );
    }

    protected function tearDown(): void {
        $this->removeDirectory($this->temporaryDirectory);
    }

    public function testAcceptsKnownValidXRechnungInvoice(): void {
        $result = $this->service->validateXml($this->validInvoice);

        self::assertTrue($result->isValid());
        self::assertSame(0, $result->getExitCode());
        self::assertNotNull($result->getReportHtml());
        self::assertStringContainsString('123456XX', $result->getReportHtml());
        $this->assertNoWorkingDirectoriesRemain();
    }

    public function testRejectsInvoiceWithoutSupplierParty(): void {
        $result = $this->service->validateXml($this->invoiceWithoutSupplierParty());

        self::assertFalse($result->isValid());
        self::assertNotSame(0, $result->getExitCode());
        self::assertNotNull($result->getReportHtml());
        self::assertStringContainsString('123456XX', $result->getReportHtml());
        $this->assertNoWorkingDirectoriesRemain();
    }

    private function requiredPath(string $name): string {
        $value = getenv($name);

        self::assertIsString($value, $name . ' must be configured');
        self::assertNotSame('', $value, $name . ' must not be empty');
        self::assertFileExists($value);

        return $value;
    }

    private function invoiceWithoutSupplierParty(): string {
        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($this->validInvoice, LIBXML_NONET));

        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace(
            'ubl',
            'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2'
        );
        $xpath->registerNamespace(
            'cac',
            'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2'
        );
        $supplierParties = $xpath->query('/ubl:Invoice/cac:AccountingSupplierParty');

        self::assertNotFalse($supplierParties);
        self::assertSame(1, $supplierParties->length);
        $supplierParty = $supplierParties->item(0);
        self::assertNotNull($supplierParty);
        self::assertNotNull($supplierParty->parentNode);
        $supplierParty->parentNode->removeChild($supplierParty);

        $xml = $document->saveXML();
        self::assertNotFalse($xml);

        return $xml;
    }

    private function assertNoWorkingDirectoriesRemain(): void {
        self::assertSame([], glob($this->temporaryDirectory . '/inv-validator-*') ?: []);
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
