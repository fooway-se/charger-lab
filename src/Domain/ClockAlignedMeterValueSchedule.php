<?php

declare(strict_types=1);

namespace ChargerEmulator\Domain;

/**
 * Calculates the next UTC clock-aligned meter sampling instant.
 */
final class ClockAlignedMeterValueSchedule
{
    public function nextSampleAt(int $intervalSeconds, int $currentTimestamp): ?int
    {
        if ($intervalSeconds <= 0) {
            return null;
        }

        $month = (int) gmdate('n', $currentTimestamp);
        $day = (int) gmdate('j', $currentTimestamp);
        $year = (int) gmdate('Y', $currentTimestamp);
        $midnight = gmmktime(0, 0, 0, $month, $day, $year);
        $secondsSinceMidnight = $currentTimestamp - $midnight;
        $nextInterval = intdiv($secondsSinceMidnight, $intervalSeconds) + 1;

        return $midnight + ($nextInterval * $intervalSeconds);
    }
}
