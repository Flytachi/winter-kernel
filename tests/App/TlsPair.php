<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\App;

/**
 * A self-signed certificate and its key, written to a temporary directory for a test.
 */
final class TlsPair
{
    public function __construct(public readonly string $dir, public readonly string $cert, public readonly string $key)
    {
    }

    /** Null without ext-openssl. */
    public static function create(): ?self
    {
        if (!function_exists('openssl_pkey_new')) {
            return null;
        }
        $dir = sys_get_temp_dir() . '/wk_tls_' . getmypid() . '_' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $pair = new self($dir, $dir . '/cert.pem', $dir . '/key.pem');
        $pair->write('cert.pem', 'key.pem');
        return $pair;
    }

    /** Another certificate with another key, under the given names in the same directory. */
    public function write(string $certName, string $keyName): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'localhost'], $key);
        $x509 = openssl_csr_sign($csr, null, $key, 1);
        openssl_x509_export_to_file($x509, $this->dir . '/' . $certName);
        openssl_pkey_export_to_file($key, $this->dir . '/' . $keyName);
    }

    public function remove(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }
}
