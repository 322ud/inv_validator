<?php

declare(strict_types=1);

namespace OCA\INValidator\Tests\Unit\Controller;

use OCA\INValidator\Controller\DocumentationController;
use OCA\INValidator\Service\TextEditorLoader;
use OCP\IRequest;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

final class DocumentationControllerTest extends TestCase
{
    public function testOnlyPackagedDocumentsCanBeSelected(): void
    {
        $urls = $this->createStub(IURLGenerator::class);
        $urls->method('linkToRoute')->willReturn('/apps/inv_validator/readme');
        $loader = new TextEditorLoader($this->createStub(IEventDispatcher::class));
        $controller = new DocumentationController('inv_validator', $this->createStub(IRequest::class), $loader, $urls);
        self::assertSame('admin', $controller->index('admin')->getParams()['document']);
        self::assertSame('readme', $controller->index('../../config/config.php')->getParams()['document']);
    }
}
