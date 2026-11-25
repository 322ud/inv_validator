<?php

declare(strict_types=1);

namespace OCA\INValidator\Settings;

use OCA\INValidator\Service\ValidatorAdminService;
use OCA\INValidator\Service\TextEditorLoader;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\IURLGenerator;

class Admin implements ISettings
{
    public function __construct(
        private ValidatorAdminService $adminService,
        private TextEditorLoader $textEditorLoader,
        private IURLGenerator $urls
    ) {
    }

    public function getForm(): TemplateResponse
    {
        $this->textEditorLoader->load();

        return new TemplateResponse('inv_validator', 'admin', [
            'status' => $this->adminService->getAdminStatus(),
            'readmeUrl' => $this->urls->linkToRoute('inv_validator.documentation.index'),
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
