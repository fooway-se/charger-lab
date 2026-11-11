<?php

declare(strict_types=1);

use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\OutletSessionRepository;

it('keeps a remote start pending until a vehicle plugs in and exposes expired starts', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $sessions = new OutletSessionRepository($database);
    $sessions->initialize($chargerId, [['number' => 1, 'evse_id' => 1, 'connector_id' => 1]]);
    $deadline = gmdate(DATE_ATOM, time() + 30);

    $sessions->beginRemoteStart($chargerId, 1, 1, 'remote-tx', 'TAG-1', $deadline);
    $pending = $sessions->find($chargerId, 1, 1);

    expect($pending['connector_status'])->toBe('Occupied')
        ->and($pending['remote_start_pending'])->toBeTrue()
        ->and($sessions->dueRemoteStarts($chargerId, gmdate(DATE_ATOM)))->toBe([])
        ->and($sessions->dueRemoteStarts($chargerId, gmdate(DATE_ATOM, time() + 31)))->toHaveCount(1);

    $sessions->markRemoteStartConnected($chargerId, 1, 1, 'remote-tx', 1);
    $connected = $sessions->find($chargerId, 1, 1);

    expect($connected['remote_start_pending'])->toBeFalse()
        ->and($connected['seq_no'])->toBe(1)
        ->and($sessions->dueRemoteStarts($chargerId, gmdate(DATE_ATOM, time() + 31)))->toBe([]);
});

it('persists local RFID stop state only for the matching active transaction token', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $sessions = new OutletSessionRepository($database);
    $sessions->initialize($chargerId, [['number' => 1, 'evse_id' => 1, 'connector_id' => 1]]);
    $sessions->markOccupied($chargerId, 1, 1, 'local-stop-tx');
    $sessions->attachToken($chargerId, 1, 1, 'local-stop-tx', 'TAG-1', 1);

    expect(fn () => $sessions->markLocalStopRequested($chargerId, 1, 1, 'local-stop-tx', 'OTHER-TAG', 2))
        ->toThrow(RuntimeException::class, 'active outlet transaction changed');

    $sessions->markLocalStopRequested($chargerId, 1, 1, 'local-stop-tx', 'TAG-1', 2);
    $stopped = $sessions->find($chargerId, 1, 1);

    expect($stopped['connector_status'])->toBe('Occupied')
        ->and($stopped['transaction_id'])->toBe('local-stop-tx')
        ->and($stopped['seq_no'])->toBe(2)
        ->and($stopped['stop_reason'])->toBe('Local')
        ->and($stopped['id_token'])->toBe('TAG-1');
});

it('keeps clock-aligned samples from resetting the periodic transaction meter interval', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $sessions = new OutletSessionRepository($database);
    $sessions->initialize($chargerId, [['number' => 1, 'evse_id' => 1, 'connector_id' => 1]]);
    $sessions->markOccupied($chargerId, 1, 1, 'meter-schedule-tx');

    $periodicSampleAt = '2026-11-10T10:00:00Z';
    $sessions->recordMeterSample($chargerId, 1, 1, 'meter-schedule-tx', 100, 1, $periodicSampleAt);
    $sessions->recordMeterSample($chargerId, 1, 1, 'meter-schedule-tx', 200, 2, null);

    $session = $sessions->find($chargerId, 1, 1);
    expect($session['meter_wh'])->toBe(200)
        ->and($session['seq_no'])->toBe(2)
        ->and($session['meter_updated_at'])->toBe($periodicSampleAt);
});

