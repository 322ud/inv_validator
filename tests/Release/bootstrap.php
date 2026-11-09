<?php

declare(strict_types=1);

$nextcloudRoot = getenv('NEXTCLOUD_PACKAGE_ROOT');
if (!is_string($nextcloudRoot) || $nextcloudRoot === '') {
    throw new RuntimeException('NEXTCLOUD_PACKAGE_ROOT must be configured');
}

$workspaceRoot = realpath(__DIR__ . '/../..');
if ($workspaceRoot === false) {
    throw new RuntimeException('Could not resolve the app workspace');
}

$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/index.php/apps/inv_validator/';
$_SERVER['SCRIPT_FILENAME'] = $nextcloudRoot . '/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['SERVER_PORT'] = '80';

require_once $nextcloudRoot . '/lib/base.php';
$composerLoader = require $workspaceRoot . '/vendor/autoload.php';
if (!$composerLoader instanceof \Composer\Autoload\ClassLoader) {
    throw new RuntimeException('Could not configure the Composer class loader');
}
$composerLoader->setPsr4(
    'OCA\\INValidator\\',
    [$nextcloudRoot . '/apps/inv_validator/lib']
);

\OC_App::loadApp(\OCA\INValidator\AppInfo\Application::APP_ID);
\OC_Hook::clear();
