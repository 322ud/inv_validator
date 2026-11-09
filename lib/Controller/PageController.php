<?php

declare(strict_types=1);

namespace OCA\INValidator\Controller;

use OCA\INValidator\Service\ValidatorAdminService;
use OCA\INValidator\Service\TextEditorLoader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class PageController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private ValidatorAdminService $adminService,
        private TextEditorLoader $textEditorLoader,
        private LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse
    {
        $this->textEditorLoader->load();
        try {
            $status = $this->adminService->updateOnStart();
        } catch (\Throwable $e) {
            $this->logger->error('Automatic KoSIT update failed: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
            $status = $this->adminService->getStatus(false);
        }

        return new TemplateResponse(
            'inv_validator',
            'index',
            [
                'status' => $status,
            ]
        );
    }
}
