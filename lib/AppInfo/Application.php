<?php

namespace OCA\KoSITValidator\AppInfo;

use OCP\AppFramework\App;

class Application extends App {
    public const APP_ID = 'kosit_validator';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }
}
