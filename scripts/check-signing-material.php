<?php

declare(strict_types=1);

// Fail before signing; never print private key material or OpenSSL error details.
[$script, $keyPath, $certificatePath, $appId] = $argv;
$key = openssl_pkey_get_private(file_get_contents($keyPath), '');
$certificate = openssl_x509_read(file_get_contents($certificatePath));
if ($key === false || $certificate === false || !openssl_x509_check_private_key($certificate, $key)) {
    fwrite(STDERR, "Signing requires a matching, readable certificate and unencrypted private key.\n");
    exit(1);
}
$details = openssl_pkey_get_details($key);
$info = openssl_x509_parse($certificate);
if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 4096) {
    fwrite(STDERR, "Signing requires an RSA key with at least 4096 bits.\n");
    exit(1);
}
if ($info === false || ($info['subject']['CN'] ?? null) !== $appId) {
    fwrite(STDERR, "Certificate CN must match the app ID in appinfo/info.xml.\n");
    exit(1);
}
if (($info['validFrom_time_t'] ?? PHP_INT_MAX) > time() || ($info['validTo_time_t'] ?? 0) < time()) {
    fwrite(STDERR, "Signing certificate is not currently valid.\n");
    exit(1);
}
