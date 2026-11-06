<?php

declare(strict_types=1);

namespace OCA\KoSITValidator\Tests\Nextcloud;

use OCA\KoSITValidator\AppInfo\Application;
use OCA\KoSITValidator\Listener\NavigationListener;
use OCA\KoSITValidator\Controller\AdminController;
use OCA\KoSITValidator\Controller\PageController;
use OCA\KoSITValidator\Controller\ValidationController;
use OCA\KoSITValidator\Service\KoSITValidatorService;
use OCA\KoSITValidator\Service\ValidatorAdminService;
use OCA\KoSITValidator\Service\ValidatorConfigService;
use OCA\KoSITValidator\Settings\Admin;
use OCA\KoSITValidator\Settings\AdminSection;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\IURLGenerator;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\INavigationManager;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Navigation\Events\LoadAdditionalEntriesEvent;
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
        self::assertInstanceOf(NavigationListener::class, $container->get(NavigationListener::class));
        self::assertInstanceOf(ValidatorConfigService::class, $container->get(ValidatorConfigService::class));
        self::assertInstanceOf(KoSITValidatorService::class, $container->get(KoSITValidatorService::class));
        self::assertInstanceOf(ValidatorAdminService::class, $container->get(ValidatorAdminService::class));
        self::assertInstanceOf(AdminController::class, $container->get(AdminController::class));
        self::assertInstanceOf(ValidationController::class, $container->get(ValidationController::class));

        $settings = $container->get(Admin::class);
        self::assertSame('kosit_validator', $settings->getSection());
        self::assertSame(50, $settings->getPriority());

        $section = $container->get(AdminSection::class);
        self::assertSame('kosit_validator', $section->getID());
        self::assertSame('prüfeXRechnung', $section->getName());
        self::assertSame(80, $section->getPriority());
        self::assertStringContainsString('/apps/kosit_validator/img/app.svg', $section->getIcon());
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

    #[DataProvider('navigationVisibilityProvider')]
    public function testNavigationVisibility(
        bool $loggedIn,
        bool $enabled,
        bool $hidden,
        bool $admin,
        bool $visible
    ): void {
        $navigation = $this->createMock(INavigationManager::class);
        if ($visible) {
            $navigation->expects(self::once())->method('add')->with(self::callback(
                static fn (array $entry): bool => $entry['id'] === Application::APP_ID
                    && str_contains($entry['href'], '/apps/kosit_validator/')
            ));
        } else {
            $navigation->expects(self::never())->method('add');
        }
        $user = $this->createStub(IUser::class);
        $user->method('getUID')->willReturn('navigation-test-user');
        $session = $this->createStub(IUserSession::class);
        $session->method('getUser')->willReturn($loggedIn ? $user : null);
        $apps = $this->createStub(IAppManager::class);
        $apps->method('isEnabledForUser')->willReturn($enabled);
        $config = $this->createStub(IConfig::class);
        $config->method('getAppValue')->willReturn($hidden ? 'yes' : 'no');
        $groups = $this->createStub(IGroupManager::class);
        $groups->method('isAdmin')->willReturn($admin);

        $listener = new NavigationListener(
            $navigation,
            $config,
            $session,
            $groups,
            $apps,
            Server::get(IURLGenerator::class),
            $this->application->getContainer()->get(IL10N::class),
        );
        $listener->handle(new LoadAdditionalEntriesEvent());
    }

    public static function navigationVisibilityProvider(): iterable
    {
        yield 'guest' => [false, true, false, false, false];
        yield 'disabled for user' => [true, false, false, false, false];
        yield 'normal user' => [true, true, false, false, true];
        yield 'hidden for normal user' => [true, true, true, false, false];
        yield 'visible for admin when hidden' => [true, true, true, true, true];
    }

    public function testPageControllerRendersTheRealTemplate(): void
    {
        $controller = $this->application->getContainer()->get(PageController::class);
        $response = $controller->index();

        self::assertSame('kosit_validator', $response->getApp());
        self::assertSame('index', $response->getTemplateName());
        $html = $response->render();
        self::assertStringContainsString('id="kosit-validator"', $html);
        self::assertStringNotContainsString('Administrator informieren', $html);
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
        yield 'save paths' => ['savePaths'];
        yield 'save settings' => ['saveSettings'];
        yield 'remove component' => ['removeComponent'];
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
