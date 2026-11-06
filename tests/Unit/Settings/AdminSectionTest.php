<?php

declare(strict_types=1);

namespace OCA\KoSITValidator\Tests\Unit\Settings;

use OCA\KoSITValidator\Settings\AdminSection;
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
            ->with('prüfeXRechnung')
            ->willReturn('prüfeXRechnung');

        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->expects(self::once())
            ->method('imagePath')
            ->with('kosit_validator', 'app.svg')
            ->willReturn('/apps/kosit_validator/img/app.svg');

        $section = new AdminSection($l10n, $urlGenerator);

        self::assertSame('kosit_validator', $section->getID());
        self::assertSame('prüfeXRechnung', $section->getName());
        self::assertSame(80, $section->getPriority());
        self::assertSame('/apps/kosit_validator/img/app.svg', $section->getIcon());
    }
}
