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
        $status = ['installed' => true, 'latestVersion' => '2.0.0'];
        $adminService = $this->createMock(ValidatorAdminService::class);
        $adminService->expects(self::once())->method('getStatus')->with(true)->willReturn($status);

        $response = $this->controller([], $adminService)->status();

        self::assertSame(200, $response->getStatus());
        self::assertSame([
            'success' => true,
            'status' => $status,
        ], $response->getData());
    }

    public function testStatusReturnsScenarioWhenRequested(): void {
        $scenario = [
            'path' => '/config/scenario.xml',
            'content' => '<scenarios/>',
            'checksum' => str_repeat('a', 64),
            'writable' => true,
        ];
        $adminService = $this->createMock(ValidatorAdminService::class);
        $adminService->expects(self::once())->method('readScenario')->willReturn($scenario);
        $adminService->expects(self::never())->method('getStatus');

        $response = $this->controller(['scenario' => '1'], $adminService)->status();

        self::assertSame(200, $response->getStatus());
        self::assertSame(['success' => true, 'scenario' => $scenario], $response->getData());
    }

    public function testStatusChecksOnlyOptionalPdfSupportWhenRequested(): void {
        $pdfSupport = [
            'available' => true,
            'pythonAvailable' => true,
            'pythonVersion' => '3.13.5',
            'pymupdfAvailable' => true,
            'pymupdfVersion' => '1.26.4',
            'reason' => null,
        ];
        $adminService = $this->createMock(ValidatorAdminService::class);
        $adminService->expects(self::once())
            ->method('getPdfSupportStatus')
            ->willReturn($pdfSupport);
        $adminService->expects(self::never())->method('getStatus');

        $response = $this->controller(['pdf' => '1'], $adminService)->status();

        self::assertSame(200, $response->getStatus());
        self::assertSame([
            'success' => true,
            'pdfSupport' => $pdfSupport,
        ], $response->getData());
    }

    public function testScenarioReadFailureIsLoggedAndReturned(): void {
        $exception = new AdministrationException('Szenario nicht lesbar');
        $adminService = $this->createMock(ValidatorAdminService::class);
        $adminService->method('readScenario')->willThrowException($exception);
        $logger = $this->loggerExpecting(
            'KoSIT scenario file could not be read: {message}',
            $exception
        );

        $response = $this->controller(['scenario' => '1'], $adminService, logger: $logger)->status();

        self::assertSame(500, $response->getStatus());
        self::assertSame([
            'success' => false,
            'message' => 'Szenario nicht lesbar',
        ], $response->getData());
    }

    public function testInstallReturnsInstalledStatus(): void {
        $status = ['installed' => true, 'installedVersion' => '2.0.0'];
        $adminService = $this->createMock(ValidatorAdminService::class);
        $adminService->expects(self::once())->method('installLatest')->willReturn($status);

        $response = $this->controller([], $adminService)->install();

        self::assertSame(200, $response->getStatus());
        self::assertSame([
            'success' => true,
            'message' => 'Der KoSIT-Validator wurde erfolgreich installiert.',
            'status' => $status,
        ], $response->getData());
    }

    public function testInstallFailureIsLoggedAndReturned(): void {
        $exception = new AdministrationException('SHA-256-Prüfung fehlgeschlagen');
        $adminService = $this->createMock(ValidatorAdminService::class);
        $adminService->method('installLatest')->willThrowException($exception);
        $logger = $this->loggerExpecting(
            'KoSIT validator installation failed: {message}',
            $exception
        );

        $response = $this->controller([], $adminService, logger: $logger)->install();

        self::assertSame(500, $response->getStatus());
        self::assertSame([
            'success' => false,
            'message' => 'SHA-256-Prüfung fehlgeschlagen',
        ], $response->getData());
    }

    public function testSavePathsPassesAllValuesAndReturnsFreshStatus(): void {
        $paths = [
            'javaBinary' => '/usr/bin/java',
            'pythonBinary' => '/usr/bin/python3',
            'validatorJar' => '/opt/kosit/validator.jar',
            'repositoryPath' => '/opt/kosit/repository',
            'scenarioFile' => '/opt/kosit/scenario.xml',
            'tempDirectory' => '/tmp/kosit',
        ];
        $configService = $this->createMock(ValidatorConfigService::class);
        $configService->expects(self::once())->method('savePaths')->with($paths);
        $adminService = $this->createMock(ValidatorAdminService::class);
        $adminService->expects(self::once())->method('getStatus')->with(true)->willReturn(['saved' => true]);

        $response = $this->controller($paths, $adminService, $configService)->savePaths();

        self::assertSame(200, $response->getStatus());
        self::assertSame([
            'success' => true,
            'message' => 'Pfade wurden gespeichert.',
            'status' => ['saved' => true],
        ], $response->getData());
    }

    public function testInvalidPathReturnsBadRequest(): void {
        $configService = $this->createMock(ValidatorConfigService::class);
        $configService->method('savePaths')->willThrowException(
            new \InvalidArgumentException('Java: ein absoluter Pfad ist erforderlich.')
        );
        $adminService = $this->createMock(ValidatorAdminService::class);
        $adminService->expects(self::never())->method('getStatus');

        $response = $this->controller([], $adminService, $configService)->savePaths();

        self::assertSame(400, $response->getStatus());
        self::assertSame([
            'success' => false,
            'message' => 'Java: ein absoluter Pfad ist erforderlich.',
        ], $response->getData());
    }

    public function testUnexpectedPathFailureIsLoggedButHiddenFromResponse(): void {
        $exception = new \RuntimeException('secret storage detail');
        $configService = $this->createMock(ValidatorConfigService::class);
        $configService->method('savePaths')->willThrowException($exception);
        $logger = $this->loggerExpecting(
            'KoSIT path configuration failed: {message}',
            $exception
        );

        $response = $this->controller([], configService: $configService, logger: $logger)->savePaths();

        self::assertSame(500, $response->getStatus());
        self::assertSame('Pfade konnten nicht gespeichert werden.', $response->getData()['message']);
        self::assertStringNotContainsString('secret', $response->getData()['message']);
    }

    public function testSaveScenarioUsesActionRoutingAndReturnsNewChecksum(): void {
        $savedScenario = [
            'path' => '/config/scenario.xml',
            'content' => '<scenarios id="new"/>',
            'checksum' => str_repeat('b', 64),
            'writable' => true,
        ];
        $adminService = $this->createMock(ValidatorAdminService::class);
        $adminService->expects(self::once())
            ->method('saveScenario')
            ->with('<scenarios id="new"/>', str_repeat('a', 64));
        $adminService->expects(self::once())->method('readScenario')->willReturn($savedScenario);
        $configService = $this->createMock(ValidatorConfigService::class);
        $configService->expects(self::never())->method('savePaths');

        $response = $this->controller([
            'action' => 'saveScenario',
            'content' => '<scenarios id="new"/>',
            'checksum' => str_repeat('a', 64),
        ], $adminService, $configService)->savePaths();

        self::assertSame(200, $response->getStatus());
        self::assertSame([
            'success' => true,
            'message' => 'Szenario-Datei wurde gespeichert.',
            'scenario' => $savedScenario,
        ], $response->getData());
    }

    public function testInvalidScenarioReturnsBadRequestWithoutReloading(): void {
        $adminService = $this->createMock(ValidatorAdminService::class);
        $adminService->method('saveScenario')->willThrowException(
            new \InvalidArgumentException('Ungültiges XML')
        );
        $adminService->expects(self::never())->method('readScenario');

        $response = $this->controller([
            'action' => 'saveScenario',
            'content' => '<broken>',
            'checksum' => str_repeat('a', 64),
        ], $adminService)->savePaths();

        self::assertSame(400, $response->getStatus());
        self::assertSame('Ungültiges XML', $response->getData()['message']);
    }

    public function testScenarioConflictIsLoggedAndReturned(): void {
        $exception = new AdministrationException('Szenario wurde zwischenzeitlich geändert');
        $adminService = $this->createMock(ValidatorAdminService::class);
        $adminService->method('saveScenario')->willThrowException($exception);
        $logger = $this->loggerExpecting(
            'KoSIT scenario file could not be saved: {message}',
            $exception
        );

        $response = $this->controller([
            'action' => 'saveScenario',
            'content' => '<scenarios/>',
            'checksum' => str_repeat('a', 64),
        ], $adminService, logger: $logger)->savePaths();

        self::assertSame(500, $response->getStatus());
        self::assertSame('Szenario wurde zwischenzeitlich geändert', $response->getData()['message']);
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
        $logger->expects(self::once())
            ->method('error')
            ->with($message, self::callback(static function (array $context) use ($exception): bool {
                return ($context['message'] ?? null) === $exception->getMessage()
                    && ($context['exception'] ?? null) === $exception;
            }));

        return $logger;
    }
}
