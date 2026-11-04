<?php

declare(strict_types=1);

namespace ChargerEmulator\Domain;

use InvalidArgumentException;

/**
 * Maps physical outlet numbers to OCPP EVSE and connector identifiers.
 */
final class OutletMap
{
    public function __construct(
        private readonly int $outletCount,
        private readonly string $topology,
    ) {
        if ($outletCount < 1 || $outletCount > 16) {
            throw new InvalidArgumentException('Outlet count must be between 1 and 16.');
        }

        if (!in_array($topology, ['independent', 'shared'], true)) {
            throw new InvalidArgumentException('Outlet topology must be independent or shared.');
        }
    }

    /**
     * @return list<array{number:int,evse_id:int,connector_id:int}>
     */
    public function outlets(): array
    {
        $outlets = [];
        for ($number = 1; $number <= $this->outletCount; $number++) {
            $outlets[] = [
                'number' => $number,
                'evse_id' => $this->topology === 'shared' ? 1 : $number,
                'connector_id' => $this->topology === 'shared' ? $number : 1,
            ];
        }

        return $outlets;
    }
}
