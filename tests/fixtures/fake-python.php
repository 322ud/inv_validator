#!/usr/bin/env php
<?php

declare(strict_types=1);

if (in_array('-c', $argv, true)) {
    echo json_encode([
        'pythonVersion' => '3.12.1',
        'pymupdfVersion' => '1.26.0',
    ], JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
}

$input = $argv[count($argv) - 2] ?? '';
$output = $argv[count($argv) - 1] ?? '';
$content = is_string($input) ? file_get_contents($input) : false;

if (!is_string($content) || !str_starts_with($content, 'fake-pdf:')) {
    exit(15);
}

if (file_put_contents($output, substr($content, strlen('fake-pdf:'))) === false) {
    exit(15);
}
