<?php

declare(strict_types=1);

use ChargerEmulator\Controller\StationController;
use ChargerEmulator\Http\Request;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\AvailabilityRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\ExchangeRepository;
use ChargerEmulator\Storage\OcppCommandRepository;
use ChargerEmulator\Storage\OutletSessionRepository;
use ChargerEmulator\Storage\ResetRepository;
use ChargerEmulator\Storage\WorkspaceSettingsRepository;

it('validates station inputs and gates early boot retries', function (): void {
    $directory = sys_get_temp_dir() . '/charger-controller-' . bin2hex(random_bytes(6));

    mkdir($directory, 0775, true);

    $databasePath = $directory . '/emulator.sqlite';

    $database = Database::connect($databasePath);

    $chargers = new ChargerRepository($database);
    $outletSessions = new OutletSessionRepository($database);

    $controller = new StationController(
        $chargers,
        new ExchangeRepository($database),
        new WorkspaceSettingsRepository($database),
        new OcppCommandRepository($database),
        $outletSessions,
        new AvailabilityRepository($database),
        new ResetRepository($database, $outletSessions),
    );

    $response = $controller->save(new Request('POST', '/api/charger', [], [], [
        'station_identity' => 'InvalidStation',
        'csms_url' => 'ws://localhost:9000/ocpp/InvalidStation',
        'vendor_name' => 'Vendor',
        'model' => '',
        'serial_number' => '',
    ]));

    $responseBody = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

    $chargerCount = (int) $database->query('SELECT COUNT(*) FROM charger')->fetchColumn();

    expect($response->status !== 422 || !isset($responseBody['error']) || $chargerCount !== 0)->toBeFalse('Invalid station details should return 422 without persisting a charger.');

    $topologyResponse = $controller->save(new Request('POST', '/api/charger', [], [], [
        'station_identity' => 'InvalidTopology',
        'csms_url' => 'ws://localhost:9000/ocpp/InvalidTopology',
        'vendor_name' => 'Vendor',
        'model' => 'Model',
        'serial_number' => '',
        'outlet_count' => 2,
        'outlet_topology' => 'parallel',
    ]));

    $chargerCount = (int) $database->query('SELECT COUNT(*) FROM charger')->fetchColumn();

    expect($topologyResponse->status !== 422 || $chargerCount !== 0)->toBeFalse('Unknown outlet topology values should be rejected before persistence.');

    $firstId = $chargers->createDefault();

    $secondId = $chargers->create([
        'station_identity' => 'SecondCharger',
        'csms_url' => 'ws://localhost:9000/ocpp/SecondCharger',
        'vendor_name' => 'Vendor',
        'model' => 'Model',
        'serial_number' => 'SECOND-001',
    ]);

    $duplicateIdentityResponse = $controller->save(new Request('POST', '/api/charger', [], [], [
        'charger_id' => $firstId,
        'station_identity' => 'SecondCharger',
        'csms_url' => 'ws://localhost:9000/ocpp/DemoCharger',
        'vendor_name' => 'Vendor',
        'model' => 'Model',
        'serial_number' => 'DEMO-001',
    ]));

    expect($duplicateIdentityResponse->status !== 422 || $chargers->get($firstId)['station_identity'] === 'SecondCharger' || $chargers->get($secondId) === null)->toBeFalse('Duplicate station identities should return 422 without changing either charger.');

    $chargers->setBootStatus($firstId, 'Pending', gmdate(DATE_ATOM, time() + 60));

    $pendingBootResponse = $controller->boot(new Request('POST', '/api/boot', [], [], [
        'charger_id' => $firstId,
        'station_identity' => 'DemoCharger',
        'csms_url' => 'ws://localhost:9000/ocpp/DemoCharger',
        'vendor_name' => 'Vendor',
        'model' => 'Model',
        'serial_number' => 'DEMO-001',
        'outlet_count' => 1,
        'outlet_topology' => 'independent',
    ]));

    $queuedCommands = (int) $database->query("SELECT COUNT(*) FROM ocpp_command WHERE charger_id = {$firstId}")->fetchColumn();

    expect($pendingBootResponse->status !== 409 || $queuedCommands !== 0)->toBeFalse('A Pending BootNotification retry should wait for its persisted CSMS interval.');

    unset($controller, $database);

    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }

    rmdir($directory);

});

