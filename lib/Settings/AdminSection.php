<?php

declare(strict_types=1);

namespace OCA\KoSITValidator\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection
{
    public function __construct(
        private IL10N $l10n,
        private IURLGenerator $urlGenerator
    ) {
    }

    public function getID(): string
    {
        return 'kosit_validator';
    }

    public function getName(): string
    {
        return $this->l10n->t('KoSIT Validator');
    }

    public function getPriority(): int
    {
        return 80;
    }

    public function getIcon(): string
    {
        return $this->urlGenerator->imagePath('kosit_validator', 'app.svg');
    }
}
