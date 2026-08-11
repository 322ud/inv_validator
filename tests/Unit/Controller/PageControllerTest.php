<?php

declare(strict_types=1);

namespace OCA\KoSITValidator\Tests\Unit\Controller;

use OCA\KoSITValidator\Controller\PageController;
use OCA\KoSITValidator\Service\ValidatorAdminService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

final class PageControllerTest extends TestCase
{
    public function testIndexChecksForUpdatesAndPassesStatusToTemplate(): void
    {
        $status = [
            'installedVersion' => '1.5.0',
            'latestVersion' => '2.0.0',
            'updateAvailable' => true,
            'releaseUrl' => 'https://github.com/itplr-kosit/validator/releases',
        ];
        $adminService = $this->createMock(ValidatorAdminService::class);
        $adminService->expects(self::once())
            ->method('getStatus')
            ->with(true)
            ->willReturn($status);

        $controller = new PageController(
            'kosit_validator',
            $this->createMock(IRequest::class),
            $adminService
        );

        $response = $controller->index();

        self::assertSame(['status' => $status], $response->getParams());
    }
}