it('exposes CSMS availability changes to the test bench state', function (): void {
    $database = Database::connect(':memory:');
    $chargers = new ChargerRepository($database);
    $chargerId = $chargers->createDefault();
    $outlets = new OutletSessionRepository($database);
    $outlets->initialize($chargerId, [['number' => 1, 'evse_id' => 1, 'connector_id' => 1]]);
    $availability = new AvailabilityRepository($database);
    $availability->requestChange($chargerId, [
        'evse' => ['id' => 1],
        'operationalStatus' => 'Inoperative',
    ]);
    $controller = new StationController(
        $chargers,
        new ExchangeRepository($database),
        new WorkspaceSettingsRepository($database),
        new OcppCommandRepository($database),
        $outlets,
        $availability,
        new ResetRepository($database, $outlets),
    );

    $response = $controller->state(new Request('GET', '/api/state', [], [], []));
    $state = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
    $chargers->setBootStatus($chargerId, 'Accepted');
    $chargers->setConnectionStatus($chargerId, 'Connected');
    $plugResponse = $controller->plugIn(new Request('POST', '/api/outlet/plug-in', [], [], [
        'charger_id' => $chargerId,
        'outlet_number' => 1,
    ]));

    expect($state['chargers'][0]['outlets'][0]['connector_status'] ?? null)->toBe('Unavailable')
        ->and($plugResponse->status)->toBe(409);
});

it('shows idle outlets as unavailable and blocks plug-in while reset is scheduled', function (): void {
    $database = Database::connect(':memory:');
    $chargers = new ChargerRepository($database);
    $chargerId = $chargers->create([
        'station_identity' => 'ScheduledResetStation',
        'csms_url' => 'ws://localhost:9000/ocpp',
        'vendor_name' => 'Test Vendor',
        'model' => 'Test Model',
        'serial_number' => '',
        'outlet_count' => 2,
        'outlet_topology' => 'independent',
    ]);
    $outlets = new OutletSessionRepository($database);
    $outlets->initialize($chargerId, [
        ['number' => 1, 'evse_id' => 1, 'connector_id' => 1],
        ['number' => 2, 'evse_id' => 2, 'connector_id' => 1],
    ]);
    $outlets->markOccupied($chargerId, 1, 1, 'active-on-idle-reset');
    $resets = new ResetRepository($database, $outlets);
    expect($resets->request($chargerId, ['type' => 'OnIdle']))->toBe('Scheduled');
    $chargers->setBootStatus($chargerId, 'Accepted');
    $chargers->setConnectionStatus($chargerId, 'Connected');
    $controller = new StationController(
        $chargers,
        new ExchangeRepository($database),
        new WorkspaceSettingsRepository($database),
        new OcppCommandRepository($database),
        $outlets,
        new AvailabilityRepository($database),
        $resets,
    );

    $stateResponse = $controller->state(new Request('GET', '/api/state', [], [], []));
    $state = json_decode($stateResponse->body, true, flags: JSON_THROW_ON_ERROR);
    $plugResponse = $controller->plugIn(new Request('POST', '/api/outlet/plug-in', [], [], [
        'charger_id' => $chargerId,
        'outlet_number' => 2,
    ]));

    expect($state['chargers'][0]['outlets'][1]['connector_status'] ?? null)->toBe('Unavailable')
        ->and($state['chargers'][0]['outlets'][1]['can_start_transaction'] ?? null)->toBeFalse()
        ->and($plugResponse->status)->toBe(409);
});
