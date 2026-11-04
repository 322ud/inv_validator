<?php

declare(strict_types=1);

namespace OCA\KoSITValidator\Tests\Unit\Controller;

use OCA\KoSITValidator\Controller\PageController;
use OCA\KoSITValidator\Service\TextEditorLoader;
use OCA\KoSITValidator\Service\ValidatorAdminService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
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
            ->method('getStatus')
            ->with(true)
            ->willReturn($status);
        $adminService->expects(self::never())->method('installLatest');
        $textEditorLoader = new TextEditorLoader(
            $this->createMock(IEventDispatcher::class)
        );

        $controller = new PageController(
            'kosit_validator',
            $this->createMock(IRequest::class),
            $adminService,
            $textEditorLoader,
            $this->createMock(LoggerInterface::class)
        );

        $response = $controller->index();

        self::assertSame(['status' => $status], $response->getParams());
    }

    #[DataProvider('availableUpdates')]
    public function testIndexInstallsAvailableUpdatesBeforeRendering(bool $validator, bool $configuration): void
    {
        $updatedStatus = [
            'validator' => ['installedVersion' => '1.6.2', 'updateAvailable' => false],
            'configuration' => ['installedVersion' => '2026-01-31', 'updateAvailable' => false],
        ];
        $adminService = $this->createMock(ValidatorAdminService::class);
        $adminService->expects(self::once())->method('getStatus')->with(true)->willReturn([
            'validator' => ['updateAvailable' => $validator],
            'configuration' => ['updateAvailable' => $configuration],
        ]);
        $adminService->expects(self::once())->method('installLatest')->willReturn($updatedStatus);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $controller = new PageController(
            'kosit_validator',
            $this->createMock(IRequest::class),
            $adminService,
            new TextEditorLoader($this->createMock(IEventDispatcher::class)),
            $logger
        );

        self::assertSame(['status' => $updatedStatus], $controller->index()->getParams());
    }

    public static function availableUpdates(): iterable
    {
        yield 'validator only' => [true, false];
        yield 'configuration only' => [false, true];
        yield 'both components' => [true, true];
    }

    public function testIndexRemainsAvailableWhenAutomaticUpdateFails(): void
    {
        $installedStatus = [
            'validator' => ['installedVersion' => '1.6.1'],
            'configuration' => ['installedVersion' => '2025-07-10'],
        ];
        $adminService = $this->createMock(ValidatorAdminService::class);
        $adminService->expects(self::exactly(2))->method('getStatus')->willReturnCallback(
            static fn (bool $checkUpdates): array => $checkUpdates ? [
                'validator' => ['updateAvailable' => false],
                'configuration' => ['updateAvailable' => true],
            ] : $installedStatus
        );
        $error = new \RuntimeException('Download failed');
        $adminService->expects(self::once())->method('installLatest')->willThrowException($error);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'Automatic KoSIT update failed: {message}',
            ['message' => 'Download failed', 'exception' => $error]
        );
        $controller = new PageController(
            'kosit_validator',
            $this->createMock(IRequest::class),
            $adminService,
            new TextEditorLoader($this->createMock(IEventDispatcher::class)),
            $logger
        );

        self::assertSame(['status' => $installedStatus], $controller->index()->getParams());
    }
}
