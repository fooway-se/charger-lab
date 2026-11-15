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
        'ActiveNetworkProfile' => '0',
        'AlignedTxEndedInterval' => '0',
        'AlignedTxEndedMeasurands' => 'Energy.Active.Import.Register,Power.Active.Import',
        'Interval' => '0',
        'Measurands' => 'Energy.Active.Import.Register,Power.Active.Import',
        'SendDuringIdle' => 'false',
        'TxUpdatedInterval' => '0',
        'TxUpdatedMeasurands' => 'Energy.Active.Import.Register,Power.Active.Import',
        'TxStartedMeasurands' => 'Energy.Active.Import.Register,Power.Active.Import',
        'TxEndedMeasurands' => 'Energy.Active.Import.Register,Power.Active.Import',
        'TxEndedInterval' => '0',
        'MessageAttemptsTransactionEvent' => '3',
        'MessageAttemptIntervalTransactionEvent' => '60',
        'LocalAuthorizeOffline' => 'false',
        'LocalPreAuthorize' => 'false',
        'OrganizationName' => 'ChargerEmulator',
        'ResetRetries' => '3',
        'RetryBackOffWaitMinimum' => '5',
        'RetryBackOffRandomRange' => '5',
        'RetryBackOffRepeatTimes' => '3',
        'OfflineThreshold' => '300',
        'HeartbeatInterval' => '300',
        'NetworkConfigurationPriority' => '0',
        'NetworkProfileConnectionAttempts' => '3',
        'EVConnectionTimeOut' => '30',
        'StopTxOnEVSideDisconnect' => 'true',
        'StopTxOnInvalidId' => 'false',
        'TimeSource' => 'Heartbeat,RealTimeClock',
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

    public function getInteger(int $chargerId, string $variable, int $default, int $minimum): int
    {
        $value = filter_var($this->get($chargerId, $variable), FILTER_VALIDATE_INT);

        return is_int($value) && $value >= $minimum ? $value : $default;
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
