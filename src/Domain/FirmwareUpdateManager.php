<?php

declare(strict_types=1);

namespace ChargerEmulator\Domain;

use ChargerEmulator\Storage\FirmwareUpdateRepository;
use ChargerEmulator\Storage\OcppCertificateRepository;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/** Schedules and securely downloads firmware for the simulated charging station. */
final class FirmwareUpdateManager
{
    private const MAX_PACKAGE_BYTES = 67_108_864;

    public function __construct(
        private readonly FirmwareUpdateRepository $updates,
        private readonly string $firmwareDirectory,
        private readonly string $signingRootsPath,
        private readonly ?OcppCertificateRepository $certificateStore = null,
    ) {
    }

    public function requestUpdate(int $chargerId, array $payload): array
    {
        $requestId = $payload['requestId'] ?? null;
        $firmware = $payload['firmware'] ?? null;
        if (array_diff(array_keys($payload), ['customData', 'firmware', 'requestId', 'retries', 'retryInterval']) !== []
            || !$this->validCustomData($payload['customData'] ?? null)
            || !is_int($requestId) || !is_array($firmware) || !$this->validFirmwareRequest($firmware)) {
            return ['status' => 'Rejected'];
        }

        $retries = $payload['retries'] ?? 0;
        $retryInterval = $payload['retryInterval'] ?? 60;
        if (!is_int($retries) || $retries < 0 || $retries > 2_147_483_647
            || !is_int($retryInterval) || $retryInterval < 0 || $retryInterval > 2_147_483_647) {
            return ['status' => 'Rejected'];
        }

        $retrieveAt = $this->timestamp($firmware['retrieveDateTime']);
        $hasInstallDate = array_key_exists('installDateTime', $firmware);
        $installAt = $hasInstallDate ? $this->timestamp($firmware['installDateTime']) : null;
        if ($retrieveAt === null || ($hasInstallDate && $installAt === null)
            || ($installAt !== null && $installAt < $retrieveAt)) {
            return ['status' => 'Rejected'];
        }

        $accepted = $this->updates->schedule(
            $chargerId,
            $requestId,
            $firmware,
            $retrieveAt,
            $installAt,
            $retries,
            $retryInterval,
        );

        return ['status' => $accepted ? 'Accepted' : 'Rejected'];
    }

    public function current(int $chargerId): array
    {
        return $this->updates->current($chargerId);
    }

    public function firmwareVersion(int $chargerId): string
    {
        return $this->updates->firmwareVersion($chargerId);
    }

    public function recoverInterrupted(): void
    {
        $this->updates->recoverInterrupted();
    }

    public function isDue(array $update): bool
    {
        return is_int($update['next_action_at'])
            ? $update['next_action_at'] <= time()
            : (is_numeric($update['next_action_at']) && (int) $update['next_action_at'] <= time());
    }

    public function beginDownload(int $chargerId): void
    {
        $this->updates->beginDownload($chargerId);
    }

