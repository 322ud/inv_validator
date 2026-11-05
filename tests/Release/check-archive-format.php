<?php

declare(strict_types=1);

// Read raw headers: archive readers can silently consume PAX extension entries.
$archive = gzopen($argv[1], 'rb');
if ($archive === false) {
    throw new RuntimeException('Could not open release archive');
}

$entries = 0;
try {
    while (true) {
        $header = gzread($archive, 512);
        if ($header === false || strlen($header) !== 512) {
            throw new RuntimeException('Truncated release archive');
        }
        if ($header === str_repeat("\0", 512)) {
            break;
        }
        if (substr($header, 257, 8) !== "ustar\00000") {
            throw new RuntimeException('Release archive must use USTAR headers');
        }
        if (!in_array($header[156], ["\0", '0', '5'], true)) {
            throw new RuntimeException('Release archive contains an unsupported entry (possibly PAX metadata)');
        }

        $size = octdec(trim(substr($header, 124, 12), "\0 "));
        $paddedSize = (int) (ceil($size / 512) * 512);
        if (gzseek($archive, $paddedSize, SEEK_CUR) !== 0) {
            throw new RuntimeException('Could not skip release archive entry');
        }
        $entries++;
    }
    if ($entries === 0) {
        throw new RuntimeException('Release archive is empty');
    }
} finally {
    gzclose($archive);
}
