<?php

declare(strict_types=1);

use ChargerEmulator\Domain\SetNetworkProfileRequestHandler;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\NetworkProfileRepository;

function networkProfileHandler(): array
{
    $database = Database::connect(':memory:');
    (new ChargerRepository($database))->createDefault();
    $repository = new NetworkProfileRepository($database);

    return [new SetNetworkProfileRequestHandler($repository), $repository];
}

function networkProfileRequest(array $overrides = []): array
{
    return [
        'configurationSlot' => 1,
        'connectionData' => [
            'ocppVersion' => 'OCPP20',
            'ocppTransport' => 'JSON',
            'ocppCsmsUrl' => 'wss://csms.example/ocpp',
            'messageTimeout' => 30,
            'securityProfile' => 2,
            'ocppInterface' => 'Wired0',
        ],
        ...$overrides,
    ];
}

it('persists a supported OCPP 2.0.1 network profile in its configuration slot', function (): void {
    [$handler, $repository] = networkProfileHandler();
    $request = networkProfileRequest();

    expect($handler->setNetworkProfile($request))->toBe(['status' => 'Accepted'])
        ->and($repository->get(1))->toBe($request['connectionData']);
});

it('rejects a network profile security downgrade from the active profile', function (): void {
    $database = Database::connect(':memory:');
    (new ChargerRepository($database))->createDefault();
    $repository = new NetworkProfileRepository($database);
    $secureProfile = networkProfileRequest()['connectionData'];
    $repository->set(1, $secureProfile, 1);
    $handler = new SetNetworkProfileRequestHandler($repository, 1, 2);
    $downgrade = networkProfileRequest(['connectionData' => [
        ...$secureProfile,
        'ocppCsmsUrl' => 'ws://csms.example/ocpp',
        'securityProfile' => 1,
    ]]);

    expect($handler->setNetworkProfile($downgrade))->toBe(['status' => 'Rejected'])
        ->and($repository->get(1))->toBe($secureProfile);
});

it('rejects unsupported network profiles without overwriting a stored profile', function (array $connectionData): void {
    [$handler, $repository] = networkProfileHandler();
    $original = networkProfileRequest()['connectionData'];
    $repository->set(1, $original);
    $request = networkProfileRequest(['connectionData' => $connectionData]);

    expect($handler->setNetworkProfile($request))->toBe(['status' => 'Rejected'])
        ->and($repository->get(1))->toBe($original);
})->with([
    'wrong OCPP version' => ['connectionData' => ['ocppVersion' => 'OCPP16', 'ocppTransport' => 'JSON', 'ocppCsmsUrl' => 'wss://csms.example/ocpp', 'messageTimeout' => 30, 'securityProfile' => 2, 'ocppInterface' => 'Wired0']],
    'wrong transport' => ['connectionData' => ['ocppVersion' => 'OCPP20', 'ocppTransport' => 'SOAP', 'ocppCsmsUrl' => 'wss://csms.example/ocpp', 'messageTimeout' => 30, 'securityProfile' => 2, 'ocppInterface' => 'Wired0']],
    'security profile mismatch' => ['connectionData' => ['ocppVersion' => 'OCPP20', 'ocppTransport' => 'JSON', 'ocppCsmsUrl' => 'wss://csms.example/ocpp', 'messageTimeout' => 30, 'securityProfile' => 1, 'ocppInterface' => 'Wired0']],
    'client certificate profile' => ['connectionData' => ['ocppVersion' => 'OCPP20', 'ocppTransport' => 'JSON', 'ocppCsmsUrl' => 'wss://csms.example/ocpp', 'messageTimeout' => 30, 'securityProfile' => 3, 'ocppInterface' => 'Wired0']],
    'cellular APN' => ['connectionData' => ['ocppVersion' => 'OCPP20', 'ocppTransport' => 'JSON', 'ocppCsmsUrl' => 'wss://csms.example/ocpp', 'messageTimeout' => 30, 'securityProfile' => 2, 'ocppInterface' => 'Wired0', 'apn' => ['apn' => 'internet']]],
    'VPN' => ['connectionData' => ['ocppVersion' => 'OCPP20', 'ocppTransport' => 'JSON', 'ocppCsmsUrl' => 'wss://csms.example/ocpp', 'messageTimeout' => 30, 'securityProfile' => 2, 'ocppInterface' => 'Wired0', 'vpn' => ['type' => 'IKEv2']]],
    'unsupported interface' => ['connectionData' => ['ocppVersion' => 'OCPP20', 'ocppTransport' => 'JSON', 'ocppCsmsUrl' => 'wss://csms.example/ocpp', 'messageTimeout' => 30, 'securityProfile' => 2, 'ocppInterface' => 'Wireless0']],
    'invalid URL scheme' => ['connectionData' => ['ocppVersion' => 'OCPP20', 'ocppTransport' => 'JSON', 'ocppCsmsUrl' => 'https://csms.example/ocpp', 'messageTimeout' => 30, 'securityProfile' => 2, 'ocppInterface' => 'Wired0']],
    'invalid URL port' => ['connectionData' => ['ocppVersion' => 'OCPP20', 'ocppTransport' => 'JSON', 'ocppCsmsUrl' => 'wss://csms.example:70000/ocpp', 'messageTimeout' => 30, 'securityProfile' => 2, 'ocppInterface' => 'Wired0']],
    'unknown profile field' => ['connectionData' => ['ocppVersion' => 'OCPP20', 'ocppTransport' => 'JSON', 'ocppCsmsUrl' => 'wss://csms.example/ocpp', 'messageTimeout' => 30, 'securityProfile' => 2, 'ocppInterface' => 'Wired0', 'identity' => 'user']],
]);

it('rejects invalid configuration slots and required profile values', function (array $request): void {
    [$handler] = networkProfileHandler();

    expect($handler->setNetworkProfile($request))->toBe(['status' => 'Rejected']);
})->with([
    'negative slot' => ['request' => ['configurationSlot' => -1, 'connectionData' => ['ocppVersion' => 'OCPP20', 'ocppTransport' => 'JSON', 'ocppCsmsUrl' => 'wss://csms.example/ocpp', 'messageTimeout' => 30, 'securityProfile' => 2, 'ocppInterface' => 'Wired0']]],
    'unsupported slot' => ['request' => ['configurationSlot' => 3, 'connectionData' => ['ocppVersion' => 'OCPP20', 'ocppTransport' => 'JSON', 'ocppCsmsUrl' => 'wss://csms.example/ocpp', 'messageTimeout' => 30, 'securityProfile' => 2, 'ocppInterface' => 'Wired0']]],
    'missing profile' => ['request' => ['configurationSlot' => 1]],
    'zero timeout' => ['request' => ['configurationSlot' => 1, 'connectionData' => ['ocppVersion' => 'OCPP20', 'ocppTransport' => 'JSON', 'ocppCsmsUrl' => 'wss://csms.example/ocpp', 'messageTimeout' => 0, 'securityProfile' => 2, 'ocppInterface' => 'Wired0']]],
]);
