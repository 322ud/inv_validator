<?php

declare(strict_types=1);

namespace OCA\INValidator\Tests\Build;

use PHPUnit\Framework\TestCase;

/** Test shell orchestration and real archive cryptography without a production signing key. */
final class ReleaseSigningTest extends TestCase
{
    private static string $directory;
    private static string $key = '';
    private static string $certificate = '';

    public static function setUpBeforeClass(): void
    {
        self::$directory = sys_get_temp_dir() . '/kosit signing ' . bin2hex(random_bytes(6));
        mkdir(self::$directory, 0700);
        $key = openssl_pkey_new(['private_key_bits' => 4096, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, self::$key);
        $id = (string)simplexml_load_file(dirname(__DIR__, 2) . '/appinfo/info.xml')->id;
        $csr = openssl_csr_new(['commonName' => $id], $key);
        $certificate = openssl_csr_sign($csr, null, $key, 1);
        openssl_x509_export($certificate, self::$certificate);
        file_put_contents(self::$directory . '/key.pem', self::$key);
        file_put_contents(self::$directory . '/certificate.pem', self::$certificate);
    }

    public static function tearDownAfterClass(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::$directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir(self::$directory);
    }

    /** @param array<string, string> $overrides
     *  @return array{int, string, string}
     */
    private function build(array $overrides = [], string $target = 'output'): array
    {
        $environment = array_merge(getenv(), [
            'APP_REQUIRE_SIGNING' => '1',
            'APP_SIGNING_KEY_FILE' => self::$directory . '/key.pem',
            'APP_SIGNING_CERTIFICATE_FILE' => self::$directory . '/certificate.pem',
            'NEXTCLOUD_OCC' => dirname(__DIR__) . '/fixtures/signing-occ.php',
        ], $overrides);
        $process = proc_open(['bash', dirname(__DIR__, 2) . '/scripts/build-release.sh', self::$directory . '/' . $target],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), trim($output), $errors];
    }

    public function testSignedArchiveVerifiesAndBuildIsReproducible(): void
    {
        [$code, $archive, $errors] = $this->build();
        self::assertSame(0, $code, $errors);
        $signature = file_get_contents($archive . '.sig');
        self::assertSame(1, openssl_verify(file_get_contents($archive), $signature, self::$certificate, OPENSSL_ALGO_SHA512));
        self::assertSame($signature, base64_decode(file_get_contents($archive . '.sig.b64'), true));
        self::assertSame(self::$certificate, file_get_contents($archive . '.crt'));
        self::assertSame(0, openssl_verify(file_get_contents($archive) . 'tampered', $signature, self::$certificate, OPENSSL_ALGO_SHA512));
        $tar = new \PharData($archive);
        $id = (string)simplexml_load_file(dirname(__DIR__, 2) . '/appinfo/info.xml')->id;
        self::assertTrue(isset($tar[$id . '/appinfo/signature.json']));
        self::assertFalse(isset($tar[$id . '/scripts/check-signing-material.php']));
        foreach (new \RecursiveIteratorIterator($tar) as $entry) {
            self::assertStringNotContainsString(self::$key, $entry->getContent());
        }
        foreach (file($archive . '.sha256', FILE_IGNORE_NEW_LINES) as $line) {
            [$hash, $filename] = explode('  ', $line, 2);
            self::assertSame($hash, hash_file('sha256', dirname($archive) . '/' . $filename));
        }
        [$secondCode, $second, $secondErrors] = $this->build(target: 'comparison');
        self::assertSame(0, $secondCode, $secondErrors);
        self::assertSame(hash_file('sha256', $archive), hash_file('sha256', $second));
    }

    public function testUnsignedBuildClearsOldSignatureSidecars(): void
    {
        [$code, $archive, $errors] = $this->build(target: 'unsigned');
        self::assertSame(0, $code, $errors);
        [$code, $archive, $errors] = $this->build([
            'APP_REQUIRE_SIGNING' => '0', 'APP_SIGNING_KEY_FILE' => '', 'APP_SIGNING_CERTIFICATE_FILE' => '',
        ], 'unsigned');
        self::assertSame(0, $code, $errors);
        foreach (['.sig', '.sig.b64', '.crt'] as $suffix) {
            self::assertFileDoesNotExist($archive . $suffix);
        }
        $id = (string)simplexml_load_file(dirname(__DIR__, 2) . '/appinfo/info.xml')->id;
        self::assertFalse(isset((new \PharData($archive))[$id . '/appinfo/signature.json']));
    }

    public function testRequiredSigningCannotFallBackToUnsigned(): void
    {
        [$code, $output] = $this->build(['APP_SIGNING_KEY_FILE' => ''], 'missing');
        self::assertNotSame(0, $code);
        self::assertSame('', $output);
    }

    public function testIncompleteOptionalSigningIsRejected(): void
    {
        [$code, $output] = $this->build(['APP_REQUIRE_SIGNING' => '0', 'APP_SIGNING_CERTIFICATE_FILE' => ''], 'partial');
        self::assertNotSame(0, $code);
        self::assertSame('', $output);
    }

    public function testMismatchedKeyAndCertificateAreRejected(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        file_put_contents(self::$directory . '/mismatched.key', $pem);
        [$code, $output, $errors] = $this->build(['APP_SIGNING_KEY_FILE' => self::$directory . '/mismatched.key'], 'mismatched');
        self::assertNotSame(0, $code);
        self::assertSame('', $output);
        self::assertStringContainsString('matching', $errors);
    }

    public function testWrongCertificateScopeIsRejected(): void
    {
        $key = openssl_pkey_get_private(self::$key);
        $csr = openssl_csr_new(['commonName' => 'other_app'], $key);
        openssl_x509_export(openssl_csr_sign($csr, null, $key, 1), $wrong);
        file_put_contents(self::$directory . '/wrong.pem', $wrong);
        [$code, $output, $error] = $this->build(['APP_SIGNING_CERTIFICATE_FILE' => self::$directory . '/wrong.pem'], 'wrong');
        self::assertNotSame(0, $code);
        self::assertSame('', $output);
        self::assertStringContainsString('CN must match', $error);
    }

    public function testFailedAppVerificationPreventsArchiveCreation(): void
    {
        [$code, $output] = $this->build(['SIGNING_TEST_FAIL_CHECK' => '1'], 'invalid');
        self::assertNotSame(0, $code);
        self::assertSame('', $output);
        self::assertSame([], glob(self::$directory . '/invalid/*.tar.gz'));
    }
}
