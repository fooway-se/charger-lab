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
            $this->create(self::defaultCharger());
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

    public function identityInUse(string $identity, int $exceptId): bool
    {
        $statement = $this->database->prepare('SELECT 1 FROM charger WHERE station_identity = :identity AND id <> :id');
        $statement->execute(['identity' => $identity, 'id' => $exceptId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @param array{station_identity:string,csms_url:string,vendor_name:string,model:string,serial_number:string,outlet_count?:int,outlet_topology?:string} $charger
     */
    public function create(array $charger): int
    {
        $statement = $this->database->prepare(<<<'SQL'
            INSERT INTO charger (station_identity, csms_url, vendor_name, model, serial_number, outlet_count, outlet_topology, boot_status, updated_at)
            VALUES (:station_identity, :csms_url, :vendor_name, :model, :serial_number, :outlet_count, :outlet_topology, 'Not connected', :updated_at)
            SQL);
        $statement->execute($charger + [
            'outlet_count' => 1,
            'outlet_topology' => 'independent',
            'updated_at' => gmdate(DATE_ATOM),
        ]);

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

        $charger = self::defaultCharger();
        $charger['station_identity'] = $identity;
        $charger['csms_url'] = 'ws://fake-csms:9000/ocpp/' . rawurlencode($identity);
        $charger['serial_number'] = 'DEMO-' . str_pad((string) $number, 3, '0', STR_PAD_LEFT);

        return $this->create($charger);
    }

    /**
     * @param array{station_identity:string,csms_url:string,vendor_name:string,model:string,serial_number:string,outlet_count:int,outlet_topology:string} $charger
     */
    public function save(int $id, array $charger): void
    {
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE charger SET station_identity = :station_identity, csms_url = :csms_url,
                vendor_name = :vendor_name, model = :model, serial_number = :serial_number,
                outlet_count = :outlet_count, outlet_topology = :outlet_topology, updated_at = :updated_at
            WHERE id = :id
            SQL);
        $statement->execute($charger + ['updated_at' => gmdate(DATE_ATOM), 'id' => $id]);
        if ($statement->rowCount() === 0 && $this->get($id) === null) {
            throw new RuntimeException('The requested charger no longer exists.');
        }
    }

    public function setBootStatus(int $id, string $status): void
    {
        $statement = $this->database->prepare('UPDATE charger SET boot_status = :status, updated_at = :updated_at WHERE id = :id');
        $statement->execute(['status' => $status, 'updated_at' => gmdate(DATE_ATOM), 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $statement = $this->database->prepare('DELETE FROM charger WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    /**
     * @return array{station_identity:string,csms_url:string,vendor_name:string,model:string,serial_number:string,outlet_count:int,outlet_topology:string}
     */
    private static function defaultCharger(): array
    {
        return [
            'station_identity' => 'DemoCharger',
            'csms_url' => 'ws://fake-csms:9000/ocpp/DemoCharger',
            'vendor_name' => 'Local Emulator',
            'model' => 'Developer Charger',
            'serial_number' => 'DEMO-001',
            'outlet_count' => 1,
            'outlet_topology' => 'independent',
        ];
    }
}
