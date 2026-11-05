<?php

declare(strict_types=1);

use ChargerEmulator\Controller\StationController;
use ChargerEmulator\Domain\OutletMap;
use ChargerEmulator\Http\Request;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\AvailabilityRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\ExchangeRepository;
use ChargerEmulator\Storage\OcppCommandRepository;
use ChargerEmulator\Storage\OutletSessionRepository;
use ChargerEmulator\Storage\WorkspaceSettingsRepository;

it('exposes charging state only for an authorized outlet still offering energy', function (): void {
    $directory = sys_get_temp_dir() . '/charger-indicator-' . bin2hex(random_bytes(6));
    $databasePath = $directory . '/emulator.sqlite';
    $database = Database::connect($databasePath);
    $chargers = new ChargerRepository($database);
    $chargerId = $chargers->create([
        'station_identity' => 'IndicatorTestCharger',
        'csms_url' => 'ws://localhost:9000/ocpp',
        'vendor_name' => 'Test Vendor',
        'model' => 'Test Model',
        'serial_number' => '',
        'outlet_count' => 4,
        'outlet_topology' => 'independent',
    ]);
    $outletSessions = new OutletSessionRepository($database);
    $outletSessions->initialize($chargerId, (new OutletMap(4, 'independent'))->outlets());
    $outletSessions->markOccupied($chargerId, 1, 1, 'authorized-transaction');
    $outletSessions->attachToken($chargerId, 1, 1, 'authorized-transaction', 'TOKEN-1', 1);
    $outletSessions->markOccupied($chargerId, 2, 1, 'unauthorized-transaction');
    $outletSessions->beginRemoteStart(
        $chargerId,
        3,
        1,
        'waiting-transaction',
        'TOKEN-3',
        gmdate(DATE_ATOM, time() + 30),
    );
    $outletSessions->markOccupied($chargerId, 4, 1, 'stopped-transaction');
    $outletSessions->attachToken($chargerId, 4, 1, 'stopped-transaction', 'TOKEN-4', 1);
    $outletSessions->markRemoteStopRequested($chargerId, 4, 1, 'stopped-transaction', 2);
    $controller = new StationController(
        $chargers,
        new ExchangeRepository($database),
        new WorkspaceSettingsRepository($database),
        new OcppCommandRepository($database),
        $outletSessions,
        new AvailabilityRepository($database),
    );

    $response = $controller->state(new Request('GET', '/api/state', [], [], []));
    $state = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
    $chargingByEvse = [];
    foreach ($state['chargers'][0]['outlets'] as $outlet) {
        $chargingByEvse[$outlet['evse_id']] = $outlet['is_charging'] ?? null;
    }

    expect($response->status)->toBe(200)
        ->and($chargingByEvse)->toBe([
            1 => true,
            2 => false,
            3 => false,
            4 => false,
        ]);

    unset($controller, $outletSessions, $chargers, $database);
    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }

    rmdir($directory);
});
