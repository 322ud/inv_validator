<?php

declare(strict_types=1);

// Contract fixture only. Uses a self-signed certificate; not Nextcloud's trust verification.
$options = [];
foreach ($argv as $arg) {
    if (preg_match('/^--([^=]+)=(.*)$/', $arg, $matches)) {
        $options[$matches[1]] = $matches[2];
    }
}
$path = $options['path'];
$hashes = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
    $relative = substr($file->getPathname(), strlen($path) + 1);
    if ($relative !== 'appinfo/signature.json') {
        $hashes[$relative] = hash_file('sha512', $file->getPathname());
    }
}
ksort($hashes);
if ($argv[1] === 'integrity:sign-app') {
    $certificate = file_get_contents($options['certificate']);
    openssl_sign(json_encode($hashes), $signature, file_get_contents($options['privateKey']), OPENSSL_ALGO_SHA512);
    file_put_contents($path . '/appinfo/signature.json', json_encode([
        'hashes' => $hashes, 'certificate' => $certificate, 'signature' => base64_encode($signature),
    ]));
} elseif ($argv[1] === 'integrity:check-app') {
    $data = json_decode(file_get_contents($path . '/appinfo/signature.json'), true);
    if (getenv('SIGNING_TEST_FAIL_CHECK') === '1' || $data['hashes'] !== $hashes
        || openssl_verify(json_encode($hashes), base64_decode($data['signature']), $data['certificate'], OPENSSL_ALGO_SHA512) !== 1) {
        exit(1);
    }
} else {
    exit(1);
}
