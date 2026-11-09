<?php

declare(strict_types=1);

namespace OCA\INValidator\Service;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;

final class TextEditorLoader
{
    public function __construct(
        private IEventDispatcher $eventDispatcher
    ) {
    }

    public function load(): void
    {
        $eventClass = 'OCA\\Text\\Event\\LoadEditor';

        if (!class_exists($eventClass)) {
            return;
        }

        $event = new $eventClass();
        if (!$event instanceof Event) {
            return;
        }

        $this->eventDispatcher->dispatchTyped($event);
    }
}
