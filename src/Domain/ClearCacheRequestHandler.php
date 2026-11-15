<?php

declare(strict_types=1);

namespace ChargerEmulator\Domain;

use ChargerEmulator\Protocol\OcppCallErrorException;

/**
 * Clears the authorization cache; this station currently keeps no cached tokens.
 */
final class ClearCacheRequestHandler
{
    public function clear(array $payload): array
    {
        if (array_diff(array_keys($payload), ['customData']) !== []
            || !CustomDataValidator::isValid($payload['customData'] ?? null, array_key_exists('customData', $payload))) {
            throw new OcppCallErrorException('FormationViolation', 'ClearCache only accepts a valid customData object.');
        }

        return ['status' => 'Accepted'];
    }
}
