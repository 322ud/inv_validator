<?php

declare(strict_types=1);

namespace OCA\KoSITValidator\Tests\Nextcloud;

use OCA\KoSITValidator\AppInfo\Application;
use OCA\KoSITValidator\Controller\AdminController;
use OCA\KoSITValidator\Controller\PageController;
use OCA\KoSITValidator\Controller\ValidationController;
use OCA\KoSITValidator\Service\KoSITValidatorService;
use OCA\KoSITValidator\Service\ValidatorAdminService;
use OCA\KoSITValidator\Service\ValidatorConfigService;
use OCA\KoSITValidator\Settings\Admin;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\IURLGenerator;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FrameworkIntegrationTest extends TestCase
{
    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->application = new Application();
    }

    public function testAppIsEnabledAndCoreServicesResolveFromRealContainer(): void
    {
        $appManager = Server::get(IAppManager::class);
        self::assertTrue($appManager->isEnabledForUser(Application::APP_ID));

        $container = $this->application->getContainer();
        self::assertInstanceOf(ValidatorConfigService::class, $container->get(ValidatorConfigService::class));
        self::assertInstanceOf(KoSITValidatorService::class, $container->get(KoSITValidatorService::class));
        self::assertInstanceOf(ValidatorAdminService::class, $container->get(ValidatorAdminService::class));
        self::assertInstanceOf(AdminController::class, $container->get(AdminController::class));
        self::assertInstanceOf(ValidationController::class, $container->get(ValidationController::class));

        $settings = $container->get(Admin::class);
        self::assertSame('additional', $settings->getSection());
        self::assertSame(50, $settings->getPriority());
    }

    public function testNamedRoutesAreRegistered(): void
    {
        $urlGenerator = Server::get(IURLGenerator::class);

        self::assertStringContainsString(
            '/apps/kosit_validator/',
            $urlGenerator->linkToRoute('kosit_validator.page.index')
        );
        self::assertStringEndsWith(
            '/apps/kosit_validator/validate',
            $urlGenerator->linkToRoute('kosit_validator.validation.validate')
        );
        self::assertStringEndsWith(
            '/apps/kosit_validator/admin/status',
            $urlGenerator->linkToRoute('kosit_validator.admin.status')
        );
        self::assertStringEndsWith(
            '/apps/kosit_validator/admin/install',
            $urlGenerator->linkToRoute('kosit_validator.admin.install')
        );
        self::assertStringEndsWith(
            '/apps/kosit_validator/admin/paths',
            $urlGenerator->linkToRoute('kosit_validator.admin.savePaths')
        );
    }

    public function testPageControllerRendersTheRealTemplate(): void
    {
        $controller = $this->application->getContainer()->get(PageController::class);
        $response = $controller->index();

        self::assertSame('kosit_validator', $response->getApp());
        self::assertSame('index', $response->getTemplateName());
        self::assertStringContainsString('id="kosit-validator"', $response->render());
    }

    public function testPublicPageAndValidationAttributesMatchSecurityContract(): void
    {
        self::assertMethodHasAttribute(PageController::class, 'index', NoAdminRequired::class);
        self::assertMethodHasAttribute(PageController::class, 'index', NoCSRFRequired::class);
        self::assertMethodHasAttribute(ValidationController::class, 'validate', NoAdminRequired::class);
        self::assertMethodLacksAttribute(ValidationController::class, 'validate', NoCSRFRequired::class);
    }

    #[DataProvider('adminMethodProvider')]
    public function testAdminEndpointsKeepFrameworkAdminAndCsrfProtection(string $method): void
    {
        self::assertMethodLacksAttribute(AdminController::class, $method, NoAdminRequired::class);
        self::assertMethodLacksAttribute(AdminController::class, $method, NoCSRFRequired::class);
    }

    public static function adminMethodProvider(): iterable
    {
        yield 'status' => ['status'];
        yield 'install' => ['install'];
        yield 'save paths and scenario' => ['savePaths'];
    }

    /** @param class-string $attribute */
    private static function assertMethodHasAttribute(
        string $class,
        string $method,
        string $attribute
    ): void {
        $reflection = new \ReflectionMethod($class, $method);
        self::assertCount(1, $reflection->getAttributes($attribute));
    }

    /** @param class-string $attribute */
    private static function assertMethodLacksAttribute(
        string $class,
        string $method,
        string $attribute
    ): void {
        $reflection = new \ReflectionMethod($class, $method);
        self::assertSame([], $reflection->getAttributes($attribute));
    }
}
