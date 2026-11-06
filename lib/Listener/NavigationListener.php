<?php

declare(strict_types=1);

namespace OCA\KoSITValidator\Listener;

use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Navigation\Events\LoadAdditionalEntriesEvent;

/** @implements IEventListener<LoadAdditionalEntriesEvent> */
class NavigationListener implements IEventListener
{
    public function __construct(
        private INavigationManager $navigation,
        private IConfig $config,
        private IUserSession $session,
        private IGroupManager $groups,
        private IAppManager $apps,
        private IURLGenerator $urls,
        private IL10N $l10n,
    ) {
    }

    public function handle(Event $event): void
    {
        if (!$event instanceof LoadAdditionalEntriesEvent) {
            return;
        }
        $user = $this->session->getUser();
        if ($user === null || !$this->apps->isEnabledForUser('kosit_validator', $user)) {
            return;
        }
        if (
            $this->config->getAppValue('kosit_validator', 'hide_navigation', 'no') === 'yes'
            && !$this->groups->isAdmin($user->getUID())
        ) {
            return;
        }
        $this->navigation->add([
            'id' => 'kosit_validator',
            'app' => 'kosit_validator',
            'order' => 100,
            'href' => $this->urls->linkToRoute('kosit_validator.page.index'),
            'icon' => $this->urls->imagePath('kosit_validator', 'app.svg'),
            'name' => $this->l10n->t('prüfeXRechnung'),
        ]);
    }
}
