<?php

declare(strict_types=1);

namespace OCA\KoSITValidator\Settings;

use OCA\KoSITValidator\Service\ValidatorAdminService;
use OCA\KoSITValidator\Service\TextEditorLoader;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

class Admin implements ISettings
{
    public function __construct(
        private ValidatorAdminService $adminService,
        private TextEditorLoader $textEditorLoader
    ) {
    }

    public function getForm(): TemplateResponse
    {
        $this->textEditorLoader->load();

        return new TemplateResponse('kosit_validator', 'admin', [
            'status' => $this->adminService->getAdminStatus(),
        ]);
    }

    public function getSection(): string
    {
        return 'kosit_validator';
    }

    public function getPriority(): int
    {
        return 50;
    }
}
