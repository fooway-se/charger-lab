<?php

declare(strict_types=1);

namespace ChargerEmulator\Storage;

use PDO;
use RuntimeException;

/**
 * Persists the Device Model settings that the emulator supports as writable.
 */
final class DeviceModelSettingsRepository
{
    private const DEFAULTS = [
        'Interval' => '0',
        'Measurands' => 'Energy.Active.Import.Register',
        'SendDuringIdle' => 'false',
        'TxUpdatedInterval' => '0',
        'TxUpdatedMeasurands' => 'Energy.Active.Import.Register',
        'TxStartedMeasurands' => 'Energy.Active.Import.Register',
        'TxEndedMeasurands' => 'Energy.Active.Import.Register',
        'TxEndedInterval' => '0',
        'MessageAttemptsTransactionEvent' => '3',
        'MessageAttemptIntervalTransactionEvent' => '60',
        'RetryBackOffWaitMinimum' => '5',
        'RetryBackOffRandomRange' => '5',
        'RetryBackOffRepeatTimes' => '3',
        'HeartbeatInterval' => '300',
        'EVConnectionTimeOut' => '30',
    ];

    public function __construct(private readonly PDO $database)
    {
    }

    public function get(int $chargerId, string $variable): ?string
    {
        if (!array_key_exists($variable, self::DEFAULTS)) {
            return null;
        }

        $statement = $this->database->prepare(<<<'SQL'
            SELECT value FROM device_model_setting
            WHERE charger_id = :charger_id AND variable = :variable
            SQL);
        $statement->execute(['charger_id' => $chargerId, 'variable' => $variable]);
        $value = $statement->fetchColumn();

        return $value === false ? self::DEFAULTS[$variable] : (string) $value;
    }

    public function set(int $chargerId, string $variable, string $value): void
    {
        if (!array_key_exists($variable, self::DEFAULTS)) {
            throw new RuntimeException('The Device Model setting is not writable by this emulator.');
        }

        $statement = $this->database->prepare(<<<'SQL'
            INSERT INTO device_model_setting (charger_id, variable, value)
            VALUES (:charger_id, :variable, :value)
            ON CONFLICT (charger_id, variable) DO UPDATE SET value = excluded.value
            SQL);
        $statement->execute([
            'charger_id' => $chargerId,
            'variable' => $variable,
            'value' => $value,
        ]);
    }
}
