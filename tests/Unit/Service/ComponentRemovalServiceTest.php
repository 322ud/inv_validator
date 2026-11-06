<?php

declare(strict_types=1);

namespace OCA\KoSITValidator\Tests\Unit\Service;

use OCA\KoSITValidator\Exception\AdministrationException;
use OCA\KoSITValidator\Service\ComponentInstallationRegistry;
use OCA\KoSITValidator\Service\ComponentRemovalService;
use OCA\KoSITValidator\Service\ValidatorConfigService;
use OCP\AppFramework\Services\IAppConfig;
use PHPUnit\Framework\TestCase;

final class ComponentRemovalServiceTest extends TestCase
{
    private string $root;
    private IAppConfig $app;
    private ComponentInstallationRegistry $registry;
    private ValidatorConfigService $config;
    private ComponentRemovalService $service;
    private array $updates;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/component-removal-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        $stored = [];
        $this->app = $this->createStub(IAppConfig::class);
        $this->app->method('getAppValueString')->willReturnCallback(static function (string $key, string $default) use (&$stored): string {
            return $stored[$key] ?? $default;
        });
        $this->app->method('setAppValueString')->willReturnCallback(static function (string $key, string $value) use (&$stored): void {
            $stored[$key] = $value;
        });
        $this->registry = new ComponentInstallationRegistry($this->app);
        $this->updates = ['validator' => true, 'configuration' => true];
        $this->config = $this->createStub(ValidatorConfigService::class);
        $this->config->method('getInstallationRegistry')->willReturn($this->registry);
        $this->config->method('getValidatorJar')->willReturn($this->root . '/validator-standalone.jar');
        $this->config->method('getAutoUpdates')->willReturnCallback(fn () => $this->updates);
        $this->config->method('saveAutoUpdates')->willReturnCallback(function (bool $validator, bool $configuration): void {
            $this->updates = compact('validator', 'configuration');
        });
        $this->service = new ComponentRemovalService($this->config);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($this->root);
    }

    private function validator(string $version = '1.6.2', bool $owned = true): string
    {
        $path = $this->root . '/validator-' . $version . '-standalone.jar';
        file_put_contents($path, 'jar ' . $version);
        $this->registry->prepare('validator', $version, $path, $owned ? $this->registry->manifest($path) : null);
        $link = $this->root . '/validator-standalone.jar';
        if (is_link($link)) {
            unlink($link);
        }
        symlink(basename($path), $link);
        $this->registry->activated('validator', $link, $path);
        return $path;
    }

    private function configuration(): string
    {
        $path = $this->root . '/xrechnung/releases/2026-01-31';
        mkdir($path . '/resources', 0700, true);
        file_put_contents($path . '/scenarios.xml', '<scenarios/>');
        file_put_contents($path . '/resources/report.xsl', '<xsl/>');
        $this->registry->prepare('configuration', '2026-01-31', $path, $this->registry->manifest($path));
        $link = $this->root . '/xrechnung/current';
        symlink($path, $link);
        $this->registry->activated('configuration', $link, $path);
        return $path;
    }

    private function token(string $component): string
    {
        $pending = array_column($this->service->pending(), null, 'component');
        return $pending[$component]['recordToken'];
    }

    public function testDeselectedComponentCannotBeRemoved(): void
    {
        $path = $this->validator();
        try {
            $this->service->remove('validator', false, $this->token('validator'));
            self::fail('Expected confirmation to be rejected');
        } catch (\InvalidArgumentException) {
            self::assertFileExists($path);
            self::assertTrue(is_link($this->root . '/validator-standalone.jar'));
            self::assertSame('installed', $this->registry->get('validator')['state']);
        }
    }

    public function testPersistsSystemTimeAndRemovesOnlyConfirmedComponentAndOwnedVersions(): void
    {
        $old = $this->validator('1.6.1');
        $new = $this->validator('1.6.2');
        $configuration = $this->configuration();
        $untracked = $this->root . '/validator-1.6.0-standalone.jar';
        file_put_contents($untracked, 'earlier version without record');
        $freshRegistry = new ComponentInstallationRegistry($this->app);
        self::assertSame($this->registry->get('validator'), $freshRegistry->get('validator'));
        self::assertLessThanOrEqual(2, abs(time() - strtotime($freshRegistry->get('validator')['lastOperationAt'])));
        $this->service->remove('validator', true, $this->token('validator'));
        self::assertFileDoesNotExist($old);
        self::assertFileDoesNotExist($new);
        self::assertFalse(is_link($this->root . '/validator-standalone.jar'));
        self::assertFileExists($untracked);
        self::assertDirectoryExists($configuration);
        self::assertSame(['validator' => false, 'configuration' => true], $this->updates);
        self::assertSame('removed', $freshRegistry->get('validator')['state']);
        self::assertNotFalse(strtotime($freshRegistry->get('validator')['removedAt']));
        self::assertCount(1, $this->service->pending());
        $this->service->remove('configuration', true, $this->token('configuration'));
        self::assertDirectoryDoesNotExist($configuration);
        self::assertSame([], $this->service->pending());
    }

    public function testReusedArtifactIsNeverDeleted(): void
    {
        $path = $this->validator(owned: false);
        $this->service->remove('validator', true, $this->token('validator'));
        self::assertFileExists($path);
        self::assertFalse(is_link($this->root . '/validator-standalone.jar'));
    }

    public function testChangedFilesPreventAllRemovalIncludingLinks(): void
    {
        $path = $this->configuration();
        file_put_contents($path . '/resources/extra.txt', 'untracked user content');
        try {
            $this->service->remove('configuration', true, $this->token('configuration'));
            self::fail('Expected changed contents to prevent removal');
        } catch (AdministrationException) {
            self::assertFileExists($path . '/scenarios.xml');
            self::assertFileExists($path . '/resources/extra.txt');
            self::assertTrue(is_link($this->root . '/xrechnung/current'));
        }
    }

    public function testReplacedFileAndSymlinkAreNotDeleted(): void
    {
        $path = $this->validator();
        unlink($path);
        symlink('/etc/passwd', $path);
        $this->expectException(AdministrationException::class);
        $this->service->remove('validator', true, $this->token('validator'));
    }

    public function testStaleConfirmationCannotDeleteNewlyInstalledVersion(): void
    {
        $this->validator('1.6.1');
        $token = $this->token('validator');
        $new = $this->validator('1.6.2');
        try {
            $this->service->remove('validator', true, $token);
            self::fail('Expected stale confirmation to fail');
        } catch (AdministrationException) {
            self::assertFileExists($new);
        }
    }

    public function testPreparedButUnactivatedArtifactCanBeRemoved(): void
    {
        $path = $this->root . '/validator-1.6.2-standalone.jar';
        file_put_contents($path, 'prepared');
        $this->registry->prepare('validator', '1.6.2', $path, $this->registry->manifest($path));
        $this->service->remove('validator', true, $this->token('validator'));
        self::assertFileDoesNotExist($path);
    }

    public function testInterruptedRemovalCanBeResumedWithoutDeletingUnknownFiles(): void
    {
        $path = $this->configuration();
        $this->registry->removing('configuration');
        unlink($path . '/resources/report.xsl');
        $this->service->remove('configuration', true, $this->token('configuration'));
        self::assertDirectoryDoesNotExist($path);
    }

    public function testInstallationLockPreventsConcurrentRemoval(): void
    {
        $path = $this->validator();
        $lock = fopen($this->root . '/.kosit-install.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $this->service->remove('validator', true, $this->token('validator'));
            self::fail('Expected shared component lock to prevent removal');
        } catch (AdministrationException) {
            self::assertFileExists($path);
        } finally {
            fclose($lock);
        }
    }
}