it('migrates active outlet sessions to allow persisted local RFID stops', function (): void {
    $directory = sys_get_temp_dir() . '/charger-local-stop-migration-' . bin2hex(random_bytes(6));
    mkdir($directory);
    $databasePath = $directory . '/emulator.sqlite';
    $database = Database::connect($databasePath);
    $chargerId = (new ChargerRepository($database))->createDefault();
    unset($database);

    $legacyDatabase = new PDO('sqlite:' . $databasePath);
    $legacyDatabase->exec('DROP TABLE outlet_session');
    $legacyDatabase->exec(<<<'SQL'
        CREATE TABLE outlet_session (
            charger_id INTEGER NOT NULL REFERENCES charger(id) ON DELETE CASCADE,
            evse_id INTEGER NOT NULL CHECK (evse_id > 0),
            connector_id INTEGER NOT NULL CHECK (connector_id > 0),
            connector_status TEXT NOT NULL CHECK (connector_status IN ('Available', 'Occupied')),
            transaction_id TEXT,
            id_token TEXT,
            seq_no INTEGER NOT NULL DEFAULT 0 CHECK (seq_no >= 0),
            meter_wh INTEGER NOT NULL DEFAULT 0 CHECK (meter_wh >= 0),
            stop_reason TEXT CHECK (stop_reason IS NULL OR stop_reason = 'Remote'),
            meter_updated_at TEXT,
            meter_ended_at TEXT,
            meter_end_samples TEXT NOT NULL DEFAULT '[]',
            remote_start_deadline TEXT,
            PRIMARY KEY (charger_id, evse_id, connector_id),
            UNIQUE (charger_id, transaction_id),
            CHECK ((connector_status = 'Available' AND transaction_id IS NULL AND id_token IS NULL)
                OR (connector_status = 'Occupied' AND transaction_id IS NOT NULL))
        )
        SQL);
    $legacyDatabase->prepare(<<<'SQL'
        INSERT INTO outlet_session (
            charger_id, evse_id, connector_id, connector_status, transaction_id, id_token,
            seq_no, meter_wh, stop_reason, meter_updated_at, meter_ended_at,
            meter_end_samples, remote_start_deadline
        ) VALUES (:charger_id, 1, 1, 'Occupied', 'legacy-local-stop-tx', 'TAG-2', 1, 10, NULL,
            '2026-11-05T00:00:00+00:00', NULL, '[]', NULL)
        SQL)->execute(['charger_id' => $chargerId]);
    unset($legacyDatabase);

    $database = Database::connect($databasePath);
    $sessions = new OutletSessionRepository($database);
    $session = $sessions->find($chargerId, 1, 1);
    $sessions->markLocalStopRequested($chargerId, 1, 1, 'legacy-local-stop-tx', 'TAG-2', 2);

    expect($session['transaction_id'])->toBe('legacy-local-stop-tx')
        ->and($sessions->find($chargerId, 1, 1)['stop_reason'])->toBe('Local');

    unset($sessions, $database);
    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }

    rmdir($directory);
});

it('keeps an outlet meter register across completed sessions and database reopen', function (): void {
    $directory = sys_get_temp_dir() . '/charger-meter-' . bin2hex(random_bytes(6));
    $databasePath = $directory . '/emulator.sqlite';
    $database = Database::connect($databasePath);
    $chargerId = (new ChargerRepository($database))->create([
        'station_identity' => 'MeterStation',
        'csms_url' => 'ws://localhost:9000/ocpp/MeterStation',
        'vendor_name' => 'Test Vendor',
        'model' => 'Test Model',
        'serial_number' => '',
        'outlet_count' => 1,
        'outlet_topology' => 'independent',
    ]);
    $sessions = new OutletSessionRepository($database);
    $outlets = [['number' => 1, 'evse_id' => 1, 'connector_id' => 1]];
    $sessions->initialize($chargerId, $outlets);
    $sessions->markOccupied($chargerId, 1, 1, 'tx-1');
    $sessions->markAvailable($chargerId, 1, 1, 'tx-1', 1000);
    unset($sessions, $database);

    $database = Database::connect($databasePath);
    $sessions = new OutletSessionRepository($database);
    $sessions->initialize($chargerId, $outlets);
    $session = $sessions->find($chargerId, 1, 1);

    expect($session['meter_wh'] ?? null)->toBe(1000);

    $sessions->markOccupied($chargerId, 1, 1, 'tx-2');
    $sessions->markAvailable($chargerId, 1, 1, 'tx-2', ($session['meter_wh'] ?? 0) + 1000);
    $session = $sessions->find($chargerId, 1, 1);

    expect($session['meter_wh'] ?? null)->toBe(2000);

    $sessions->markOccupied($chargerId, 1, 1, 'tx-3');
    $sessions->markRemoteStopRequested($chargerId, 1, 1, 'tx-3', 1);
    unset($sessions, $database);
    $database = Database::connect($databasePath);
    $sessions = new OutletSessionRepository($database);
    $sessions->initialize($chargerId, $outlets);
    $session = $sessions->find($chargerId, 1, 1);

    expect($session['seq_no'] ?? null)->toBe(1)
        ->and($session['stop_reason'] ?? null)->toBe('Remote');

    unset($sessions, $database);
    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }

    rmdir($directory);
});

