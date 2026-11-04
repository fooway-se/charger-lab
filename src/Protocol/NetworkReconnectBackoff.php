<?php

declare(strict_types=1);

namespace ChargerEmulator\Protocol;

use InvalidArgumentException;

/**
 * Calculates the OCPP communication controller delay between reconnect attempts.
 */
final class NetworkReconnectBackoff
{
    public static function delaySeconds(
        int $minimumWaitSeconds,
        int $randomRangeSeconds,
        int $repeatTimes,
        int $attempt,
        int $randomSeconds,
    ): int {
        if ($minimumWaitSeconds < 1 || $randomRangeSeconds < 0 || $repeatTimes < 0
            || $attempt < 1 || $randomSeconds < 0 || $randomSeconds > $randomRangeSeconds) {
            throw new InvalidArgumentException('Invalid OCPP network reconnect backoff values.');
        }

        $doublings = min($attempt - 1, $repeatTimes);
        $factor = 1;
        for ($index = 0; $index < $doublings; $index++) {
            if ($factor > intdiv(PHP_INT_MAX, 2)) {
                return PHP_INT_MAX;
            }

            $factor *= 2;
        }

        if ($minimumWaitSeconds > intdiv(PHP_INT_MAX, $factor)) {
            return PHP_INT_MAX;
        }

        $delay = $minimumWaitSeconds * $factor;
        if ($randomSeconds > PHP_INT_MAX - $delay) {
            return PHP_INT_MAX;
        }

        return $delay + $randomSeconds;
    }
}
