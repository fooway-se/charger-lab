<?php

declare(strict_types=1);

namespace ChargerEmulator\Domain;

use ChargerEmulator\Storage\OcppCertificateRepository;
use Throwable;

/** Implements the CSMS-facing certificate management state of the emulated station. */
final class CertificateManagementManager
{
    private const INSTALLABLE_TYPES = [
        'V2GRootCertificate',
        'MORootCertificate',
        'CSMSRootCertificate',
        'ManufacturerRootCertificate',
    ];

    private const QUERYABLE_TYPES = [
        ...self::INSTALLABLE_TYPES,
        'V2GCertificateChain',
    ];

    private const HASH_ALGORITHMS = [
        'SHA256' => 'sha256',
        'SHA384' => 'sha384',
        'SHA512' => 'sha512',
    ];

    public function __construct(private readonly OcppCertificateRepository $certificates)
    {
    }

    public function install(int $chargerId, array $payload): array
    {
        $certificateType = $payload['certificateType'] ?? null;
        $certificate = $payload['certificate'] ?? null;
        if (!$this->validCustomData($payload['customData'] ?? null)
            || !is_string($certificateType) || !in_array($certificateType, self::INSTALLABLE_TYPES, true)
            || !is_string($certificate) || strlen($certificate) > 5500) {
            return ['status' => 'Rejected'];
        }

        $parsed = $this->parseRootCertificate($certificate);
        if ($parsed === null) {
            return ['status' => 'Rejected'];
        }

        try {
            $this->certificates->install(
                $chargerId,
                $certificateType,
                $parsed['pem'],
                hash('sha256', $parsed['der']),
            );
        } catch (Throwable) {
            return ['status' => 'Failed'];
        }

        return ['status' => 'Accepted'];
    }

    public function getInstalledCertificateIds(int $chargerId, array $payload): array
    {
        if (!$this->validCustomData($payload['customData'] ?? null)) {
            return ['status' => 'NotFound'];
        }

        $certificateTypes = null;
        if (array_key_exists('certificateType', $payload)) {
            $certificateTypes = $payload['certificateType'];
            if (!is_array($certificateTypes) || count($certificateTypes) < 1 || count($certificateTypes) > 5) {
                return ['status' => 'NotFound'];
            }
            foreach ($certificateTypes as $certificateType) {
                if (!is_string($certificateType) || !in_array($certificateType, self::QUERYABLE_TYPES, true)) {
                    return ['status' => 'NotFound'];
                }
            }
        }

        $chains = [];
        foreach ($this->certificates->all($chargerId, $certificateTypes) as $certificate) {
            $hashData = $this->certificateHashData((string) $certificate['certificate_pem'], 'SHA256');
            if ($hashData === null) {
                continue;
            }
            $chains[] = [
                'certificateHashData' => $hashData,
                'certificateType' => $certificate['certificate_type'],
            ];
        }

        return $chains === []
            ? ['status' => 'NotFound']
            : ['status' => 'Accepted', 'certificateHashDataChain' => $chains];
    }

    public function delete(int $chargerId, array $payload): array
    {
        $requestedHash = $payload['certificateHashData'] ?? null;
        if (!$this->validCustomData($payload['customData'] ?? null) || !is_array($requestedHash)
            || !$this->validCustomData($requestedHash['customData'] ?? null)) {
            return ['status' => 'Failed'];
        }

        $algorithm = $requestedHash['hashAlgorithm'] ?? null;
        $issuerNameHash = $requestedHash['issuerNameHash'] ?? null;
        $issuerKeyHash = $requestedHash['issuerKeyHash'] ?? null;
        $serialNumber = $requestedHash['serialNumber'] ?? null;
        if (!is_string($algorithm) || !isset(self::HASH_ALGORITHMS[$algorithm])
            || !$this->boundedString($issuerNameHash, 128)
            || !$this->boundedString($issuerKeyHash, 128)
            || !$this->boundedString($serialNumber, 40)) {
            return ['status' => 'Failed'];
        }

        foreach ($this->certificates->all($chargerId) as $certificate) {
            $hashData = $this->certificateHashData((string) $certificate['certificate_pem'], $algorithm);
            if ($hashData === null
                || strcasecmp($hashData['issuerNameHash'], $issuerNameHash) !== 0
                || strcasecmp($hashData['issuerKeyHash'], $issuerKeyHash) !== 0
                || strcasecmp($hashData['serialNumber'], $serialNumber) !== 0) {
                continue;
            }

            return ['status' => $this->certificates->delete((int) $certificate['id'], $chargerId) ? 'Accepted' : 'Failed'];
        }

        return ['status' => 'NotFound'];
    }

