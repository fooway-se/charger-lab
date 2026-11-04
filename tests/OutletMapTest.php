<?php

declare(strict_types=1);

use ChargerEmulator\Domain\OutletMap;

it('maps independent and shared outlets to EVSE IDs', function (): void {
    $independent = new OutletMap(2, 'independent');

    expect($independent->outlets() !== [
        ['number' => 1, 'evse_id' => 1, 'connector_id' => 1],
        ['number' => 2, 'evse_id' => 2, 'connector_id' => 1],
    ])->toBeFalse('Independent outlets should each have their own EVSE and connector 1.');

    $shared = new OutletMap(2, 'shared');

    expect($shared->outlets() !== [
        ['number' => 1, 'evse_id' => 1, 'connector_id' => 1],
        ['number' => 2, 'evse_id' => 1, 'connector_id' => 2],
    ])->toBeFalse('Shared-power outlets should use one EVSE and distinct connector IDs.');

    expect(fn () => new OutletMap(0, 'independent'))->toThrow(InvalidArgumentException::class);
});
