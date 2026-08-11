<?php

declare(strict_types=1);

namespace OCA\KoSITValidator\Controller;

use OCA\KoSITValidator\Exception\ValidationException;
use OCA\KoSITValidator\Service\KoSITValidatorService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class ValidationController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private KoSITValidatorService $validatorService,
        private LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function validate(): DataResponse
    {
        $xml = (string)$this->request->getParam('xml', '');

        if ($xml === '') {
            return new DataResponse([
                'success' => false,
                'message' => 'Kein XML übergeben',
            ], 400);
        }

        if (strlen($xml) > 5 * 1024 * 1024) {
            return new DataResponse([
                'success' => false,
                'message' => 'XML-Datei ist zu groß',
            ], 413);
        }

        if (!$this->isWellFormedXml($xml)) {
            return new DataResponse([
                'success' => false,
                'message' => 'Ungültiger XML-Inhalt',
            ], 400);
        }

        try {
            $result = $this->validatorService->validateXml($xml);

            return new DataResponse([
                'success' => true,
                'valid' => $result->isValid(),
                'exitCode' => $result->getExitCode(),
                'reportHtml' => $result->getReportHtml(),
            ]);
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
