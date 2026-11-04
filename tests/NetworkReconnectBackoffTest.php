<?php

declare(strict_types=1);

use ChargerEmulator\Protocol\NetworkReconnectBackoff;

it('doubles the reconnect delay up to the configured repeat count', function (): void {
    expect(NetworkReconnectBackoff::delaySeconds(5, 3, 2, 1, 2))->toBe(7)
        ->and(NetworkReconnectBackoff::delaySeconds(5, 3, 2, 2, 2))->toBe(12)
        ->and(NetworkReconnectBackoff::delaySeconds(5, 3, 2, 3, 2))->toBe(22)
        ->and(NetworkReconnectBackoff::delaySeconds(5, 3, 2, 4, 2))->toBe(22);
});

it('rejects invalid reconnect backoff inputs', function (): void {
    expect(fn () => NetworkReconnectBackoff::delaySeconds(0, 3, 2, 1, 2))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => NetworkReconnectBackoff::delaySeconds(5, 3, 2, 1, 4))
        ->toThrow(InvalidArgumentException::class);
});

it('caps an oversized exponential delay without integer overflow', function (): void {
    expect(NetworkReconnectBackoff::delaySeconds(5, 3, PHP_INT_MAX, PHP_INT_MAX, 2))
        ->toBe(PHP_INT_MAX);
});
