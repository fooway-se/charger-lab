<?php

declare(strict_types=1);

namespace ChargerEmulator\Domain;

/**
 * Defines the sampled measurands supported by the simulated meter.
 */
final class MeterMeasurands
{
    public const ENERGY_REGISTER = 'Energy.Active.Import.Register';

    public const ACTIVE_IMPORT_POWER = 'Power.Active.Import';

    public const SUPPORTED_LIST = self::ENERGY_REGISTER . ',' . self::ACTIVE_IMPORT_POWER;

    /**
     * @return list<string>|null Null when the MemberList contains an unsupported or duplicate member.
     */
    public static function parse(string $value): ?array
    {
        if ($value === '') {
            return [];
        }

        $measurands = explode(',', $value);
        if (count($measurands) !== count(array_unique($measurands))) {
            return null;
        }

        foreach ($measurands as $measurand) {
            if (!in_array($measurand, [self::ENERGY_REGISTER, self::ACTIVE_IMPORT_POWER], true)) {
                return null;
            }
        }

        return $measurands;
    }
}
