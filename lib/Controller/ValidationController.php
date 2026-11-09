<?php

declare(strict_types=1);

namespace OCA\INValidator\Controller;

use OCA\INValidator\Exception\DocumentInputException;
use OCA\INValidator\Exception\ValidationException;
use OCA\INValidator\Service\KoSITValidatorService;
use OCA\INValidator\Service\PdfXmlExtractor;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class ValidationController extends Controller
{
    private const MAX_XML_BYTES = 5 * 1024 * 1024;
    private const MAX_PDF_BYTES = 20 * 1024 * 1024;

    public function __construct(
        string $appName,
        IRequest $request,
        private KoSITValidatorService $validatorService,
        private PdfXmlExtractor $pdfXmlExtractor,
        private LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function validate(): DataResponse
    {
        try {
            [$xml, $documentType] = $this->readInputDocument();

            if (!$this->isWellFormedXml($xml)) {
                throw new DocumentInputException('Ungültiger XML-Inhalt');
            }

            $result = $this->validatorService->validateXml($xml);

            return new DataResponse([
                'success' => true,
                'valid' => $result->isValid(),
                'documentType' => $documentType,
                'exitCode' => $result->getExitCode(),
                'reportHtml' => $result->getReportHtml(),
            ]);
        } catch (DocumentInputException $e) {
            return new DataResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getHttpStatus());
        } catch (ValidationException $e) {
            $this->logger->error('KoSIT validation failed: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return new DataResponse([
                'success' => false,
                'message' => 'Validator ist momentan nicht verfügbar',
            ], 503);
        } catch (\Throwable $e) {
            $this->logger->error('Unexpected KoSIT validation error', [
                'exception' => $e,
            ]);

            return new DataResponse([
                'success' => false,
                'message' => 'Validierung fehlgeschlagen',
            ], 500);
        }
    }

    /** @return array{string, 'xml'|'pdf'} */
    private function readInputDocument(): array
    {
        $uploadedFile = $this->request->getUploadedFile('document');

        if (is_array($uploadedFile)) {
            return $this->readUploadedDocument($uploadedFile);
        }

        // Keep the original XML field available for API clients during migration.
        $xml = (string)$this->request->getParam('xml', '');
        if ($xml === '') {
            throw new DocumentInputException('Keine XML- oder PDF-Datei übergeben.');
        }
        if (strlen($xml) > self::MAX_XML_BYTES) {
            throw new DocumentInputException('XML-Datei ist zu groß', 413);
        }

        return [$xml, 'xml'];
    }

    /**
     * @param array<string, mixed> $uploadedFile
     * @return array{string, 'xml'|'pdf'}
     */
    private function readUploadedDocument(array $uploadedFile): array
    {
        $error = $uploadedFile['error'] ?? UPLOAD_ERR_OK;
        if (!is_int($error) || $error !== UPLOAD_ERR_OK) {
            $status = in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 413
                : 400;
            throw new DocumentInputException('Datei-Upload ist fehlgeschlagen.', $status);
        }

        $path = $uploadedFile['tmp_name'] ?? null;
        if (!is_string($path) || !is_file($path) || !is_readable($path)) {
            throw new DocumentInputException('Hochgeladene Datei konnte nicht gelesen werden.');
        }

        $size = filesize($path);
        if ($size === false) {
            throw new DocumentInputException('Dateigröße konnte nicht ermittelt werden.');
        }
        if ($size > self::MAX_PDF_BYTES) {
            throw new DocumentInputException('Datei ist zu groß. Maximal erlaubt sind 20 MB.', 413);
        }

        $handle = fopen($path, 'rb');
        $header = $handle === false ? false : fread($handle, 5);
        if (is_resource($handle)) {
            fclose($handle);
        }

        if ($header === '%PDF-') {
            return [$this->pdfXmlExtractor->extract($path), 'pdf'];
        }

        $name = $uploadedFile['name'] ?? '';
        if (is_string($name) && strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'pdf') {
            throw new DocumentInputException('Ungültiger PDF-Inhalt.');
        }
        if ($size > self::MAX_XML_BYTES) {
            throw new DocumentInputException('XML-Datei ist zu groß.', 413);
        }

        $xml = file_get_contents($path);
        if ($xml === false || $xml === '') {
            throw new DocumentInputException('Keine XML- oder PDF-Datei übergeben.');
        }

        return [$xml, 'xml'];
    }

    private function isWellFormedXml(string $xml): bool
    {
        if (trim($xml) === '') {
            return false;
        }

        if (stripos($xml, '<!DOCTYPE') !== false) {
            return false;
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $document = new \DOMDocument();

        $loaded = $document->loadXML(
            $xml,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );

        $hasErrors = count(libxml_get_errors()) > 0;

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded && !$hasErrors && $document->documentElement !== null;
    }
}
