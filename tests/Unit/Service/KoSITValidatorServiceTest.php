<?php

declare(strict_types=1);

namespace OCA\KoSITValidator\Tests\Unit\Service;

use OCA\KoSITValidator\Exception\ValidationException;
use OCA\KoSITValidator\Service\KoSITValidatorService;
use OCA\KoSITValidator\Service\ValidatorConfigService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KoSITValidatorServiceTest extends TestCase {
    private string $temporaryDirectory;
    private KoSITValidatorService $service;

    protected function setUp(): void {
        $this->temporaryDirectory = sys_get_temp_dir() . '/kosit-test-' . bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory, 0700, true);

        $config = $this->createStub(ValidatorConfigService::class);
        $this->service = new KoSITValidatorService($config);
    }

    protected function tearDown(): void {
        $this->removeDirectory($this->temporaryDirectory);
    }

    #[DataProvider('assessmentProvider')]
    public function testReadsAssessmentFromNamespacedReport(string $assessment, bool $expected): void {
        $report = $this->writeReport(sprintf(
            '<rep:report xmlns:rep="http://www.xoev.de/de/validator/varl/1"><rep:assessment><rep:%s/></rep:assessment></rep:report>',
            $assessment
        ));

        self::assertSame($expected, $this->invoke('readReportValidity', $report));
    }

    public static function assessmentProvider(): iterable {
        yield 'accept' => ['accept', true];
        yield 'reject' => ['reject', false];
    }

    public function testRejectsReportWithoutAssessment(): void {
        $report = $this->writeReport(
            '<rep:report xmlns:rep="http://www.xoev.de/de/validator/varl/1"/>'
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('contains no assessment');
        $this->invoke('readReportValidity', $report);
    }

    public function testRejectsAssessmentInWrongNamespace(): void {
        $report = $this->writeReport(
            '<rep:report xmlns:rep="urn:not-kosit"><rep:assessment><rep:accept/></rep:assessment></rep:report>'
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('contains no assessment');
        $this->invoke('readReportValidity', $report);
    }

    public function testRejectsUnreadableReport(): void {
        $report = $this->writeReport('<report><broken></report>');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('unreadable XML report');
        $this->invoke('readReportValidity', $report);
    }

    public function testRejectsMalformedReportContainingRawAcceptMarker(): void {
        $report = $this->writeReport(
            '<rep:report xmlns:rep="http://www.xoev.de/de/validator/varl/1">'
            . '<rep:assessment><rep:accept/></rep:assessment><broken></rep:report>'
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('unreadable XML report');
        $this->invoke('readReportValidity', $report);
    }

    public function testAcceptsCorrectNamespaceWithArbitraryDocumentPrefix(): void {
        $report = $this->writeReport(
            '<result:report xmlns:result="http://www.xoev.de/de/validator/varl/1">'
            . '<result:assessment><result:accept/></result:assessment></result:report>'
        );

        self::assertTrue($this->invoke('readReportValidity', $report));
    }

    public function testDocumentCannotShadowInternalXpathPrefix(): void {
        $report = $this->writeReport(
            '<rep:report xmlns:rep="http://www.xoev.de/de/validator/varl/1" xmlns:varl="urn:attacker">'
            . '<rep:assessment><rep:accept/></rep:assessment></rep:report>'
        );

        self::assertTrue($this->invoke('readReportValidity', $report));
    }

    public function testRejectsConflictingAssessments(): void {
        $report = $this->writeReport(
            '<rep:report xmlns:rep="http://www.xoev.de/de/validator/varl/1"><rep:assessment>'
            . '<rep:accept/><rep:reject/></rep:assessment></rep:report>'
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('conflicting assessments');
        $this->invoke('readReportValidity', $report);
    }

    public function testRejectsDoctypeAndExternalEntityDeclaration(): void {
        $report = $this->writeReport(
            '<!DOCTYPE rep:report [<!ENTITY secret SYSTEM "file:///etc/passwd">]>'
            . '<rep:report xmlns:rep="http://www.xoev.de/de/validator/varl/1">'
            . '<rep:assessment><rep:accept/></rep:assessment></rep:report>'
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('DOCTYPE');
        $this->invoke('readReportValidity', $report);
    }

    public function testFindsReportsRecursively(): void {
        $nested = $this->temporaryDirectory . '/nested';
        mkdir($nested);
        file_put_contents($nested . '/validation-report.xml', '<report/>');
        file_put_contents($nested . '/validation.html', '<html></html>');

        $reports = $this->invoke('findReportFiles', $this->temporaryDirectory);

        self::assertSame($nested . '/validation-report.xml', $reports['xml']);
        self::assertSame($nested . '/validation.html', $reports['html']);
    }

    public function testIgnoresXmlFilesWhoseNameDoesNotContainReport(): void {
        file_put_contents($this->temporaryDirectory . '/document.xml', '<invoice/>');

        $reports = $this->invoke('findReportFiles', $this->temporaryDirectory);

        self::assertNull($reports['xml']);
    }

    private function writeReport(string $content): string {
        $path = $this->temporaryDirectory . '/report.xml';
        file_put_contents($path, $content);
        return $path;
    }

    private function invoke(string $method, mixed ...$arguments): mixed {
        $reflection = new \ReflectionMethod($this->service, $method);
        return $reflection->invoke($this->service, ...$arguments);
    }

    private function removeDirectory(string $directory): void {
        foreach (scandir($directory) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}
