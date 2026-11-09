<?php

declare(strict_types=1);

namespace OCA\INValidator\Tests\Unit\Service;

use OCA\INValidator\Service\TextEditorLoader;
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
