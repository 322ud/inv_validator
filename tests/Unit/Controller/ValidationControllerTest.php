<?php

declare(strict_types=1);

namespace OCA\KoSITValidator\Tests\Unit\Controller;

use OCA\KoSITValidator\Controller\ValidationController;
use OCA\KoSITValidator\Exception\DocumentInputException;
use OCA\KoSITValidator\Exception\ValidationException;
use OCA\KoSITValidator\Service\KoSITValidatorService;
use OCA\KoSITValidator\Service\PdfXmlExtractor;
use OCA\KoSITValidator\Service\ValidationResult;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ValidationControllerTest extends TestCase {
    public function testRejectsMissingXml(): void {
        $response = $this->controllerWithXml('')->validate();

        self::assertSame(400, $response->getStatus());
        self::assertSame(false, $response->getData()['success']);
    }

    public function testRejectsXmlLargerThanFiveMegabytes(): void {
        $response = $this->controllerWithXml(str_repeat('x', 5 * 1024 * 1024 + 1))->validate();

        self::assertSame(413, $response->getStatus());
        self::assertSame('XML-Datei ist zu groß', $response->getData()['message']);
    }

    #[DataProvider('invalidXmlProvider')]
    public function testRejectsInvalidXml(string $xml): void {
        $response = $this->controllerWithXml($xml)->validate();

        self::assertSame(400, $response->getStatus());
        self::assertSame('Ungültiger XML-Inhalt', $response->getData()['message']);
    }

    public static function invalidXmlProvider(): iterable {
        yield 'whitespace' => ['   '];
        yield 'malformed' => ['<invoice>'];
        yield 'doctype' => ['<!DOCTYPE invoice><invoice/>'];
    }

    public function testReturnsTechnicalAndBusinessResultSeparately(): void {
        $service = $this->createMock(KoSITValidatorService::class);
        $service->expects(self::once())->method('validateXml')->with('<invoice/>')
            ->willReturn(new ValidationResult(false, 0, 'output', '', '<html>report</html>'));

        $response = $this->controllerWithXml('<invoice/>', $service)->validate();

        self::assertSame(200, $response->getStatus());
        self::assertSame([
            'success' => true,
            'valid' => false,
            'documentType' => 'xml',
            'exitCode' => 0,
            'reportHtml' => '<html>report</html>',
        ], $response->getData());
    }

    public function testExtractsAndValidatesXmlFromUploadedPdf(): void {
        $path = tempnam(sys_get_temp_dir(), 'kosit-upload-');
        self::assertIsString($path);
        file_put_contents($path, '%PDF-fake');

        try {
            $extractor = $this->createMock(PdfXmlExtractor::class);
            $extractor->expects(self::once())->method('extract')->with($path)
                ->willReturn('<invoice/>');
            $service = $this->createMock(KoSITValidatorService::class);
            $service->expects(self::once())->method('validateXml')->with('<invoice/>')
                ->willReturn(new ValidationResult(true, 0, '', '', null));

            $response = $this->controllerWithUpload([
                'name' => 'invoice.pdf',
                'tmp_name' => $path,
                'error' => UPLOAD_ERR_OK,
            ], $service, $extractor)->validate();

            self::assertSame(200, $response->getStatus());
            self::assertTrue($response->getData()['valid']);
            self::assertSame('pdf', $response->getData()['documentType']);
        } finally {
            unlink($path);
        }
    }

    public function testRejectsPdfWhenOptionalSupportIsUnavailable(): void {
        $path = tempnam(sys_get_temp_dir(), 'kosit-upload-');
        self::assertIsString($path);
        file_put_contents($path, '%PDF-fake');

        try {
            $extractor = $this->createStub(PdfXmlExtractor::class);
            $extractor->method('extract')->willThrowException(
                new DocumentInputException('PDF-Eingabe ist auf diesem Server nicht verfügbar.', 415)
            );

            $response = $this->controllerWithUpload([
                'name' => 'invoice.pdf',
                'tmp_name' => $path,
                'error' => UPLOAD_ERR_OK,
            ], extractor: $extractor)->validate();

            self::assertSame(415, $response->getStatus());
            self::assertSame(
                'PDF-Eingabe ist auf diesem Server nicht verfügbar.',
                $response->getData()['message']
            );
        } finally {
            unlink($path);
        }
    }

    public function testValidationExceptionBecomesServiceUnavailableWithoutDetails(): void {
        $service = $this->createMock(KoSITValidatorService::class);
        $service->method('validateXml')->willThrowException(new ValidationException('secret path'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $response = $this->controllerWithXml('<invoice/>', $service, $logger)->validate();

        self::assertSame(503, $response->getStatus());
        self::assertSame('Validator ist momentan nicht verfügbar', $response->getData()['message']);
        self::assertStringNotContainsString('secret', $response->getData()['message']);
    }

    public function testUnexpectedExceptionBecomesInternalServerError(): void {
        $service = $this->createMock(KoSITValidatorService::class);
        $service->method('validateXml')->willThrowException(new \RuntimeException('failure'));

        $response = $this->controllerWithXml('<invoice/>', $service)->validate();

        self::assertSame(500, $response->getStatus());
        self::assertSame('Validierung fehlgeschlagen', $response->getData()['message']);
    }

    private function controllerWithXml(
        string $xml,
        ?KoSITValidatorService $service = null,
        ?LoggerInterface $logger = null
    ): ValidationController {
        $request = $this->createStub(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => $key === 'xml' ? $xml : $default
        );

        return new ValidationController(
            'kosit_validator',
            $request,
            $service ?? $this->createStub(KoSITValidatorService::class),
            $this->createStub(PdfXmlExtractor::class),
            $logger ?? $this->createStub(LoggerInterface::class)
        );
    }

    /** @param array<string, mixed> $file */
    private function controllerWithUpload(
        array $file,
        ?KoSITValidatorService $service = null,
        ?PdfXmlExtractor $extractor = null
    ): ValidationController {
        $request = $this->createStub(IRequest::class);
        $request->method('getUploadedFile')->willReturn($file);

        return new ValidationController(
            'kosit_validator',
            $request,
            $service ?? $this->createStub(KoSITValidatorService::class),
            $extractor ?? $this->createStub(PdfXmlExtractor::class),
            $this->createStub(LoggerInterface::class)
        );
    }
}
