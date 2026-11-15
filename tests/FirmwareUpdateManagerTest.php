<?php

declare(strict_types=1);

use ChargerEmulator\Domain\FirmwareUpdateManager;
use ChargerEmulator\Domain\FirmwareSignatureVerifier;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\FirmwareUpdateRepository;
use ChargerEmulator\Storage\OcppCertificateRepository;

it('keeps a secure firmware update scheduled until its future retrieve time', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $certificatePem = file_get_contents(__DIR__ . '/fixtures/csms-test-identity.pem');
    $signingRootsPath = __DIR__ . '/fixtures/csms-test-root.pem';
    $certificate = is_string($certificatePem) ? openssl_x509_read($certificatePem) : false;
    $signingKey = is_string($certificatePem) ? openssl_pkey_get_private($certificatePem) : false;
    expect($certificate)->not->toBeFalse()
        ->and($signingKey)->not->toBeFalse();
    openssl_x509_export($certificate, $signingCertificate);
    openssl_sign('future firmware', $signature, $signingKey, OPENSSL_ALGO_SHA256);

    $manager = new FirmwareUpdateManager(
        new FirmwareUpdateRepository($database),
        sys_get_temp_dir() . '/firmware-scheduled-' . bin2hex(random_bytes(6)),
        $signingRootsPath,
        new OcppCertificateRepository($database),
    );
    $retrieveAt = time() + 3_600;
    $response = $manager->requestUpdate($chargerId, [
        'requestId' => 301,
        'firmware' => [
            'location' => 'https://firmware.example.test/firmware.bin',
            'retrieveDateTime' => gmdate('Y-m-d\\TH:i:s\\Z', $retrieveAt),
            'signingCertificate' => $signingCertificate,
            'signature' => base64_encode($signature),
        ],
        'retries' => 0,
        'retryInterval' => 0,
    ]);
    $update = $manager->current($chargerId);

    expect($response['status'] ?? null)->toBe('Accepted')
        ->and($update['state'] ?? null)->toBe('DownloadScheduled')
        ->and((int) ($update['next_action_at'] ?? 0))->toBe($retrieveAt)
        ->and($manager->isDue($update))->toBeFalse()
        ->and((int) ($update['download_attempts'] ?? -1))->toBe(0);
});

it('verifies RSA-PSS firmware signatures and rejects PKCS1 signatures', function (): void {
    $directory = sys_get_temp_dir() . '/firmware-rsa-pss-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700, true);
    $packagePath = $directory . '/firmware.bin';
    $keyPath = $directory . '/signing-key.pem';
    $signaturePath = $directory . '/firmware.sig';
    $package = 'firmware protected with RSA-PSS';
    $identityPem = file_get_contents(__DIR__ . '/fixtures/csms-test-identity.pem');
    $identity = is_string($identityPem) ? openssl_x509_read($identityPem) : false;
    $signingKey = is_string($identityPem) ? openssl_pkey_get_private($identityPem) : false;
    expect($identity)->not->toBeFalse()
        ->and($signingKey)->not->toBeFalse();
    openssl_x509_export($identity, $signingCertificate);
    openssl_pkey_export($signingKey, $privateKeyPem);
    file_put_contents($packagePath, $package);
    file_put_contents($keyPath, $privateKeyPem);
    chmod($keyPath, 0600);

    $process = proc_open([
        'openssl', 'dgst', '-sha256', '-sign', $keyPath,
        '-sigopt', 'rsa_padding_mode:pss',
        '-sigopt', 'rsa_pss_saltlen:digest',
        '-out', $signaturePath, $packagePath,
    ], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    expect(!is_resource($process))->toBeFalse('OpenSSL must be able to create the RSA-PSS test signature.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    expect($exitCode)->toBe(0, 'OpenSSL signature creation failed: ' . $stdout . $stderr);
    $signature = file_get_contents($signaturePath);
    expect(is_string($signature))->toBeTrue();

    openssl_sign($package, $pkcs1Signature, $signingKey, OPENSSL_ALGO_SHA256);
    $verifier = new FirmwareSignatureVerifier();

    try {
        expect($verifier->verifyRsaPss($packagePath, $signature, $signingCertificate))->toBeTrue()
            ->and($verifier->verifyRsaPss($packagePath, $pkcs1Signature, $signingCertificate))->toBeFalse();
    } finally {
        foreach ([$packagePath, $keyPath, $signaturePath] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
    }
});
