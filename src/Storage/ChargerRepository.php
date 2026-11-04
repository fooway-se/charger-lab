<?php

declare(strict_types=1);

namespace ChargerEmulator\Storage;

use PDO;
use RuntimeException;

/**
 * Reads and writes configured charging stations.
 */
final class ChargerRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $chargers = $this->database->query('SELECT * FROM charger ORDER BY id')->fetchAll();
        if ($chargers === []) {
            $defaultUrl = (string) $this->database->query('SELECT default_csms_url FROM workspace_settings WHERE id = 1')->fetchColumn();
            $this->create(self::defaultCharger($defaultUrl));
            $chargers = $this->database->query('SELECT * FROM charger ORDER BY id')->fetchAll();
        }

        return $chargers;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $id): ?array
    {
        $statement = $this->database->prepare('SELECT * FROM charger WHERE id = :id');
        $statement->execute(['id' => $id]);
        $charger = $statement->fetch();

        return $charger === false ? null : $charger;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function dueBootRetries(): array
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT * FROM charger
            WHERE boot_status IN ('Pending', 'Rejected')
                AND boot_retry_at IS NOT NULL
                AND boot_retry_at <= :now
            ORDER BY id
            SQL);
        $statement->execute(['now' => gmdate(DATE_ATOM)]);

        return $statement->fetchAll();
    }

    public function identityInUse(string $identity, int $exceptId): bool
    {
        $statement = $this->database->prepare('SELECT 1 FROM charger WHERE station_identity = :identity AND id <> :id');
        $statement->execute(['identity' => $identity, 'id' => $exceptId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @param array{station_identity:string,csms_url:string,vendor_name:string,model:string,serial_number:string,outlet_count?:int,outlet_topology?:string,uses_default_csms?:bool} $charger
     */
    public function create(array $charger): int
    {
        $statement = $this->database->prepare(<<<'SQL'
            INSERT INTO charger (station_identity, csms_url, vendor_name, model, serial_number, outlet_count, outlet_topology, uses_default_csms, boot_status, updated_at)
            VALUES (:station_identity, :csms_url, :vendor_name, :model, :serial_number, :outlet_count, :outlet_topology, :uses_default_csms, 'Not connected', :updated_at)
            SQL);
        $values = $charger + [
            'outlet_count' => 1,
            'outlet_topology' => 'independent',
            'uses_default_csms' => 1,
            'updated_at' => gmdate(DATE_ATOM),
        ];
        $values['uses_default_csms'] = ($values['uses_default_csms'] ?? true) ? 1 : 0;
        $statement->execute($values);

        return (int) $this->database->lastInsertId();
    }

    public function createDefault(): int
    {
        $all = $this->database->query('SELECT station_identity FROM charger')->fetchAll(PDO::FETCH_COLUMN);
        $number = 1;
        do {
            $number++;
            $identity = 'DemoCharger ' . $number;
        } while (in_array($identity, $all, true));

        $defaultUrl = (string) $this->database->query('SELECT default_csms_url FROM workspace_settings WHERE id = 1')->fetchColumn();
        $charger = self::defaultCharger($defaultUrl);
        $charger['station_identity'] = $identity;
        $charger['serial_number'] = 'DEMO-' . str_pad((string) $number, 3, '0', STR_PAD_LEFT);

        return $this->create($charger);
    }

    /**
     * @param array{station_identity:string,csms_url:string,vendor_name:string,model:string,serial_number:string,outlet_count:int,outlet_topology:string,uses_default_csms?:bool} $charger
     */
    public function save(int $id, array $charger): void
    {
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE charger SET station_identity = :station_identity, csms_url = :csms_url,
                vendor_name = :vendor_name, model = :model, serial_number = :serial_number,
                uses_default_csms = :uses_default_csms,
                outlet_count = :outlet_count, outlet_topology = :outlet_topology, updated_at = :updated_at
            WHERE id = :id
            SQL);
        $values = $charger + ['uses_default_csms' => 1, 'updated_at' => gmdate(DATE_ATOM), 'id' => $id];
        $values['uses_default_csms'] = ($values['uses_default_csms'] ?? true) ? 1 : 0;
        $statement->execute($values);
        if ($statement->rowCount() === 0 && $this->get($id) === null) {
            throw new RuntimeException('The requested charger no longer exists.');
        }
    }

    public function setBootStatus(int $id, string $status, ?string $retryAt = null): void
    {
        $statement = $this->database->prepare('UPDATE charger SET boot_status = :status, boot_retry_at = :retry_at, updated_at = :updated_at WHERE id = :id');
        $statement->execute([
            'status' => $status,
            'retry_at' => $retryAt,
            'updated_at' => gmdate(DATE_ATOM),
            'id' => $id,
        ]);
    }

    public function nextTransactionId(int $id): string
    {
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE charger
            SET next_transaction_sequence = next_transaction_sequence + 1
            WHERE id = :id
            RETURNING next_transaction_sequence - 1
            SQL);
        $statement->execute(['id' => $id]);
        $sequence = $statement->fetchColumn();
        if ($sequence === false) {
            throw new RuntimeException('The requested charger no longer exists.');
        }

        return sprintf('%016x%016x', $id, (int) $sequence);
    }

    public function setConnectionStatus(int $id, string $status): void
    {
        $statement = $this->database->prepare('UPDATE charger SET connection_status = :status, updated_at = :updated_at WHERE id = :id');
        $statement->execute(['status' => $status, 'updated_at' => gmdate(DATE_ATOM), 'id' => $id]);
    }

    public function resetConnectionStatuses(): void
    {
        $this->database->exec("UPDATE charger SET connection_status = 'Not connected'");
    }

    public function delete(int $id): void
    {
        $statement = $this->database->prepare('DELETE FROM charger WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    /**
     * @return array{station_identity:string,csms_url:string,vendor_name:string,model:string,serial_number:string,outlet_count:int,outlet_topology:string,uses_default_csms:bool}
     */
    private static function defaultCharger(string $csmsUrl): array
    {
        return [
            'station_identity' => 'DemoCharger',
            'csms_url' => $csmsUrl,
            'vendor_name' => 'Local Emulator',
            'model' => 'Developer Charger',
            'serial_number' => 'DEMO-001',
            'outlet_count' => 1,
            'outlet_topology' => 'independent',
            'uses_default_csms' => true,
        ];
    }
}
