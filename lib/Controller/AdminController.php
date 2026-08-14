<?php

declare(strict_types=1);

namespace OCA\KoSITValidator\Controller;

use OCA\KoSITValidator\Service\ValidatorAdminService;
use OCA\KoSITValidator\Service\ValidatorConfigService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class AdminController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private ValidatorAdminService $adminService,
        private ValidatorConfigService $configService,
        private LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
    }

    public function status(): DataResponse
    {
        if ($this->request->getParam('pdf') === '1') {
            return new DataResponse([
                'success' => true,
                'pdfSupport' => $this->adminService->getPdfSupportStatus(),
            ]);
        }

        return new DataResponse([
            'success' => true,
            'status' => $this->adminService->getStatus(true),
        ]);
    }

    public function install(): DataResponse
    {
        try {
            return new DataResponse([
                'success' => true,
                'message' => 'KoSIT-Validator und XRechnung-Konfiguration wurden erfolgreich installiert.',
                'status' => $this->adminService->installLatest(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('KoSIT installation failed: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return new DataResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function savePaths(): DataResponse
    {
        try {
            $this->configService->savePaths([
                'javaBinary' => $this->request->getParam('javaBinary'),
                'pythonBinary' => $this->request->getParam('pythonBinary'),
                'validatorJar' => $this->request->getParam('validatorJar'),
                'repositoryPath' => $this->request->getParam('repositoryPath'),
                'tempDirectory' => $this->request->getParam('tempDirectory'),
            ]);

            return new DataResponse([
                'success' => true,
                'message' => 'Pfade wurden gespeichert.',
                'status' => $this->adminService->getStatus(true),
            ]);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Throwable $e) {
            $this->logger->error('KoSIT path configuration failed: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return new DataResponse([
                'success' => false,
                'message' => 'Pfade konnten nicht gespeichert werden.',
            ], 500);
        }
    }
}