it('adds the meter register when upgrading an existing outlet session table', function (): void {
    $directory = sys_get_temp_dir() . '/charger-meter-migration-' . bin2hex(random_bytes(6));
    $databasePath = $directory . '/emulator.sqlite';
    $database = Database::connect($databasePath);
    $chargerId = (new ChargerRepository($database))->create([
        'station_identity' => 'LegacyMeterStation',
        'csms_url' => 'ws://localhost:9000/ocpp/LegacyMeterStation',
        'vendor_name' => 'Test Vendor',
        'model' => 'Test Model',
        'serial_number' => '',
        'outlet_count' => 1,
        'outlet_topology' => 'independent',
    ]);
    $sessions = new OutletSessionRepository($database);
    $sessions->initialize($chargerId, [['number' => 1, 'evse_id' => 1, 'connector_id' => 1]]);
    $sessions->markOccupied($chargerId, 1, 1, 'legacy-tx');
    $sessions->attachToken($chargerId, 1, 1, 'legacy-tx', '04A1', 1);
    unset($sessions, $database);

    $legacyDatabase = new PDO('sqlite:' . $databasePath);
    $legacyDatabase->exec('ALTER TABLE outlet_session DROP COLUMN meter_wh');
    $legacyDatabase->exec('ALTER TABLE outlet_session DROP COLUMN meter_ended_at');
    $legacyDatabase->exec('ALTER TABLE outlet_session DROP COLUMN meter_end_samples');
    unset($legacyDatabase);

    $database = Database::connect($databasePath);
    $session = (new OutletSessionRepository($database))->find($chargerId, 1, 1);

    expect($session['connector_status'] ?? null)->toBe('Occupied')
        ->and($session['transaction_id'] ?? null)->toBe('legacy-tx')
        ->and($session['id_token'] ?? null)->toBe('04A1')
        ->and($session['meter_wh'] ?? null)->toBe(0)
        ->and($session['meter_end_samples'] ?? null)->toBe([])
        ->and($session['meter_ended_at'] ?? null)->toBeNull();

    unset($database);
    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }

    rmdir($directory);
});

it('persists transaction end interval samples across database reopen', function (): void {
    $directory = sys_get_temp_dir() . '/charger-ended-meter-samples-' . bin2hex(random_bytes(6));
    $databasePath = $directory . '/emulator.sqlite';
    $database = Database::connect($databasePath);
    $chargerId = (new ChargerRepository($database))->createDefault();
    $sessions = new OutletSessionRepository($database);
    $sessions->initialize($chargerId, [['number' => 1, 'evse_id' => 1, 'connector_id' => 1]]);
    $sessions->markOccupied($chargerId, 1, 1, 'tx-samples');
    $sampledAt = '2026-11-04T12:00:00+00:00';
    $meterValue = [
        'timestamp' => $sampledAt,
        'sampledValue' => [[
            'value' => 100,
            'context' => 'Sample.Periodic',
            'measurand' => 'Energy.Active.Import.Register',
            'unitOfMeasure' => ['unit' => 'Wh'],
        ]],
    ];
    $sessions->recordTransactionEndSample($chargerId, 1, 1, 'tx-samples', 100, $sampledAt, $meterValue);
    unset($sessions, $database);

    $database = Database::connect($databasePath);
    $sessions = new OutletSessionRepository($database);
    $session = $sessions->find($chargerId, 1, 1);

    expect($session['meter_wh'] ?? null)->toBe(100)
        ->and($session['meter_end_samples'] ?? null)->toBe([$meterValue])
        ->and($session['meter_ended_at'] ?? null)->toBe($sampledAt);

    $sessions->markAvailable($chargerId, 1, 1, 'tx-samples', 1100);
    $session = $sessions->find($chargerId, 1, 1);
    expect($session['meter_end_samples'] ?? null)->toBe([])
        ->and($session['meter_ended_at'] ?? null)->toBeNull();

    unset($sessions, $database);
    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }

    rmdir($directory);
});