    /** Downloads the package, validates the manufacturer chain, then verifies the signature. */
    public function downloadAndVerify(int $chargerId, ?string $downloadCaPem = null): array
    {
        $update = $this->updates->current($chargerId);
        $firmware = $update['firmware_request'];
        if (!is_array($firmware)) {
            throw new RuntimeException('The scheduled firmware request is missing.');
        }

        [$trustedRootsPath, $temporaryRootsPath] = $this->trustedRootsPath($chargerId);
        try {
            $signer = openssl_x509_read($firmware['signingCertificate']);
            if ($signer === false || !is_file($trustedRootsPath)) {
                throw new FirmwareSignatureException(
                    'No valid firmware signer or manufacturer trust root is available.',
                    'InvalidFirmwareSigningCertificate',
                );
            }

            $chainValid = @openssl_x509_checkpurpose($signer, X509_PURPOSE_ANY, [$trustedRootsPath]);
            if ($chainValid !== true) {
                throw new FirmwareSignatureException(
                    'The firmware signing certificate is not trusted.',
                    'InvalidFirmwareSigningCertificate',
                );
            }

            $package = $this->downloadPackage($firmware['location'], $downloadCaPem);
            $signature = base64_decode($firmware['signature'], true);
            $publicKey = openssl_pkey_get_public($signer);
            if (!is_string($signature) || $publicKey === false
                || openssl_verify($package, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
                throw new FirmwareSignatureException('The downloaded firmware signature is invalid.');
            }

            if (!is_dir($this->firmwareDirectory)
                && !mkdir($this->firmwareDirectory, 0700, true)
                && !is_dir($this->firmwareDirectory)) {
                throw new RuntimeException('Could not create the firmware package directory.');
            }

            $path = sprintf('%s/charger-%d-request-%d.bin', $this->firmwareDirectory, $chargerId, $update['request_id']);
            $temporaryPath = tempnam($this->firmwareDirectory, '.firmware-');
            if (!is_string($temporaryPath)) {
                throw new RuntimeException('Could not prepare a temporary firmware package file.');
            }

            try {
                if (file_put_contents($temporaryPath, $package, LOCK_EX) !== strlen($package) || !chmod($temporaryPath, 0600)) {
                    throw new RuntimeException('Could not securely save the verified firmware package.');
                }
                if (!rename($temporaryPath, $path)) {
                    throw new RuntimeException('Could not finalize the verified firmware package.');
                }
            } finally {
                if (is_file($temporaryPath)) {
                    unlink($temporaryPath);
                }
            }

            return [
                'path' => $path,
                'sha256' => hash('sha256', $package),
                'size' => strlen($package),
            ];
        } finally {
            if (is_string($temporaryRootsPath) && is_file($temporaryRootsPath)) {
                unlink($temporaryRootsPath);
            }
        }
    }

    public function recordDownloadedPackage(int $chargerId, array $package): void
    {
        $this->updates->recordDownloadedPackage($chargerId, $package['path'], $package['sha256'], $package['size']);
    }

    public function scheduleInstall(int $chargerId, int $installAt): void
    {
        $this->updates->scheduleInstall($chargerId, $installAt);
    }

    public function beginInstall(int $chargerId, int $nextActionAt): void
    {
        $this->updates->beginInstall($chargerId, $nextActionAt);
    }

    public function prepareReboot(int $chargerId): string
    {
        $update = $this->updates->current($chargerId);
        $sha256 = $update['package_sha256'];
        if (!is_string($sha256) || $sha256 === '') {
            throw new RuntimeException('The verified firmware package is unavailable for installation.');
        }

        // The emulator records the image digest as its simulated version; it never executes
        // or replaces the PHP application with content supplied by a CSMS.
        $version = 'sim-' . substr($sha256, 0, 16);
        $this->updates->prepareReboot($chargerId, $version);

        return $version;
    }

    public function complete(int $chargerId): void
    {
        $this->updates->complete($chargerId);
    }

    public function fail(int $chargerId, string $state, string $reason): bool
    {
        $retry = $state === 'DownloadFailed' && $this->updates->retryDownload($chargerId);
        if (!$retry) {
            $this->updates->fail($chargerId, $state, $reason);
        }

        return $retry;
    }

    private function validFirmwareRequest(array $firmware): bool
    {
        $allowed = ['customData', 'installDateTime', 'location', 'retrieveDateTime', 'signature', 'signingCertificate'];
        $location = $firmware['location'] ?? null;
        try {
            $parts = is_string($location) ? parse_url($location) : false;
        } catch (\ValueError) {
            return false;
        }
        $scheme = is_array($parts) ? ($parts['scheme'] ?? null) : null;
        $signingCertificate = $firmware['signingCertificate'] ?? null;
        $signature = $firmware['signature'] ?? null;

        return array_diff(array_keys($firmware), $allowed) === []
            && $this->validCustomData($firmware['customData'] ?? null)
            && is_string($location)
            && strlen($location) <= 512
            && is_array($parts)
            && $scheme === 'https'
            && isset($parts['host'])
            && !isset($parts['user'], $parts['pass'], $parts['fragment'])
            && preg_match('/[\x00-\x20\x7f]/', $location) !== 1
            && is_string($firmware['retrieveDateTime'] ?? null)
            && (!array_key_exists('installDateTime', $firmware) || is_string($firmware['installDateTime']))
            && is_string($signingCertificate)
            && strlen($signingCertificate) <= 5500
            && is_string($signature)
            && strlen($signature) <= 800
            && base64_decode($signature, true) !== false;
    }

    private function validCustomData(mixed $customData): bool
    {
        return $customData === null
            || (is_array($customData)
                && is_string($customData['vendorId'] ?? null)
                && strlen($customData['vendorId']) <= 255);
    }

    private function timestamp(string $dateTime): ?int
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $dateTime) !== 1) {
            return null;
        }

        try {
            return (new DateTimeImmutable($dateTime))->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }

    private function downloadPackage(string $location, ?string $downloadCaPem): string
    {
        $sslOptions = ['verify_peer' => true, 'verify_peer_name' => true];
        $caPath = null;
        if (is_string($downloadCaPem) && $downloadCaPem !== '') {
            if (!str_contains($downloadCaPem, '-----BEGIN CERTIFICATE-----')
                || openssl_x509_read($downloadCaPem) === false) {
                throw new RuntimeException('The configured TLS CA certificate is invalid PEM X.509 data.');
            }
            $caPath = tempnam(sys_get_temp_dir(), 'firmware-ca-');
            if (!is_string($caPath)) {
                throw new RuntimeException('Could not prepare the firmware download CA certificate.');
            }
            if (file_put_contents($caPath, $downloadCaPem) === false || !chmod($caPath, 0600)) {
                unlink($caPath);
                throw new RuntimeException('Could not prepare the firmware download CA certificate.');
            }
            $sslOptions['cafile'] = $caPath;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 30,
                'follow_location' => 0,
                'ignore_errors' => true,
                'header' => "Accept: application/octet-stream\r\nUser-Agent: Charger-Lab/1.0\r\n",
            ],
            'ssl' => $sslOptions,
        ]);
        $handle = @fopen($location, 'rb', false, $context);
        if (!is_resource($handle)) {
            if (is_string($caPath) && is_file($caPath)) {
                unlink($caPath);
            }
            throw new RuntimeException('Could not open the firmware download URL.');
        }

        try {
            $metadata = stream_get_meta_data($handle);
            if (!$this->isHttpSuccess($metadata['wrapper_data'] ?? [])) {
                throw new RuntimeException('The firmware server did not return HTTP 200.');
            }
            $package = '';
            while (!feof($handle)) {
                $chunk = fread($handle, 65_536);
                if ($chunk === false) {
                    throw new RuntimeException('The firmware download stream failed.');
                }
                if ($chunk === '' && !feof($handle)) {
                    $streamState = stream_get_meta_data($handle);
                    $reason = ($streamState['timed_out'] ?? false)
                        ? 'The firmware download timed out.'
                        : 'The firmware download stream stopped before EOF.';
                    throw new RuntimeException($reason);
                }
                $package .= $chunk;
                if (strlen($package) > self::MAX_PACKAGE_BYTES) {
                    throw new RuntimeException('The firmware package exceeds the 64 MiB simulator limit.');
                }
            }
            if ($package === '') {
                throw new RuntimeException('The firmware package is empty.');
            }

            return $package;
        } finally {
            fclose($handle);
            if (is_string($caPath) && is_file($caPath)) {
                unlink($caPath);
            }
        }
    }

    private function isHttpSuccess(mixed $headers): bool
    {
        if (!is_array($headers)) {
            return false;
        }
        $statusCode = null;
        foreach ($headers as $header) {
            if (is_string($header) && preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})\b/', $header, $matches) === 1) {
                $statusCode = (int) $matches[1];
            }
        }

        return $statusCode === 200;
    }

    /** @return array{0: string, 1: ?string} */
    private function trustedRootsPath(int $chargerId): array
    {
        $installedRoots = $this->certificateStore?->manufacturerRootBundle($chargerId) ?? '';
        if (trim($installedRoots) === '') {
            return [$this->signingRootsPath, null];
        }

        $configuredRoots = is_file($this->signingRootsPath) ? file_get_contents($this->signingRootsPath) : '';
        if (!is_string($configuredRoots)) {
            $configuredRoots = '';
        }
        $temporaryRootsPath = tempnam(sys_get_temp_dir(), 'firmware-roots-');
        if (!is_string($temporaryRootsPath)) {
            throw new RuntimeException('Could not prepare the simulated manufacturer certificate store.');
        }
        $bundle = trim($configuredRoots . "\n" . $installedRoots) . "\n";
        if (file_put_contents($temporaryRootsPath, $bundle, LOCK_EX) !== strlen($bundle)
            || !chmod($temporaryRootsPath, 0600)) {
            unlink($temporaryRootsPath);
            throw new RuntimeException('Could not prepare the simulated manufacturer certificate store.');
        }

        return [$temporaryRootsPath, $temporaryRootsPath];
    }
}
