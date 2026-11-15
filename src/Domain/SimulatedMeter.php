<?php

declare(strict_types=1);

namespace ChargerEmulator\Domain;

/**
 * Supplies deterministic readings for the emulator's active power measurand.
 */
final class SimulatedMeter
{
    public const CHARGING_POWER_W = 7200;

    public static function powerForOutlet(array $outlet): int
    {
        if (($outlet['connector_status'] ?? null) !== 'Occupied'
            || !is_string($outlet['transaction_id'] ?? null)
            || $outlet['transaction_id'] === ''
            || ($outlet['id_token'] ?? null) === null
            || ($outlet['stop_reason'] ?? null) !== null
            || ($outlet['suspended_by'] ?? null) !== null
            || ($outlet['remote_start_pending'] ?? false)
            || ($outlet['authorization_pending'] ?? false)) {
            return 0;
        }

        return self::CHARGING_POWER_W;
    }
}
