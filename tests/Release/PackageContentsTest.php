<?php

declare(strict_types=1);

namespace OCA\KoSITValidator\Tests\Release;

use OCA\KoSITValidator\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;

final class PackageContentsTest extends TestCase {
    private string $appDirectory;
    private string $expectedVersion;

    protected function setUp(): void {
        $nextcloudRoot = getenv('NEXTCLOUD_PACKAGE_ROOT');
        self::assertIsString($nextcloudRoot);
        self::assertNotSame('', $nextcloudRoot);
        $this->appDirectory = $nextcloudRoot . '/apps/kosit_validator';

        $expectedVersion = getenv('EXPECTED_APP_VERSION');
        self::assertIsString($expectedVersion);
        self::assertNotSame('', $expectedVersion);
        $this->expectedVersion = $expectedVersion;
    }

    public function testContainsRuntimeFilesAndMetadata(): void {
        self::assertFileExists($this->appDirectory . '/appinfo/info.xml');
        self::assertFileExists($this->appDirectory . '/appinfo/routes.php');
        self::assertFileExists($this->appDirectory . '/config/EN16931_(CII)-szenario.xml');
        self::assertFileExists($this->appDirectory . '/js/app.js');
        self::assertFileExists($this->appDirectory . '/lib/AppInfo/Application.php');
        self::assertFileExists($this->appDirectory . '/templates/index.php');

        $metadata = Server::get(IAppManager::class)->getAppInfo('kosit_validator');
        self::assertIsArray($metadata);
        self::assertSame('kosit_validator', $metadata['id'] ?? null);
        self::assertSame($this->expectedVersion, $metadata['version'] ?? null);

        $applicationClass = new \ReflectionClass(Application::class);
        self::assertSame(
            $this->appDirectory . '/lib/AppInfo/Application.php',
            $applicationClass->getFileName()
        );
    }

    public function testExcludesDevelopmentFiles(): void {
        foreach ([
            '.git',
            '.github',
            'composer.json',
            'composer.lock',
            'deploy.sh',
            'node_modules',
            'package.json',
            'package-lock.json',
            'tests',
            'vendor',
        ] as $entry) {
            self::assertFileDoesNotExist($this->appDirectory . '/' . $entry);
        }
    }
}
