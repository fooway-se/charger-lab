<?php

declare(strict_types=1);

namespace ChargerEmulator\Protocol;

use ChargerEmulator\Domain\OutletMap;
use ChargerEmulator\Domain\DeviceModel;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\ExchangeRepository;
use ChargerEmulator\Storage\OcppCommandRepository;
use ChargerEmulator\Storage\OutletSessionRepository;
use RuntimeException;
use Throwable;

/**
 * Owns open OCPP connections and executes queued actions outside HTTP requests.
 */
final class StationSessionWorker
{
    private const SIMULATED_SESSION_ENERGY_WH = 1000;

    /**
     * @var array<int, array{connection:WebSocketConnection,url:string,heartbeat_interval:int,next_heartbeat_at:?float,boot_payload:array<string,mixed>,next_boot_at:?float}>
     */
    private array $sessions = [];

    public function __construct(
        private readonly ChargerRepository $chargers,
        private readonly ExchangeRepository $logs,
        private readonly OcppCommandRepository $commands,
        private readonly OutletSessionRepository $outletSessions,
    ) {
    }

    public function run(int $pollMicroseconds = 20_000): never
    {
        $this->commands->recoverInterrupted();
        $this->chargers->resetConnectionStatuses();
        while (true) {
            if (!$this->runOne() && !$this->pollSessions()) {
                usleep($pollMicroseconds);
            }
        }
    }

    public function runOne(): bool
    {
        $this->queueDueBootRetries();
        $command = $this->commands->claimNext();
        if ($command === null) {
            return false;
        }

        $chargerId = $command['charger_id'];
        $hadSession = isset($this->sessions[$chargerId]);
        try {
            $charger = $this->chargers->get($chargerId);
            if ($charger === null) {
                throw new RuntimeException('The requested charger no longer exists.');
            }

            if ($command['action'] === 'Disconnect') {
                unset($this->sessions[$chargerId]);
                $this->chargers->setConnectionStatus($chargerId, 'Not connected');
                $this->commands->complete($command['id'], ['status' => 'Disconnected']);

                return true;
            }

            $connectionUrl = OcppConnectionUrl::fromEndpoint($charger['csms_url'], $charger['station_identity']);
            if (!isset($this->sessions[$chargerId]) || $this->sessions[$chargerId]['url'] !== $connectionUrl) {
                unset($this->sessions[$chargerId]);
                if ($command['action'] !== 'BootNotification') {
                    throw new RuntimeException('Connect the charger with BootNotification before sending other actions.');
                }

                $this->sessions[$chargerId] = [
                    'connection' => new WebSocketConnection($connectionUrl),
                    'url' => $connectionUrl,
                    'heartbeat_interval' => 300,
                    'next_heartbeat_at' => null,
                    'boot_payload' => [],
                    'next_boot_at' => null,
                ];
            }

            if ($command['action'] === 'PlugIn') {
                $result = $this->plugIn($chargerId, $command['payload']);
                $this->commands->complete($command['id'], $result);

                return true;
            }

            if ($command['action'] === 'Unplug') {
                $result = $this->unplug($chargerId, $command['payload']);
                $this->commands->complete($command['id'], $result);

                return true;
            }

            $payload = $command['payload'];
            unset($payload['_retry_status']);
            $outlet = $payload['_outlet'] ?? null;
            unset($payload['_outlet']);
            if ($command['action'] === 'BootNotification') {
                $this->sessions[$chargerId]['boot_payload'] = $payload;
            }
            $client = $this->createClient($chargerId);
            $result = $client->request($command['action'], $payload);
            if ($command['action'] === 'Authorize' && ($result['idTokenInfo']['status'] ?? null) === 'Accepted' && is_array($outlet)) {
                $this->attachAuthorizedToken($chargerId, $outlet, $payload['idToken']);
            }
            if ($command['action'] === 'BootNotification') {
                $this->applyBootResponse($chargerId, $charger, $result);
            } else {
                $this->chargers->setConnectionStatus($chargerId, 'Connected');
            }

            $this->commands->complete($command['id'], $result);
        } catch (Throwable $exception) {
            unset($this->sessions[$chargerId]);
            $this->logs->record($chargerId, 'system', $command['action'], $exception->getMessage());
            if ($command['action'] === 'BootNotification') {
                $retryStatus = $command['payload']['_retry_status'] ?? null;
                if (is_string($retryStatus) && in_array($retryStatus, ['Pending', 'Rejected'], true)) {
                    $retryAt = gmdate(DATE_ATOM, time() + 300);
                    $this->chargers->setBootStatus($chargerId, $retryStatus, $retryAt);
                } else {
                    $this->chargers->setBootStatus($chargerId, 'Connection failed');
                }
            }
            if ($command['action'] === 'BootNotification' || $hadSession) {
                $this->chargers->setConnectionStatus($chargerId, 'Connection failed');
            }

            $this->commands->fail($command['id'], $exception->getMessage());
        }

        return true;
    }