    public function manufacturerRootBundle(int $chargerId): string
    {
        return $this->certificates->manufacturerRootBundle($chargerId);
    }

    /** @return array{pem: string, der: string}|null */
    private function parseRootCertificate(string $certificate): ?array
    {
        if (substr_count($certificate, '-----BEGIN CERTIFICATE-----') !== 1) {
            return null;
        }

        $x509 = @openssl_x509_read($certificate);
        if ($x509 === false) {
            return null;
        }
        $details = @openssl_x509_parse($x509, false);
        if (!is_array($details)
            || !is_int($details['validFrom_time_t'] ?? null)
            || !is_int($details['validTo_time_t'] ?? null)
            || $details['validFrom_time_t'] > time()
            || $details['validTo_time_t'] < time()
            || !is_string($details['extensions']['basicConstraints'] ?? null)
            || preg_match('/\bCA\s*:\s*TRUE\b/i', $details['extensions']['basicConstraints']) !== 1) {
            return null;
        }

        $publicKey = @openssl_pkey_get_public($x509);
        $signatureName = (string) ($details['signatureTypeSN'] ?? '');
        $hashAlgorithm = match (true) {
            stripos($signatureName, 'SHA512') !== false => OPENSSL_ALGO_SHA512,
            stripos($signatureName, 'SHA384') !== false => OPENSSL_ALGO_SHA384,
            stripos($signatureName, 'SHA256') !== false => OPENSSL_ALGO_SHA256,
            stripos($signatureName, 'SHA1') !== false => OPENSSL_ALGO_SHA1,
            default => null,
        };
        $derFields = $this->certificateDerFields($certificate);
        if ($publicKey === false || $hashAlgorithm === null || $derFields === null
            || !hash_equals($derFields['issuerName'], $derFields['subjectName'])
            || openssl_verify($derFields['tbs'], $derFields['signature'], $publicKey, $hashAlgorithm) !== 1) {
            return null;
        }

        $pem = '';
        if (!openssl_x509_export($x509, $pem)) {
            return null;
        }
        $der = base64_decode(preg_replace('/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s+/', '', $pem) ?? '', true);
        if (!is_string($der) || $der === '') {
            return null;
        }

        return ['pem' => $pem, 'der' => $der];
    }

