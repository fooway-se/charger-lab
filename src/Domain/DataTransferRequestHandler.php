<?php

declare(strict_types=1);

namespace ChargerEmulator\Domain;

use ChargerEmulator\Protocol\OcppCallErrorException;

/**
 * Rejects vendor-specific data transfers that this emulator does not implement.
 */
final class DataTransferRequestHandler
{
    public function transfer(array $payload): array
    {
        $vendorId = $payload['vendorId'] ?? null;
        $messageId = $payload['messageId'] ?? null;
        if (!is_string($vendorId) || $vendorId === '' || strlen($vendorId) > 255
            || (array_key_exists('messageId', $payload)
                && (!is_string($messageId) || $messageId === '' || strlen($messageId) > 50))) {
            throw new OcppCallErrorException('FormationViolation', 'DataTransfer requires a valid vendorId and messageId.');
        }

        return ['status' => 'UnknownVendorId'];
    }
}
