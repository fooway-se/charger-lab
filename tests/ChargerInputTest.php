<?php

declare(strict_types=1);

use ChargerEmulator\Controller\ChargerInput;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\WorkspaceSettingsRepository;

it('applies workspace CSMS defaults while preserving an existing charger password', function (): void {
    $database = Database::connect(':memory:');
    $chargers = new ChargerRepository($database);
    $settings = new WorkspaceSettingsRepository($database);
    $settings->saveCsmsUrl('wss://new-csms.example/ocpp');
    $chargerId = $chargers->create([
        'station_identity' => 'InputTestStation',
        'csms_url' => 'wss://old-csms.example/ocpp',
        'csms_password' => 'station-secret-123',
        'vendor_name' => 'Test Vendor',
        'model' => 'Test Model',
        'serial_number' => '',
    ]);
    $input = new ChargerInput($chargers, $settings);

    $charger = $input->validatedCharger($chargerId, ['uses_default_csms' => true]);

    expect($charger['csms_url'])->toBe('wss://new-csms.example/ocpp')
        ->and($charger['csms_password'])->toBe('station-secret-123');
});

it('rejects non-WebSocket CSMS URLs at the request boundary', function (): void {
    $database = Database::connect(':memory:');
    $input = new ChargerInput(
        new ChargerRepository($database),
        new WorkspaceSettingsRepository($database),
    );

    expect(fn () => $input->validateCsmsUrl('https://csms.example/ocpp'))
        ->toThrow(InvalidArgumentException::class);
});
