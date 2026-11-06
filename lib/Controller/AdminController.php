<?php

declare(strict_types=1);

namespace OCA\KoSITValidator\Controller;

use OCA\KoSITValidator\Service\ValidatorAdminService;
use OCA\KoSITValidator\Service\ComponentRemovalService;
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
            'status' => $this->adminService->getAdminStatus(),
        ]);
    }

    public function install(): DataResponse
    {
        $component = $this->request->getParam('component', 'both');
        if (!in_array($component, ['validator', 'configuration', 'both'], true)) {
            return new DataResponse(['success' => false, 'message' => 'Ungültige Komponente.'], 400);
        }
        try {
            $this->adminService->installLatest($component !== 'configuration', $component !== 'validator');
            return new DataResponse([
                'success' => true,
                'message' => 'Komponentenaktion abgeschlossen.',
                'status' => $this->adminService->getAdminStatus(),
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

    public function removeComponent(): DataResponse
    {
        $component = $this->request->getParam('component');
        $confirmed = $this->request->getParam('confirmed');
        $token = $this->request->getParam('recordToken');
        if (!in_array($component, ['validator', 'configuration'], true) || $confirmed !== '1' || !is_string($token)) {
            return new DataResponse(['success' => false, 'message' => 'Komponente zum Entfernen auswählen.'], 400);
        }
        try {
            (new ComponentRemovalService($this->configService))->remove($component, true, $token);
            return new DataResponse([
                'success' => true,
                'message' => 'Die bestätigte Komponente wurde entfernt.',
                'status' => $this->adminService->getAdminStatus(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Component removal failed: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
            return new DataResponse(['success' => false, 'message' => $e->getMessage()], 409);
        }
    }

    public function saveSettings(): DataResponse
    {
        $validator = $this->request->getParam('validator');
        $configuration = $this->request->getParam('configuration');
        if (!in_array($validator, ['yes', 'no'], true) || !in_array($configuration, ['yes', 'no'], true)) {
            return new DataResponse(['success' => false, 'message' => 'Ungültige Update-Einstellungen.'], 400);
        }
        $this->configService->saveAutoUpdates($validator === 'yes', $configuration === 'yes');
        return new DataResponse(['success' => true, 'message' => 'Einstellungen wurden gespeichert.']);
    }

    public function savePaths(): DataResponse
    {
        try {
            $this->configService->savePaths([
                'javaBinary' => $this->request->getParam('javaBinary'),
                'pythonBinary' => $this->request->getParam('pythonBinary'),
            ]);

            return new DataResponse([
                'success' => true,
                'message' => 'Pfade wurden gespeichert.',
                'status' => $this->adminService->getAdminStatus(),
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
