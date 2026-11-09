<?php

declare(strict_types=1);

namespace OCA\INValidator\Tests\Unit\Controller;

use OCA\INValidator\Controller\PageController;
use OCA\INValidator\Service\TextEditorLoader;
use OCA\INValidator\Service\ValidatorAdminService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class PageControllerTest extends TestCase
{
    public function testIndexDoesNotInstallWhenNoUpdatesAreAvailable(): void
    {
        $status = [
            'validator' => ['updateAvailable' => false],
            'configuration' => ['updateAvailable' => false],
        ];
        $adminService = $this->createMock(ValidatorAdminService::class);
        $adminService->expects(self::once())
            ->method('updateOnStart')
            ->willReturn($status);
        $adminService->expects(self::never())->method('installLatest');
        $textEditorLoader = new TextEditorLoader(
            $this->createMock(IEventDispatcher::class)
        );

        $controller = new PageController(
            'inv_validator',
            $this->createMock(IRequest::class),
            $adminService,
            $textEditorLoader,
            $this->createMock(LoggerInterface::class)
        );

        $response = $controller->index();

        self::assertSame(['status' => $status], $response->getParams());
    }

    public function testIndexRendersStatusAfterStartupUpdates(): void
    {
        $updatedStatus = [
            'validator' => ['installedVersion' => '1.6.2', 'updateAvailable' => false],
            'configuration' => ['installedVersion' => '2026-01-31', 'updateAvailable' => false],
        ];
        $adminService = $this->createMock(ValidatorAdminService::class);
        $adminService->expects(self::once())->method('updateOnStart')->willReturn($updatedStatus);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $controller = new PageController(
            'inv_validator',
            $this->createMock(IRequest::class),
            $adminService,
            new TextEditorLoader($this->createMock(IEventDispatcher::class)),
            $logger
        );

        self::assertSame(['status' => $updatedStatus], $controller->index()->getParams());
    }

    public function testIndexRemainsAvailableWhenAutomaticUpdateFails(): void
    {
        $installedStatus = [
            'validator' => ['installedVersion' => '1.6.1'],
            'configuration' => ['installedVersion' => '2025-07-10'],
        ];
        $adminService = $this->createMock(ValidatorAdminService::class);
        $adminService->expects(self::once())->method('getStatus')->with(false)->willReturn($installedStatus);
        $error = new \RuntimeException('Download failed');
        $adminService->expects(self::once())->method('updateOnStart')->willThrowException($error);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'Automatic KoSIT update failed: {message}',
            ['message' => 'Download failed', 'exception' => $error]
        );
        $controller = new PageController(
            'inv_validator',
            $this->createMock(IRequest::class),
            $adminService,
            new TextEditorLoader($this->createMock(IEventDispatcher::class)),
            $logger
        );

        self::assertSame(['status' => $installedStatus], $controller->index()->getParams());
    }
}
