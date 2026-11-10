<?php

declare(strict_types=1);

use ChargerEmulator\Domain\GetLocalListVersionRequestHandler;
use ChargerEmulator\Protocol\OcppCallErrorException;

it('reports version zero when no local authorization list is installed', function (): void {
    expect((new GetLocalListVersionRequestHandler())->getVersion([]))
        ->toBe(['versionNumber' => 0]);
});

it('accepts the optional OCPP customData object', function (): void {
    expect((new GetLocalListVersionRequestHandler())->getVersion([
        'customData' => ['vendorId' => 'com.example.csms'],
    ]))->toBe(['versionNumber' => 0]);
});

it('returns FormationViolation for a GetLocalListVersion payload outside its schema', function (array $payload): void {
    expect(fn () => (new GetLocalListVersionRequestHandler())->getVersion($payload))
        ->toThrow(OcppCallErrorException::class, 'GetLocalListVersion only accepts a valid customData object.');
})->with([
    'unknown property' => [['unexpected' => true]],
    'customData is not an object' => [['customData' => 'invalid']],
    'customData vendor ID is empty' => [['customData' => ['vendorId' => '']]],
    'customData has an unknown property' => [['customData' => ['vendorId' => 'csms', 'extra' => true]]],
]);
