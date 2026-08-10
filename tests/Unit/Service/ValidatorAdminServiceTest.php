<?php

declare(strict_types=1);

namespace OCA\KoSITValidator\Tests\Unit\Service;

use OCA\KoSITValidator\Exception\AdministrationException;
use OCA\KoSITValidator\Service\ValidatorAdminService;
use OCA\KoSITValidator\Service\ValidatorConfigService;
use OCP\Http\Client\IClientService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValidatorAdminServiceTest extends TestCase {
    private const NAMESPACE = 'http://www.xoev.de/de/validator/framework/1/scenarios';

    private string $temporaryDirectory;
    private string $scenarioPath;
    private ValidatorAdminService $service;

    protected function setUp(): void {
        $this->temporaryDirectory = sys_get_temp_dir() . '/kosit-admin-test-' . bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory, 0700, true);
        $this->scenarioPath = $this->temporaryDirectory . '/scenario.xml';
        file_put_contents($this->scenarioPath, $this->validScenario('initial'));

        $config = $this->createStub(ValidatorConfigService::class);
        $config->method('getScenarioFile')->willReturn($this->scenarioPath);
        $this->service = new ValidatorAdminService(
            $config,
            $this->createStub(IClientService::class)
        );
    }

    protected function tearDown(): void {
        foreach (glob($this->temporaryDirectory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->temporaryDirectory)) {
            rmdir($this->temporaryDirectory);
        }
    }

    public function testReadScenarioReturnsContentChecksumAndPath(): void {
        $result = $this->service->readScenario();

        self::assertSame($this->scenarioPath, $result['path']);
        self::assertSame(file_get_contents($this->scenarioPath), $result['content']);
        self::assertSame(hash('sha256', $result['content']), $result['checksum']);
        self::assertTrue($result['writable']);
    }

    public function testSaveScenarioAtomicallyReplacesContent(): void {
        $oldContent = file_get_contents($this->scenarioPath);
        $newContent = $this->validScenario('updated');

        $this->service->saveScenario($newContent, hash('sha256', $oldContent));

        self::assertSame($newContent, file_get_contents($this->scenarioPath));
        self::assertSame(hash('sha256', $newContent), $this->service->readScenario()['checksum']);
        self::assertSame([], glob($this->temporaryDirectory . '/.scenario-*') ?: []);
    }

    public function testStaleChecksumDoesNotOverwriteScenario(): void {
        $original = file_get_contents($this->scenarioPath);

        try {
            $this->service->saveScenario($this->validScenario('updated'), str_repeat('0', 64));
            self::fail('Expected a stale checksum exception');
        } catch (AdministrationException $exception) {
            self::assertStringContainsString('zwischenzeitlich geändert', $exception->getMessage());
        }

        self::assertSame($original, file_get_contents($this->scenarioPath));
    }

    #[DataProvider('invalidScenarioProvider')]
    public function testRejectsInvalidScenarioXml(string $content, string $message): void {
        $checksum = hash_file('sha256', $this->scenarioPath);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $this->service->saveScenario($content, $checksum);
    }

    public static function invalidScenarioProvider(): iterable {
        yield 'empty' => ['', 'darf nicht leer sein'];
        yield 'malformed' => ['<scenarios>', 'Ungültiges XML'];
        yield 'doctype' => ['<!DOCTYPE scenarios><scenarios/>', 'DOCTYPE'];
        yield 'wrong root' => ['<scenario xmlns="' . self::NAMESPACE . '"/>', 'Wurzelelement'];
        yield 'wrong namespace' => ['<scenarios xmlns="urn:wrong"/>', 'Wurzelelement'];
    }

    public function testRejectsInvalidChecksumFormat(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Ungültiger Änderungsstand');
        $this->service->saveScenario($this->validScenario('updated'), 'not-a-checksum');
    }

    private function validScenario(string $id): string {
        return sprintf('<scenarios xmlns="%s" id="%s"/>', self::NAMESPACE, $id);
    }
}