    /** @return array{issuerName: string, subjectName: string, publicKeyBits: string, signature: string, tbs: string}|null */
    private function certificateDerFields(string $certificate): ?array
    {
        $der = base64_decode(preg_replace('/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s+/', '', $certificate) ?? '', true);
        if (!is_string($der) || $der === '') {
            return null;
        }

        $offset = 0;
        $outer = $this->readDerElement($der, $offset);
        if ($outer === null || $outer['tag'] !== 0x30 || $outer['end'] !== strlen($der)) {
            return null;
        }
        $outerOffset = $outer['contentStart'];
        $tbs = $this->readDerElement($der, $outerOffset);
        $signatureAlgorithm = $this->readDerElement($der, $outerOffset);
        $signatureBits = $this->readDerElement($der, $outerOffset);
        if ($tbs === null || $tbs['tag'] !== 0x30 || $signatureAlgorithm === null || $signatureAlgorithm['tag'] !== 0x30
            || $signatureBits === null || $signatureBits['tag'] !== 0x03 || $outerOffset !== $outer['end']) {
            return null;
        }

        $tbsOffset = $tbs['contentStart'];
        $firstTbsField = $this->readDerElement($der, $tbsOffset);
        if ($firstTbsField === null) {
            return null;
        }
        $serial = $firstTbsField['tag'] === 0xa0
            ? $this->readDerElement($der, $tbsOffset)
            : $firstTbsField;
        $signature = $this->readDerElement($der, $tbsOffset);
        $issuer = $this->readDerElement($der, $tbsOffset);
        $validity = $this->readDerElement($der, $tbsOffset);
        $subject = $this->readDerElement($der, $tbsOffset);
        $subjectPublicKeyInfo = $this->readDerElement($der, $tbsOffset);
        if ($serial === null || $serial['tag'] !== 0x02 || $signature === null || $issuer === null || $issuer['tag'] !== 0x30
            || $validity === null || $validity['tag'] !== 0x30 || $subject === null || $subject['tag'] !== 0x30
            || $subjectPublicKeyInfo === null || $subjectPublicKeyInfo['tag'] !== 0x30) {
            return null;
        }

        $spkiOffset = $subjectPublicKeyInfo['contentStart'];
        $algorithm = $this->readDerElement($der, $spkiOffset);
        $publicKeyBits = $this->readDerElement($der, $spkiOffset);
        if ($algorithm === null || $algorithm['tag'] !== 0x30 || $publicKeyBits === null || $publicKeyBits['tag'] !== 0x03
            || $publicKeyBits['contentLength'] < 2 || $spkiOffset !== $subjectPublicKeyInfo['end']) {
            return null;
        }

        $signatureContent = substr($der, $signatureBits['contentStart'], $signatureBits['contentLength']);
        if ($signatureContent === '' || ord($signatureContent[0]) !== 0) {
            return null;
        }

        return [
            'issuerName' => substr($der, $issuer['start'], $issuer['end'] - $issuer['start']),
            'subjectName' => substr($der, $subject['start'], $subject['end'] - $subject['start']),
            'publicKeyBits' => substr($der, $publicKeyBits['contentStart'], $publicKeyBits['contentLength']),
            'signature' => substr($signatureContent, 1),
            'tbs' => substr($der, $tbs['start'], $tbs['end'] - $tbs['start']),
        ];
    }

    /** @return array{tag: int, start: int, contentStart: int, contentLength: int, end: int}|null */
    private function readDerElement(string $der, int &$offset): ?array
    {
        $start = $offset;
        $length = strlen($der);
        if ($offset + 2 > $length) {
            return null;
        }

        $tag = ord($der[$offset++]);
        $lengthByte = ord($der[$offset++]);
        if (($lengthByte & 0x80) === 0) {
            $contentLength = $lengthByte;
        } else {
            $lengthBytes = $lengthByte & 0x7f;
            if ($lengthBytes < 1 || $lengthBytes > 4 || $offset + $lengthBytes > $length) {
                return null;
            }
            $contentLength = 0;
            for ($index = 0; $index < $lengthBytes; $index++) {
                $contentLength = ($contentLength * 256) + ord($der[$offset++]);
            }
        }

        $contentStart = $offset;
        $end = $contentStart + $contentLength;
        if ($end > $length) {
            return null;
        }
        $offset = $end;

        return [
            'tag' => $tag,
            'start' => $start,
            'contentStart' => $contentStart,
            'contentLength' => $contentLength,
            'end' => $end,
        ];
    }

    private function certificateHashData(string $certificatePem, string $algorithm): ?array
    {
        $fields = $this->certificateDerFields($certificatePem);
        if ($fields === null || !isset(self::HASH_ALGORITHMS[$algorithm])) {
            return null;
        }

        $x509 = @openssl_x509_read($certificatePem);
        $parsed = $x509 === false ? false : @openssl_x509_parse($x509, false);
        $serialNumber = is_array($parsed) ? ($parsed['serialNumberHex'] ?? null) : null;
        if (!is_string($serialNumber) || $serialNumber === '') {
            return null;
        }
        $serialNumber = strtoupper(ltrim($serialNumber, '0'));
        $serialNumber = $serialNumber === '' ? '0' : $serialNumber;

        return [
            'hashAlgorithm' => $algorithm,
            'issuerNameHash' => hash(self::HASH_ALGORITHMS[$algorithm], $fields['issuerName']),
            'issuerKeyHash' => hash(self::HASH_ALGORITHMS[$algorithm], $fields['publicKeyBits']),
            'serialNumber' => $serialNumber,
        ];
    }

    private function validCustomData(mixed $customData): bool
    {
        return $customData === null
            || (is_array($customData)
                && is_string($customData['vendorId'] ?? null)
                && strlen($customData['vendorId']) <= 255);
    }

    private function boundedString(mixed $value, int $maximumLength): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= $maximumLength;
    }
}
