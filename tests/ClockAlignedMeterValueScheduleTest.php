<?php

declare(strict_types=1);

use ChargerEmulator\Domain\ClockAlignedMeterValueSchedule;

it('schedules meter readings on interval boundaries from midnight', function (): void {
    $schedule = new ClockAlignedMeterValueSchedule();

    expect($schedule->nextSampleAt(300, gmmktime(12, 2, 17, 11, 5, 2026)))
        ->toBe(gmmktime(12, 5, 0, 11, 5, 2026));
});

it('advances to the next day boundary when the last interval has passed', function (): void {
    $schedule = new ClockAlignedMeterValueSchedule();

    expect($schedule->nextSampleAt(300, gmmktime(23, 59, 59, 11, 5, 2026)))
        ->toBe(gmmktime(0, 0, 0, 11, 6, 2026));
});

it('does not schedule clock-aligned samples when the interval is disabled', function (): void {
    $schedule = new ClockAlignedMeterValueSchedule();

    expect($schedule->nextSampleAt(0, gmmktime(12, 0, 0, 11, 5, 2026)))->toBeNull();
});
