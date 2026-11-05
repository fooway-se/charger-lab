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

it('persists a charger password without exposing it in station state', function (): void {
    $directory = sys_get_temp_dir() . '/charger-password-' . bin2hex(random_bytes(6));
    mkdir($directory);
    $databasePath = $directory . '/emulator.sqlite';
    $database = Database::connect($databasePath);
    $chargers = new ChargerRepository($database);
    $chargerId = $chargers->create([
        'station_identity' => 'PasswordStation',
        'csms_url' => 'wss://csms.example/ocpp',
        'csms_password' => 'station-secret-123',
        'vendor_name' => 'Test Vendor',
        'model' => 'Test Model',
        'serial_number' => '',
    ]);
    unset($chargers, $database);

    $database = Database::connect($databasePath);
    $chargers = new ChargerRepository($database);
    $charger = $chargers->get($chargerId);
    $controller = new StationController(
        $chargers,
        new ExchangeRepository($database),
        new WorkspaceSettingsRepository($database),
        new OcppCommandRepository($database),
        new OutletSessionRepository($database),
        new AvailabilityRepository($database),
        new ResetRepository($database, new OutletSessionRepository($database)),
    );
    $stateResponse = $controller->state(new Request('GET', '/api/state', [], [], []));
    $state = json_decode($stateResponse->body, true, flags: JSON_THROW_ON_ERROR);
    $saveResponse = $controller->save(new Request('POST', '/api/charger', [], [], [
        'charger_id' => $chargerId,
        'station_identity' => 'PasswordStation',
        'vendor_name' => 'Test Vendor',
        'model' => 'Test Model',
        'serial_number' => '',
        'clear_csms_password' => false,
    ]));
    $saveResult = json_decode($saveResponse->body, true, flags: JSON_THROW_ON_ERROR);
    $savedCharger = $chargers->get($chargerId);
    $clearResponse = $controller->save(new Request('POST', '/api/charger', [], [], [
        'charger_id' => $chargerId,
        'clear_csms_password' => true,
    ]));
    $clearResult = json_decode($clearResponse->body, true, flags: JSON_THROW_ON_ERROR);

    expect($charger['csms_password'] ?? null)->toBe('station-secret-123')
        ->and($state['chargers'][0]['has_password'] ?? null)->toBeTrue()
        ->and($stateResponse->body)->not->toContain('station-secret-123')
        ->and($saveResult['charger']['has_password'] ?? null)->toBeTrue()
        ->and($saveResponse->body)->not->toContain('station-secret-123')
        ->and($savedCharger['csms_password'] ?? null)->toBe('station-secret-123')
        ->and($clearResult['charger']['has_password'] ?? null)->toBeFalse()
        ->and($chargers->get($chargerId)['csms_password'] ?? null)->toBe('');

    unset($controller, $chargers, $database);
    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }

    rmdir($directory);
});

it('requires at least 16 password characters for TLS WebSocket connections', function (): void {
    $database = Database::connect(':memory:');
    $chargers = new ChargerRepository($database);
    $chargerId = $chargers->createDefault();
    $controller = new StationController(
        $chargers,
        new ExchangeRepository($database),
        new WorkspaceSettingsRepository($database),
        new OcppCommandRepository($database),
        new OutletSessionRepository($database),
        new AvailabilityRepository($database),
        new ResetRepository($database, new OutletSessionRepository($database)),
    );

    $response = $controller->save(new Request('POST', '/api/charger', [], [], [
        'charger_id' => $chargerId,
        'station_identity' => 'DemoCharger',
        'csms_url' => 'wss://csms.example/ocpp',
        'csms_password' => 'short-password',
        'vendor_name' => 'Local Emulator',
        'model' => 'Developer Charger',
        'serial_number' => 'DEMO-001',
        'outlet_count' => '1',
        'outlet_topology' => 'independent',
        'uses_default_csms' => false,
    ]));

    expect($response->status)->toBe(422)
        ->and($response->body)->toContain('at least 16 characters');
});
