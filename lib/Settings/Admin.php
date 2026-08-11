<?php

declare(strict_types=1);

namespace OCA\KoSITValidator\Settings;

use OCA\KoSITValidator\Service\ValidatorAdminService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

class Admin implements ISettings
{
    public function __construct(
        private ValidatorAdminService $adminService
    ) {
    }

    public function getForm(): TemplateResponse
    {
        return new TemplateResponse('kosit_validator', 'admin', [
            'status' => $this->adminService->getStatus(true),
        ]);
    }

    public function getSection(): string
    {
        return 'additional';
    }

    public function getPriority(): int
    {
        return 50;
    }
}
