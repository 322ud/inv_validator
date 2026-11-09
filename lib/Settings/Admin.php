<?php

declare(strict_types=1);

namespace OCA\INValidator\Settings;

use OCA\INValidator\Service\ValidatorAdminService;
use OCA\INValidator\Service\TextEditorLoader;
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

        return new TemplateResponse('inv_validator', 'admin', [
            'status' => $this->adminService->getAdminStatus(),
        ]);
    }

    public function getSection(): string
    {
        return 'inv_validator';
    }

    public function getPriority(): int
    {
        return 50;
    }
}
