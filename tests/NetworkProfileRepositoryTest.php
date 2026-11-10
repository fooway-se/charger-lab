<?php

declare(strict_types=1);

use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\NetworkProfileRepository;

it('orders configured profiles by NetworkConfigurationPriority and uses the existing charger endpoint for slot zero', function (): void {
    $database = Database::connect(':memory:');
    $charger = (new ChargerRepository($database))->get((new ChargerRepository($database))->createDefault());
    $chargerId = (int) $charger['id'];
    $profiles = new NetworkProfileRepository($database);
    $backup = [
        'ocppVersion' => 'OCPP20',
        'ocppTransport' => 'JSON',
        'ocppCsmsUrl' => 'wss://backup.example/ocpp',
        'messageTimeout' => 30,
        'securityProfile' => 2,
        'ocppInterface' => 'Wired0',
    ];
    $profiles->set($chargerId, $backup, 1);
    $profiles->rememberSuccessful($chargerId, [
        'ocppVersion' => 'OCPP20',
        'ocppTransport' => 'JSON',
        'ocppCsmsUrl' => 'wss://former.example/ocpp',
        'messageTimeout' => 30,
        'securityProfile' => 2,
        'ocppInterface' => 'Wired0',
    ], 'former-user', 'former-password');

    expect($profiles->prioritized($chargerId, $charger, '1,0'))->toBe([
        ['slot' => 1, 'connectionData' => $backup, 'username' => $charger['station_identity'], 'password' => $charger['csms_password']],
        ['slot' => 0, 'connectionData' => [
            'ocppVersion' => 'OCPP20',
            'ocppTransport' => 'JSON',
            'ocppCsmsUrl' => $charger['csms_url'],
            'messageTimeout' => 8,
            'securityProfile' => 1,
            'ocppInterface' => 'Wired0',
        ], 'username' => $charger['station_identity'], 'password' => $charger['csms_password']],
        ['slot' => 'last-successful', 'connectionData' => [
            'ocppVersion' => 'OCPP20',
            'ocppTransport' => 'JSON',
            'ocppCsmsUrl' => 'wss://former.example/ocpp',
            'messageTimeout' => 30,
            'securityProfile' => 2,
            'ocppInterface' => 'Wired0',
        ], 'username' => 'former-user', 'password' => 'former-password'],
    ]);
});

it('omits unavailable slots from the connection attempt order', function (): void {
    $database = Database::connect(':memory:');
    $chargers = new ChargerRepository($database);
    $chargerId = $chargers->createDefault();
    $charger = $chargers->get($chargerId);
    $profiles = new NetworkProfileRepository($database);

    expect($profiles->prioritized($chargerId, $charger, '2,0'))->toHaveCount(1)
        ->and($profiles->prioritized($chargerId, $charger, '2,0')[0]['slot'])->toBe(0);
});

it('persists the last successfully used profile and credentials', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $profiles = new NetworkProfileRepository($database);
    $connectionData = [
        'ocppVersion' => 'OCPP20',
        'ocppTransport' => 'JSON',
        'ocppCsmsUrl' => 'wss://csms.example/ocpp',
        'messageTimeout' => 30,
        'securityProfile' => 2,
        'ocppInterface' => 'Wired0',
    ];

    $profiles->rememberSuccessful($chargerId, $connectionData, 'station-user', 'station-password');

    expect($profiles->lastSuccessful($chargerId))->toBe([
        'connectionData' => $connectionData,
        'username' => 'station-user',
        'password' => 'station-password',
    ]);
});
