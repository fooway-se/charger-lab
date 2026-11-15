<?php

declare(strict_types=1);

use ChargerEmulator\Domain\ClearCacheRequestHandler;
use ChargerEmulator\Protocol\OcppCallErrorException;

it('accepts ClearCache when the station has no authorization cache', function (): void {
    expect((new ClearCacheRequestHandler())->clear([]))->toBe(['status' => 'Accepted']);
});

it('accepts the optional OCPP customData object', function (): void {
    expect((new ClearCacheRequestHandler())->clear([
        'customData' => ['vendorId' => 'com.example.csms'],
    ]))->toBe(['status' => 'Accepted']);
});

it('accepts vendor-specific properties inside customData', function (): void {
    expect((new ClearCacheRequestHandler())->clear([
        'customData' => ['vendorId' => 'com.example.csms', 'traceId' => ['id' => 'abc-123']],
    ]))->toBe(['status' => 'Accepted']);
});

it('returns FormationViolation for a ClearCache payload outside its schema', function (array $payload): void {
    expect(fn () => (new ClearCacheRequestHandler())->clear($payload))
        ->toThrow(OcppCallErrorException::class, 'ClearCache only accepts a valid customData object.');
})->with([
    'unknown property' => [['unexpected' => true]],
    'customData is not an object' => [['customData' => 'invalid']],
    'customData vendor ID is empty' => [['customData' => ['vendorId' => '']]],
    'customData vendor ID is too long' => [['customData' => ['vendorId' => str_repeat('v', 256)]]],
    'customData is null' => [['customData' => null]],
]);
