<?php

declare(strict_types=1);

use ChargerEmulator\Domain\DataTransferRequestHandler;
use ChargerEmulator\Protocol\OcppCallErrorException;

it('returns UnknownVendorId when no vendor extension is implemented', function (): void {
    $response = (new DataTransferRequestHandler())->transfer([
        'vendorId' => 'com.example.extension',
        'messageId' => 'ReadValue',
        'data' => ['key' => 'value'],
    ]);

    expect($response)->toBe(['status' => 'UnknownVendorId']);
});

it('returns FormationViolation when DataTransfer has invalid identifiers', function (array $payload): void {
    expect(fn () => (new DataTransferRequestHandler())->transfer($payload))
        ->toThrow(OcppCallErrorException::class, 'DataTransfer requires a valid vendorId and messageId');
})->with([
    'missing vendor' => [['messageId' => 'ReadValue']],
    'empty vendor' => [['vendorId' => '']],
    'vendor too long' => [['vendorId' => str_repeat('v', 256)]],
    'message ID too long' => [['vendorId' => 'com.example.extension', 'messageId' => str_repeat('m', 51)]],
]);
