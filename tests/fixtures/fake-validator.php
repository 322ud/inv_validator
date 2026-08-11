#!/usr/bin/env php
<?php

declare(strict_types=1);

$outputIndex = array_search('-o', $argv, true);
$outputDirectory = $outputIndex === false ? null : ($argv[$outputIndex + 1] ?? null);
$xmlPath = $argv[array_key_last($argv)] ?? null;
$xml = is_string($xmlPath) ? file_get_contents($xmlPath) : false;

if (!is_string($outputDirectory) || $xml === false) {
    fwrite(STDERR, 'invalid fake validator invocation');
    exit(64);
}

if (str_contains($xml, '<sleep')) {
    usleep(5000000);
}

if (str_contains($xml, '<no-report')) {
    fwrite(STDERR, 'fixture produced no report');
    exit(9);
}

$assessment = str_contains($xml, '<reject') ? 'reject' : 'accept';
$report = sprintf(
    '<rep:report xmlns:rep="http://www.xoev.de/de/validator/varl/1"><rep:assessment><rep:%s/></rep:assessment></rep:report>',
    $assessment
);

file_put_contents($outputDirectory . '/validation-report.xml', $report);
file_put_contents($outputDirectory . '/validation.html', '<html><body>fixture report</body></html>');
fwrite(STDOUT, 'fixture stdout');
fwrite(STDERR, 'fixture stderr');

exit(str_contains($xml, '<exit-code') ? 7 : 0);
