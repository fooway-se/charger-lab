<?php

declare(strict_types=1);

namespace ChargerEmulator\Domain;

/** Validates the required OCPP customData vendor identifier while allowing vendor extensions. */
final class CustomDataValidator
{
    public static function isValid(mixed $customData, bool $present = true): bool
    {
        if (!$present) {
            return true;
        }

        return is_array($customData)
            && !array_is_list($customData)
            && is_string($customData['vendorId'] ?? null)
            && $customData['vendorId'] !== ''
            && strlen($customData['vendorId']) <= 255;
    }
}
