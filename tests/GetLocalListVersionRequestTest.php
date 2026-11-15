<?php

declare(strict_types=1);

use ChargerEmulator\Domain\GetLocalListVersionRequestHandler;
use ChargerEmulator\Protocol\OcppCallErrorException;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\LocalAuthorizationListRepository;

function createLocalListVersionHandler(): array
{
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $lists = new LocalAuthorizationListRepository($database);

    return [$lists, new GetLocalListVersionRequestHandler($chargerId, $lists)];
}

it('reports version zero when no local authorization list is installed', function (): void {
    [, $handler] = createLocalListVersionHandler();

    expect($handler->getVersion([]))
        ->toBe(['versionNumber' => 0]);
});

it('accepts the optional OCPP customData object', function (): void {
    [, $handler] = createLocalListVersionHandler();

    expect($handler->getVersion([
        'customData' => ['vendorId' => 'com.example.csms'],
    ]))->toBe(['versionNumber' => 0]);
});

it('accepts vendor-specific properties in customData', function (): void {
    [, $handler] = createLocalListVersionHandler();

    expect($handler->getVersion([
        'customData' => ['vendorId' => 'com.example.csms', 'traceId' => 'abc-123'],
    ]))->toBe(['versionNumber' => 0]);
});

it('returns FormationViolation for a GetLocalListVersion payload outside its schema', function (array $payload): void {
    [, $handler] = createLocalListVersionHandler();

    expect(fn () => $handler->getVersion($payload))
        ->toThrow(OcppCallErrorException::class, 'GetLocalListVersion only accepts a valid customData object.');
})->with([
    'unknown property' => [['unexpected' => true]],
    'customData is not an object' => [['customData' => 'invalid']],
    'customData vendor ID is empty' => [['customData' => ['vendorId' => '']]],
    'customData has no vendor ID' => [['customData' => ['extra' => true]]],
    'customData is null' => [['customData' => null]],
]);
