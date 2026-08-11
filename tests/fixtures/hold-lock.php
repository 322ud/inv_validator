<?php

declare(strict_types=1);

$lock = fopen($argv[1], 'c');
if ($lock === false || !flock($lock, LOCK_EX)) {
    fwrite(STDERR, 'could not acquire fixture lock');
    exit(1);
}

fwrite(STDOUT, "locked\n");
fflush(STDOUT);

while (true) {
    usleep(100000);
}
