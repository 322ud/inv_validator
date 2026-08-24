<?php

declare(strict_types=1);

namespace OCA\KoSITValidator\Tests\Unit\Controller;

use OCA\KoSITValidator\Controller\AdminController;
use OCA\KoSITValidator\Exception\AdministrationException;
use OCA\KoSITValidator\Service\ValidatorAdminService;
use OCA\KoSITValidator\Service\ValidatorConfigService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class AdminControllerTest extends TestCase {
    public function testStatusReturnsUpdateStatus(): void {
        $status = ['validator' => ['installed' => true]];
        $adminService = $this->createMock(ValidatorAdminService::class);
        $adminService->expects(self::once())->method('getStatus')->with(true)->willReturn($status);

        $response = $this->controller([], $adminService)->status();

        self::assertSame(200, $response->getStatus());
        self::assertSame(['success' => true, 'status' => $status], $response->getData());
    }

    public function testStatusChecksOnlyOptionalPdfSupportWhenRequested(): void {
        $pdfSupport = ['available' => true, 'reason' => null];
        $adminService = $this->createMock(ValidatorAdminService::class);
        $adminService->expects(self::once())->method('getPdfSupportStatus')->willReturn($pdfSupport);
        $adminService->expects(self::never())->method('getStatus');

        $response = $this->controller(['pdf' => '1'], $adminService)->status();

        self::assertSame(['success' => true, 'pdfSupport' => $pdfSupport], $response->getData());
    }

    public function testInstallReturnsInstalledStatus(): void {
        $status = ['validator' => ['installed' => true], 'configuration' => ['installed' => true]];
        $adminService = $this->createMock(ValidatorAdminService::class);
        $adminService->expects(self::once())->method('installLatest')->willReturn($status);

        $response = $this->controller([], $adminService)->install();

        self::assertSame(200, $response->getStatus());
        self::assertSame('KoSIT-Validator und XRechnung-Konfiguration wurden erfolgreich installiert.', $response->getData()['message']);
        self::assertSame($status, $response->getData()['status']);
    }

    public function testInstallFailureIsLoggedAndReturned(): void {
        $exception = new AdministrationException('SHA-256-Prüfung fehlgeschlagen');
        $adminService = $this->createMock(ValidatorAdminService::class);
        $adminService->method('installLatest')->willThrowException($exception);
        $logger = $this->loggerExpecting('KoSIT installation failed: {message}', $exception);

        $response = $this->controller([], $adminService, logger: $logger)->install();

        self::assertSame(500, $response->getStatus());
        self::assertSame($exception->getMessage(), $response->getData()['message']);
    }

    public function testSavePathsPassesEditableValuesAndReturnsFreshStatus(): void {
        $paths = [
            'javaBinary' => '/usr/bin/java',
            'pythonBinary' => '/usr/bin/python3',
        ];
        $requestPaths = $paths + [
            'validatorJar' => '/tmp/ignored-validator.jar',
            'repositoryPath' => '/tmp/ignored-repository',
            'tempDirectory' => '/tmp/ignored-temp',
        ];
        $configService = $this->createMock(ValidatorConfigService::class);
        $configService->expects(self::once())->method('savePaths')->with($paths);
        $adminService = $this->createMock(ValidatorAdminService::class);
        $adminService->expects(self::once())->method('getStatus')->with(true)->willReturn(['saved' => true]);

        $response = $this->controller($requestPaths, $adminService, $configService)->savePaths();

        self::assertSame(200, $response->getStatus());
        self::assertSame(['success' => true, 'message' => 'Pfade wurden gespeichert.', 'status' => ['saved' => true]], $response->getData());
    }

    public function testInvalidPathReturnsBadRequest(): void {
        $configService = $this->createMock(ValidatorConfigService::class);
        $configService->method('savePaths')->willThrowException(new \InvalidArgumentException('Ungültiger Pfad'));

        $response = $this->controller([], configService: $configService)->savePaths();

        self::assertSame(400, $response->getStatus());
        self::assertSame('Ungültiger Pfad', $response->getData()['message']);
    }

    /** @param array<string, mixed> $parameters */
    private function controller(
        array $parameters,
        ?ValidatorAdminService $adminService = null,
        ?ValidatorConfigService $configService = null,
        ?LoggerInterface $logger = null
    ): AdminController {
        $request = $this->createStub(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => $parameters[$key] ?? $default
        );
        return new AdminController(
            'kosit_validator',
            $request,
            $adminService ?? $this->createStub(ValidatorAdminService::class),
            $configService ?? $this->createStub(ValidatorConfigService::class),
            $logger ?? $this->createStub(LoggerInterface::class)
        );
    }

    private function loggerExpecting(string $message, \Throwable $exception): LoggerInterface {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            $message,
            self::callback(static fn (array $context): bool => ($context['exception'] ?? null) === $exception)
        );
        return $logger;
    }
}
