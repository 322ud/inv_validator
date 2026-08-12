<?php

declare(strict_types=1);

namespace OCA\KoSITValidator\Controller;

use OCA\KoSITValidator\Service\ValidatorAdminService;
use OCA\KoSITValidator\Service\TextEditorLoader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

class PageController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private ValidatorAdminService $adminService,
        private TextEditorLoader $textEditorLoader
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse
    {
        $this->textEditorLoader->load();

        return new TemplateResponse(
            'kosit_validator',
            'index',
            [
                'status' => $this->adminService->getStatus(true),
            ]
        );
    }
}
