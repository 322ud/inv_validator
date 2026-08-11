<?php

declare(strict_types=1);

$mode = $argv[1] ?? '';

if ($mode === 'exit') {
    fwrite(STDOUT, $argv[2] ?? 'stdout');
    fwrite(STDERR, $argv[3] ?? 'stderr');
    exit((int)($argv[4] ?? 0));
}

if ($mode === 'large-output') {
    $bytes = (int)($argv[2] ?? 100000);
    fwrite(STDOUT, str_repeat('o', $bytes));
    fwrite(STDERR, str_repeat('e', $bytes));
    exit(0);
}

if ($mode === 'ignore-term') {
    $marker = $argv[2] ?? '';
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use ($marker): void {
        file_put_contents($marker, 'term');
    });

    while (true) {
        usleep(10000);
    }
}

fwrite(STDERR, 'unknown fixture mode');
exit(64);
