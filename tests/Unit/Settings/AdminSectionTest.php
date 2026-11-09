<?php

declare(strict_types=1);

namespace OCA\INValidator\Tests\Unit\Settings;

use OCA\INValidator\Settings\AdminSection;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

final class AdminSectionTest extends TestCase
{
    public function testDefinesDedicatedAdminSection(): void
    {
        $l10n = $this->createMock(IL10N::class);
        $l10n->expects(self::once())
            ->method('t')
            ->with('INValidator')
            ->willReturn('INValidator');

        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->expects(self::once())
            ->method('imagePath')
            ->with('inv_validator', 'app.svg')
            ->willReturn('/apps/inv_validator/img/app.svg');

        $section = new AdminSection($l10n, $urlGenerator);

        self::assertSame('inv_validator', $section->getID());
        self::assertSame('INValidator', $section->getName());
        self::assertSame(80, $section->getPriority());
        self::assertSame('/apps/inv_validator/img/app.svg', $section->getIcon());
    }
}