    private function queueDueBootRetries(): void
    {
        foreach ($this->chargers->dueBootRetries() as $charger) {
            $chargerId = (int) $charger['id'];
            if ($this->commands->hasPendingBootNotification($chargerId)) {
                continue;
            }

            $payload = [
                'chargingStation' => array_filter([
                    'model' => $charger['model'],
                    'vendorName' => $charger['vendor_name'],
                    'serialNumber' => $charger['serial_number'] !== '' ? $charger['serial_number'] : null,
                ], static fn (mixed $value): bool => $value !== null),
                'reason' => 'PowerUp',
                '_retry_status' => $charger['boot_status'],
            ];
            $this->commands->enqueue($chargerId, 'BootNotification', $payload);
        }
    }

    private function pollSessions(): bool
    {
        foreach ($this->sessions as $chargerId => $session) {
            try {
                if ($session['next_boot_at'] !== null && microtime(true) >= $session['next_boot_at']) {
                    $charger = $this->chargers->get($chargerId);
                    if ($charger === null) {
                        unset($this->sessions[$chargerId]);

                        return true;
                    }

                    $result = $this->createClient($chargerId)->request('BootNotification', $session['boot_payload']);
                    $this->applyBootResponse($chargerId, $charger, $result);

                    return true;
                }

                if ($session['next_heartbeat_at'] !== null && microtime(true) >= $session['next_heartbeat_at']) {
                    $result = $this->createClient($chargerId)->request('Heartbeat', new \stdClass());
                    $interval = $this->sessions[$chargerId]['heartbeat_interval'];
                    $this->sessions[$chargerId]['next_heartbeat_at'] = microtime(true) + $interval;
                    $this->chargers->setConnectionStatus($chargerId, 'Connected');
                    if (!isset($result['currentTime']) || !is_string($result['currentTime'])) {
                        throw new OcppProtocolException('The CSMS Heartbeat response did not include currentTime.');
                    }

                    return true;
                }

                $message = $session['connection']->receiveAvailable();
                if ($message !== null) {
                    $charger = $this->chargers->get($chargerId);
                    $bootStatus = is_array($charger) && is_string($charger['boot_status'] ?? null)
                        ? $charger['boot_status']
                        : null;
                    $this->createClient($chargerId)->answerIncomingCallMessage($message, $bootStatus);

                    return true;
                }
            } catch (Throwable $exception) {
                unset($this->sessions[$chargerId]);
                $this->logs->record($chargerId, 'system', 'Session', $exception->getMessage());
                $this->chargers->setConnectionStatus($chargerId, 'Connection failed');

                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $charger
     * @param array<string, mixed> $result
     */
    private function applyBootResponse(int $chargerId, array $charger, array $result): void
    {
        $status = is_string($result['status'] ?? null) ? $result['status'] : 'Unknown response';
        $interval = $result['interval'] ?? 300;
        $interval = is_int($interval) && $interval > 0 ? $interval : 300;
        if ($status === 'Accepted') {
            $this->sessions[$chargerId]['next_boot_at'] = null;
            $this->sessions[$chargerId]['heartbeat_interval'] = $interval;
            $this->sessions[$chargerId]['next_heartbeat_at'] = microtime(true) + $interval;
            $this->chargers->setBootStatus($chargerId, $status);
            $this->chargers->setConnectionStatus($chargerId, 'Connected');
            $this->reportAvailableOutlets($chargerId, $charger);

            return;
        }

        $this->sessions[$chargerId]['next_boot_at'] = microtime(true) + $interval;
        $retryAt = gmdate(DATE_ATOM, (int) ceil($this->sessions[$chargerId]['next_boot_at']));
        $this->chargers->setBootStatus($chargerId, $status, $retryAt);
        $this->sessions[$chargerId]['next_heartbeat_at'] = null;
        $this->chargers->setConnectionStatus($chargerId, $status);
    }

    private function createClient(int $chargerId): OcppClient
    {
        $deviceModel = new DeviceModel($this->outletSessions, $chargerId);

        return new OcppClient(
            $this->sessions[$chargerId]['connection'],
            function (string $direction, string $action, string $message) use ($chargerId): void {
                $this->logs->record($chargerId, $direction, $action, $message);
            },
            static fn (string $action, array $payload): ?array => $deviceModel->handle($action, $payload),
            function () use ($chargerId): ?string {
                $charger = $this->chargers->get($chargerId);

                return is_array($charger) && is_string($charger['boot_status'] ?? null)
                    ? $charger['boot_status']
                    : null;
            },
        );
    }

    /**
     * @param array{outlet_count:int,outlet_topology:string} $charger
     */
    private function reportAvailableOutlets(int $chargerId, array $charger): void
    {
        $outlets = (new OutletMap((int) $charger['outlet_count'], $charger['outlet_topology']))->outlets();
        $this->outletSessions->initialize($chargerId, $outlets);
        foreach ($outlets as $outlet) {
            $session = $this->outletSessions->find($chargerId, $outlet['evse_id'], $outlet['connector_id']);
            $this->sendStatusNotification(
                $chargerId,
                $outlet['evse_id'],
                $outlet['connector_id'],
                $session['connector_status'] ?? 'Available',
            );
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status:string,transactionId:string}
     */
    private function plugIn(int $chargerId, array $payload): array
    {
        $evseId = $this->requiredOutletId($payload, 'evseId');
        $connectorId = $this->requiredOutletId($payload, 'connectorId');
        $session = $this->outletSessions->find($chargerId, $evseId, $connectorId);
        if ($session === null || $session['connector_status'] !== 'Available') {
            throw new RuntimeException('The selected outlet is not available.');
        }

        $transactionId = $this->chargers->nextTransactionId($chargerId);
        $meterWh = $session['meter_wh'];
        $this->sendStatusNotification($chargerId, $evseId, $connectorId, 'Occupied');
        $this->createClient($chargerId)->request('TransactionEvent', [
            'eventType' => 'Started',
            'timestamp' => gmdate(DATE_ATOM),
            'triggerReason' => 'CablePluggedIn',
            'seqNo' => 0,
            'meterValue' => $this->meterValue($meterWh, 'Transaction.Begin'),
            'transactionInfo' => ['transactionId' => $transactionId, 'chargingState' => 'EVConnected'],
            'evse' => ['id' => $evseId, 'connectorId' => $connectorId],
        ]);
        $this->outletSessions->markOccupied($chargerId, $evseId, $connectorId, $transactionId);

        return ['status' => 'PluggedIn', 'transactionId' => $transactionId];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status:string}
     */
    private function unplug(int $chargerId, array $payload): array
    {
        $evseId = $this->requiredOutletId($payload, 'evseId');
        $connectorId = $this->requiredOutletId($payload, 'connectorId');
        $session = $this->outletSessions->find($chargerId, $evseId, $connectorId);
        if ($session === null || $session['connector_status'] !== 'Occupied' || $session['transaction_id'] === null) {
            throw new RuntimeException('The selected outlet has no active vehicle.');
        }

        $sequence = $session['seq_no'] + 1;
        $meterWh = $session['meter_wh'] + self::SIMULATED_SESSION_ENERGY_WH;
        $this->createClient($chargerId)->request('TransactionEvent', [
            'eventType' => 'Ended',
            'timestamp' => gmdate(DATE_ATOM),
            'triggerReason' => 'EVCommunicationLost',
            'seqNo' => $sequence,
            'meterValue' => $this->meterValue($meterWh, 'Transaction.End'),
            'transactionInfo' => [
                'transactionId' => $session['transaction_id'],
                'chargingState' => 'Idle',
                'stoppedReason' => 'EVDisconnected',
            ],
            'evse' => ['id' => $evseId, 'connectorId' => $connectorId],
        ]);
        $this->outletSessions->markAvailable($chargerId, $evseId, $connectorId, $session['transaction_id'], $meterWh);
        $this->sendStatusNotification($chargerId, $evseId, $connectorId, 'Available');

        return ['status' => 'Unplugged'];
    }

    /**
     * @param array{evse_id:int,connector_id:int} $outlet
     * @param array{idToken:string,type:string} $idToken
     */
    private function attachAuthorizedToken(int $chargerId, array $outlet, array $idToken): void
    {
        $session = $this->outletSessions->find($chargerId, $outlet['evse_id'], $outlet['connector_id']);
        if ($session === null || $session['connector_status'] !== 'Occupied' || $session['transaction_id'] === null) {
            throw new RuntimeException('Plug in the selected outlet before presenting an RFID token.');
        }

        $sequence = $session['seq_no'] + 1;
        $authorizationResult = $this->createClient($chargerId)->request('TransactionEvent', [
            'eventType' => 'Updated',
            'timestamp' => gmdate(DATE_ATOM),
            'triggerReason' => 'Authorized',
            'seqNo' => $sequence,
            'transactionInfo' => ['transactionId' => $session['transaction_id']],
            'evse' => ['id' => $outlet['evse_id'], 'connectorId' => $outlet['connector_id']],
            'idToken' => $idToken,
        ]);
        if (($authorizationResult['idTokenInfo']['status'] ?? null) !== 'Accepted') {
            throw new OcppProtocolException('The CSMS did not accept the idToken in the TransactionEvent response.');
        }

        $chargingSequence = $sequence + 1;
        $this->createClient($chargerId)->request('TransactionEvent', [
            'eventType' => 'Updated',
            'timestamp' => gmdate(DATE_ATOM),
            'triggerReason' => 'ChargingStateChanged',
            'seqNo' => $chargingSequence,
            'transactionInfo' => ['transactionId' => $session['transaction_id'], 'chargingState' => 'Charging'],
            'evse' => ['id' => $outlet['evse_id'], 'connectorId' => $outlet['connector_id']],
        ]);

        $this->outletSessions->attachToken(
            $chargerId,
            $outlet['evse_id'],
            $outlet['connector_id'],
            $session['transaction_id'],
            $idToken['idToken'],
            $chargingSequence,
        );
    }

    private function sendStatusNotification(int $chargerId, int $evseId, int $connectorId, string $status): void
    {
        $this->createClient($chargerId)->request('StatusNotification', [
            'timestamp' => gmdate(DATE_ATOM),
            'connectorStatus' => $status,
            'evseId' => $evseId,
            'connectorId' => $connectorId,
        ]);
    }

    /**
     * @return list<array{timestamp:string,sampledValue:list<array{value:int,context:string,measurand:string,unitOfMeasure:array{unit:string}}>}>
     */
    private function meterValue(int $meterWh, string $context): array
    {
        return [[
            'timestamp' => gmdate(DATE_ATOM),
            'sampledValue' => [[
                'value' => $meterWh,
                'context' => $context,
                'measurand' => 'Energy.Active.Import.Register',
                'unitOfMeasure' => ['unit' => 'Wh'],
            ]],
        ]];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requiredOutletId(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;
        if (!is_int($value) || $value < 1) {
            throw new RuntimeException('The outlet command contains an invalid EVSE or connector ID.');
        }

        return $value;
    }
}
