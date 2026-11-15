<?php

declare(strict_types=1);

namespace ChargerEmulator\Domain;

/** Verifies the RSA-PSS/SHA-256 signature required for secure firmware updates. */
final class FirmwareSignatureVerifier
{
    public function verifyRsaPss(string $packagePath, string $signature, string $certificatePem): bool
    {
        if (!is_file($packagePath) || $signature === '') {
            return false;
        }

        $certificate = @openssl_x509_read($certificatePem);
        $publicKey = $certificate === false ? false : @openssl_pkey_get_public($certificate);
        $keyDetails = $publicKey === false ? false : @openssl_pkey_get_details($publicKey);
        if (!is_array($keyDetails)
            || ($keyDetails['type'] ?? null) !== OPENSSL_KEYTYPE_RSA
            || !is_string($keyDetails['key'] ?? null)) {
            return false;
        }

        $directory = sys_get_temp_dir() . '/ocpp-firmware-signature-' . bin2hex(random_bytes(12));
        if (!mkdir($directory, 0700)) {
            return false;
        }

        $publicKeyPath = $directory . '/signer-public-key.pem';
        $signaturePath = $directory . '/firmware-signature.bin';
        try {
            if (file_put_contents($publicKeyPath, $keyDetails['key'], LOCK_EX) !== strlen($keyDetails['key'])
                || file_put_contents($signaturePath, $signature, LOCK_EX) !== strlen($signature)
                || !chmod($publicKeyPath, 0600)
                || !chmod($signaturePath, 0600)) {
                return false;
            }

            $pipes = [];
            $process = @proc_open([
                'openssl', 'dgst', '-sha256', '-verify', $publicKeyPath,
                '-signature', $signaturePath,
                '-sigopt', 'rsa_padding_mode:pss',
                '-sigopt', 'rsa_pss_saltlen:digest',
                $packagePath,
            ], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
            if (!is_resource($process)) {
                return false;
            }

            fclose($pipes[0]);
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            return proc_close($process) === 0;
        } finally {
            foreach ([$publicKeyPath, $signaturePath] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($directory);
        }
    }
}
