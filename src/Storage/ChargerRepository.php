<?php

declare(strict_types=1);

namespace ChargerEmulator\Storage;

use PDO;

/**
 * Reads and writes the single configured charging station.
 */
final class ChargerRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    /**
     * @return array<string, string>
     */
    public function get(): array
    {
        $charger = $this->database->query('SELECT * FROM charger WHERE id = 1')->fetch();
        if ($charger !== false) {
            return $charger;
        }

        return [
            'station_identity' => 'DemoCharger',
            'csms_url' => 'ws://fake-csms:9000/ocpp/DemoCharger',
            'vendor_name' => 'Local Emulator',
            'model' => 'Developer Charger',
            'serial_number' => 'DEMO-001',
            'outlet_count' => 1,
            'outlet_topology' => 'independent',
            'boot_status' => 'Not connected',
            'updated_at' => gmdate(DATE_ATOM),
        ];
    }

    /**
     * @param array{station_identity:string,csms_url:string,vendor_name:string,model:string,serial_number:string,outlet_count?:int,outlet_topology?:string} $charger
     */
    public function save(array $charger): void
    {
        $statement = $this->database->prepare(<<<'SQL'
            INSERT INTO charger (id, station_identity, csms_url, vendor_name, model, serial_number, outlet_count, outlet_topology, boot_status, updated_at)
            VALUES (1, :station_identity, :csms_url, :vendor_name, :model, :serial_number, :outlet_count, :outlet_topology, 'Not connected', :updated_at)
            ON CONFLICT(id) DO UPDATE SET
                station_identity = excluded.station_identity,
                csms_url = excluded.csms_url,
                vendor_name = excluded.vendor_name,
                model = excluded.model,
                serial_number = excluded.serial_number,
                outlet_count = excluded.outlet_count,
                outlet_topology = excluded.outlet_topology,
                updated_at = excluded.updated_at
            SQL);
        $statement->execute($charger + [
            'outlet_count' => 1,
            'outlet_topology' => 'independent',
            'updated_at' => gmdate(DATE_ATOM),
        ]);
    }

    public function setBootStatus(string $status): void
    {
        $statement = $this->database->prepare('UPDATE charger SET boot_status = :status, updated_at = :updated_at WHERE id = 1');
        $statement->execute(['status' => $status, 'updated_at' => gmdate(DATE_ATOM)]);
    }
}
