<?php

declare(strict_types=1);

namespace OCA\KoSITValidator\Tests\Unit\Service;

use OCA\KoSITValidator\Service\TextEditorLoader;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;

final class TextEditorLoaderTest extends TestCase
{
    public function testDoesNothingWhenTextAppClassIsUnavailable(): void
    {
        $dispatcher = $this->createMock(IEventDispatcher::class);
        $dispatcher->expects(self::never())
            ->method('dispatchTyped');

        (new TextEditorLoader($dispatcher))->load();
    }
}
