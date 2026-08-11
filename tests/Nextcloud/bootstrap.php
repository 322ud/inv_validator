<?php

declare(strict_types=1);

$serverRoot = realpath(__DIR__ . '/../../../..');
if ($serverRoot === false) {
    throw new \RuntimeException('Nextcloud server root could not be resolved');
}

$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/index.php/apps/kosit_validator/';
$_SERVER['SCRIPT_FILENAME'] = $serverRoot . '/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['SERVER_PORT'] = '80';

require_once $serverRoot . '/lib/base.php';
require_once __DIR__ . '/../../vendor/autoload.php';

\OC_App::loadApp(\OCA\KoSITValidator\AppInfo\Application::APP_ID);
\OC_Hook::clear();
