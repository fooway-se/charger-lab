<?php

declare(strict_types=1);

namespace ChargerEmulator\Protocol;

use ChargerEmulator\Domain\ClockAlignedMeterValueSchedule;
use ChargerEmulator\Domain\OutletMap;
use ChargerEmulator\Domain\DeviceModel;
use ChargerEmulator\Domain\ChangeAvailabilityRequestHandler;
use ChargerEmulator\Domain\RemoteStartRequestHandler;
use ChargerEmulator\Domain\RemoteStopRequestHandler;
use ChargerEmulator\Domain\ResetRequestHandler;
use ChargerEmulator\Domain\TransactionStatusRequestHandler;
use ChargerEmulator\Domain\TriggerMessageRequestHandler;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\AvailabilityRepository;
use ChargerEmulator\Storage\DeviceModelSettingsRepository;
use ChargerEmulator\Storage\ExchangeRepository;
use ChargerEmulator\Storage\OcppCommandRepository;
use ChargerEmulator\Storage\OutletSessionRepository;
use ChargerEmulator\Storage\TransactionEventOutboxRepository;
use ChargerEmulator\Storage\ResetRepository;
use ChargerEmulator\Storage\WorkspaceSettingsRepository;
use RuntimeException;
use Throwable;

/**
 * Owns open OCPP connections and executes queued actions outside HTTP requests.
 */
final class StationSessionWorker
{
    private const SIMULATED_SESSION_ENERGY_WH = 1000;
    private const SIMULATED_PERIODIC_ENERGY_WH = 100;

    /**
     * @var array<int, array{connection:?WebSocketConnection,url:string,username:string,password:string,tls_server_name:string,tls_ca_certificate:string,heartbeat_interval:int,next_heartbeat_at:?float,boot_payload:array<string,mixed>,next_boot_at:?float,reconnect_attempt:int,next_reconnect_at:?float}>
     */
    private array $sessions = [];

    /**
     * @var array<int, array{interval:int,next_at:int}>
     */
    private array $alignedMeterSchedules = [];

    public function __construct(
        private readonly ChargerRepository $chargers,
        private readonly ExchangeRepository $logs,
        private readonly OcppCommandRepository $commands,
        private readonly OutletSessionRepository $outletSessions,
        private readonly AvailabilityRepository $availability,
        private readonly ResetRepository $resets,
        private readonly DeviceModelSettingsRepository $deviceModelSettings,
        private readonly TransactionEventOutboxRepository $transactionEvents,
        private readonly WorkspaceSettingsRepository $workspaceSettings,
    ) {
    }

    public function run(int $pollMicroseconds = 20_000): never
    {
        $this->commands->recoverInterrupted();
        $this->chargers->resetConnectionStatuses();
        $this->restoreAcceptedSessions();
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
        $hadSession = isset($this->sessions[$chargerId])
            && $this->sessions[$chargerId]['connection'] instanceof WebSocketConnection;
        try {
            $charger = $this->chargers->get($chargerId);
            if ($charger === null) {
                throw new RuntimeException('The requested charger no longer exists.');
            }

            if ($command['action'] === 'LocalStop') {
                $result = $this->localStop($chargerId, $command['payload']);
                $this->commands->complete($command['id'], $result);

                return true;
            }

            if ($command['action'] === 'Disconnect') {
                unset($this->sessions[$chargerId]);
                $this->chargers->setConnectionStatus($chargerId, 'Not connected');
                $this->commands->complete($command['id'], ['status' => 'Disconnected']);

                return true;
            }

            $connectionUrl = OcppConnectionUrl::fromEndpoint($charger['csms_url'], $charger['station_identity']);
            $tlsSettings = $this->workspaceSettings->getTlsSettings();
            if (!isset($this->sessions[$chargerId]) || $this->sessions[$chargerId]['url'] !== $connectionUrl
                || $this->sessions[$chargerId]['username'] !== $charger['station_identity']
                || $this->sessions[$chargerId]['password'] !== $charger['csms_password']
                || $this->sessions[$chargerId]['tls_server_name'] !== $tlsSettings['tls_server_name']
                || $this->sessions[$chargerId]['tls_ca_certificate'] !== $tlsSettings['tls_ca_certificate']
                || $this->sessions[$chargerId]['connection'] === null) {
                unset($this->sessions[$chargerId]);
                if ($command['action'] !== 'BootNotification') {
                    throw new RuntimeException('Connect the charger with BootNotification before sending other actions.');
                }

                $this->sessions[$chargerId] = [
                    'connection' => new WebSocketConnection(
                        $connectionUrl,
                        username: $charger['station_identity'],
                        password: $charger['csms_password'],
                        tlsServerName: $tlsSettings['tls_server_name'] !== '' ? $tlsSettings['tls_server_name'] : null,
                        tlsCaCertificatePem: $tlsSettings['tls_ca_certificate'] !== '' ? $tlsSettings['tls_ca_certificate'] : null,
                    ),
                    'url' => $connectionUrl,
                    'username' => $charger['station_identity'],
                    'password' => $charger['csms_password'],
                    'tls_server_name' => $tlsSettings['tls_server_name'],
                    'tls_ca_certificate' => $tlsSettings['tls_ca_certificate'],
                    'heartbeat_interval' => 300,
                    'next_heartbeat_at' => null,
                    'boot_payload' => [],
                    'next_boot_at' => null,
                    'reconnect_attempt' => 0,
                    'next_reconnect_at' => null,
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

            if ($command['action'] === 'RemoteStart') {
                $result = $this->remoteStart($chargerId, $command['payload']);
                $this->commands->complete($command['id'], $result);

                return true;
            }

            if ($command['action'] === 'RemoteStop') {
                $result = $this->remoteStop($chargerId, $command['payload']);
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
            $csmsRejectedMessage = $this->isOcppCallError($exception);
            $retryWaiting = $exception instanceof TransactionEventRetryPendingException;
            if (!$csmsRejectedMessage && !$retryWaiting && $command['action'] !== 'BootNotification' && $hadSession) {
                $this->scheduleReconnect($chargerId);
            } elseif (!$csmsRejectedMessage && !$retryWaiting) {
                unset($this->sessions[$chargerId]);
            }

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
            if (!$csmsRejectedMessage && !$retryWaiting && ($command['action'] === 'BootNotification' || $hadSession)) {
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
                if ($session['connection'] === null) {
                    if ($session['next_reconnect_at'] === null || microtime(true) < $session['next_reconnect_at']) {
                        continue;
                    }

                    $charger = $this->chargers->get($chargerId);
                    if ($charger === null) {
                        unset($this->sessions[$chargerId]);

                        return true;
                    }
                    $this->sessions[$chargerId]['username'] = $charger['station_identity'];
                    $this->sessions[$chargerId]['password'] = $charger['csms_password'];
                    $tlsSettings = $this->workspaceSettings->getTlsSettings();
                    $this->sessions[$chargerId]['tls_server_name'] = $tlsSettings['tls_server_name'];
                    $this->sessions[$chargerId]['tls_ca_certificate'] = $tlsSettings['tls_ca_certificate'];
                    unset($this->alignedMeterSchedules[$chargerId]);
                    $this->sessions[$chargerId]['connection'] = new WebSocketConnection(
                        $session['url'],
                        username: $charger['station_identity'],
                        password: $charger['csms_password'],
                        tlsServerName: $tlsSettings['tls_server_name'] !== '' ? $tlsSettings['tls_server_name'] : null,
                        tlsCaCertificatePem: $tlsSettings['tls_ca_certificate'] !== '' ? $tlsSettings['tls_ca_certificate'] : null,
                    );
                    $this->sessions[$chargerId]['reconnect_attempt'] = 0;
                    $this->sessions[$chargerId]['next_reconnect_at'] = null;
                    $this->sessions[$chargerId]['next_heartbeat_at'] = microtime(true) + $session['heartbeat_interval'];
                    $this->chargers->setConnectionStatus($chargerId, 'Connected');
                    $session = $this->sessions[$chargerId];
                }

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
                    $this->sendHeartbeat($chargerId);
                    $interval = $this->sessions[$chargerId]['heartbeat_interval'];
                    $this->sessions[$chargerId]['next_heartbeat_at'] = microtime(true) + $interval;
                    $this->chargers->setConnectionStatus($chargerId, 'Connected');

                    return true;
                }

                if ($this->reportPendingResetUnavailable($chargerId)) {
                    return true;
                }

                if ($this->processPendingReset($chargerId)) {
                    return true;
                }

                if ($this->expireRemoteStart($chargerId)) {
                    return true;
                }

                if ($this->deliverPendingTransactionEvent($chargerId)) {
                    return true;
                }

                if ($this->sendPeriodicMeterValue($chargerId)) {
                    return true;
                }

                if ($this->sendClockAlignedMeterValues($chargerId)) {
                    return true;
                }

                if ($this->sampleTransactionEndMeterValue($chargerId)) {
                    return true;
                }

                if ($this->deliverPendingTransactionEvent($chargerId)) {
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
                $this->logs->record($chargerId, 'system', 'Session', $exception->getMessage());
                if (!$this->isOcppCallError($exception) && !($exception instanceof TransactionEventRetryPendingException)) {
                    $this->scheduleReconnect($chargerId);
                    $this->chargers->setConnectionStatus($chargerId, 'Connection failed');
                }

                return true;
            }
        }

        return false;
    }

    private function restoreAcceptedSessions(): void
    {
        foreach ($this->chargers->all() as $charger) {
            $chargerId = (int) $charger['id'];
            $resetReconnecting = $this->resets->isReconnecting($chargerId);
            if (($charger['boot_status'] ?? null) !== 'Accepted' && !$resetReconnecting) {
                continue;
            }

            $url = OcppConnectionUrl::fromEndpoint($charger['csms_url'], $charger['station_identity']);
            $tlsSettings = $this->workspaceSettings->getTlsSettings();
            $this->transactionEvents->markPendingOffline($chargerId);
            $this->sessions[$chargerId] = [
                'connection' => null,
                'url' => $url,
                'username' => $charger['station_identity'],
                'password' => $charger['csms_password'],
                'tls_server_name' => $tlsSettings['tls_server_name'],
                'tls_ca_certificate' => $tlsSettings['tls_ca_certificate'],
                'heartbeat_interval' => $this->deviceModelSettingInt($chargerId, 'HeartbeatInterval', 300, 1),
                'next_heartbeat_at' => null,
                'boot_payload' => $resetReconnecting ? $this->resetBootPayload($charger) : [],
                'next_boot_at' => $resetReconnecting ? microtime(true) : null,
                'reconnect_attempt' => 1,
                'next_reconnect_at' => microtime(true) + $this->nextReconnectDelay($chargerId, 1),
            ];
        }
    }

    private function scheduleReconnect(int $chargerId): void
    {
        if (!isset($this->sessions[$chargerId])) {
            return;
        }

        $this->transactionEvents->markPendingOffline($chargerId);
        $attempt = $this->sessions[$chargerId]['reconnect_attempt'] + 1;
        $this->sessions[$chargerId]['connection'] = null;
        $this->sessions[$chargerId]['reconnect_attempt'] = $attempt;
        $this->sessions[$chargerId]['next_reconnect_at'] = microtime(true) + $this->nextReconnectDelay($chargerId, $attempt);
    }

    private function nextReconnectDelay(int $chargerId, int $attempt): int
    {
        $minimumWait = $this->deviceModelSettingInt($chargerId, 'RetryBackOffWaitMinimum', 5, 1);
        $randomRange = $this->deviceModelSettingInt($chargerId, 'RetryBackOffRandomRange', 5, 0);
        $repeatTimes = $this->deviceModelSettingInt($chargerId, 'RetryBackOffRepeatTimes', 3, 0);
        $randomSeconds = $randomRange === 0 ? 0 : random_int(0, $randomRange);

        return NetworkReconnectBackoff::delaySeconds(
            $minimumWait,
            $randomRange,
            $repeatTimes,
            $attempt,
            $randomSeconds,
        );
    }

    private function deviceModelSettingInt(int $chargerId, string $variable, int $default, int $minimum): int
    {
        $value = filter_var($this->deviceModelSettings->get($chargerId, $variable), FILTER_VALIDATE_INT);

        return is_int($value) && $value >= $minimum ? $value : $default;
    }

    /**
     * @param array<string, mixed> $charger
     * @param array<string, mixed> $result
     */
    private function applyBootResponse(int $chargerId, array $charger, array $result): void
    {
        if ($this->resets->isReconnecting($chargerId)) {
            $this->resets->complete($chargerId);
        }

        $status = is_string($result['status'] ?? null) ? $result['status'] : 'Unknown response';
        $interval = $result['interval'] ?? 300;
        $interval = is_int($interval) && $interval > 0 ? $interval : 300;
        if ($status === 'Accepted') {
            $this->sessions[$chargerId]['next_boot_at'] = null;
            $this->sessions[$chargerId]['heartbeat_interval'] = $interval;
            $this->sessions[$chargerId]['next_heartbeat_at'] = microtime(true) + $interval;
            $this->deviceModelSettings->set($chargerId, 'HeartbeatInterval', (string) $interval);
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
        $deviceModel = new DeviceModel(
            $this->outletSessions,
            $this->deviceModelSettings,
            $chargerId,
            $this->availability,
        );
        $changeAvailability = new ChangeAvailabilityRequestHandler($chargerId, $this->availability);
        $remoteStart = new RemoteStartRequestHandler(
            $chargerId,
            $this->outletSessions,
            $this->commands,
            $this->availability,
            $this->resets,
        );
        $remoteStop = new RemoteStopRequestHandler($chargerId, $this->outletSessions, $this->commands);
        $reset = new ResetRequestHandler($chargerId, $this->resets);
        $transactionStatus = new TransactionStatusRequestHandler($chargerId, $this->outletSessions, $this->transactionEvents);
        $triggerMessage = new TriggerMessageRequestHandler(
            $chargerId,
            $this->outletSessions,
            $this->availability,
            $this->resets,
            $this->deviceModelSettings,
        );

        $connection = $this->sessions[$chargerId]['connection'];
        if (!$connection instanceof WebSocketConnection) {
            throw new RuntimeException('The charger is waiting to reconnect to the CSMS.');
        }

        return new OcppClient(
            $connection,
            function (string $direction, string $action, string $message) use ($chargerId): void {
                $this->logs->record($chargerId, $direction, $action, $message);
            },
            function (string $action, array $payload) use ($deviceModel, $changeAvailability, $remoteStart, $remoteStop, $reset, $transactionStatus, $triggerMessage, $chargerId): ?array {
                $deviceModelResponse = $deviceModel->handle($action, $payload);
                if ($deviceModelResponse !== null) {
                    return $deviceModelResponse;
                }

                if ($action === 'ChangeAvailability') {
                    return $changeAvailability->handle($payload);
                }

                if ($action === 'RequestStartTransaction') {
                    return $remoteStart->handle($payload);
                }

                if ($action === 'RequestStopTransaction') {
                    return $remoteStop->handle($payload);
                }

                if ($action === 'Reset') {
                    return $reset->handle($payload);
                }

                if ($action === 'TriggerMessage') {
                    $bootStatus = null;
                    if (($payload['requestedMessage'] ?? null) === 'BootNotification') {
                        $charger = $this->chargers->get($chargerId);
                        $bootStatus = is_array($charger) && is_string($charger['boot_status'] ?? null)
                            ? $charger['boot_status']
                            : null;
                    }

                    return $triggerMessage->handle($payload, $bootStatus);
                }

                return $action === 'GetTransactionStatus' ? $transactionStatus->handle($payload) : null;
            },
            function () use ($chargerId): ?string {
                $charger = $this->chargers->get($chargerId);

                return is_array($charger) && is_string($charger['boot_status'] ?? null)
                    ? $charger['boot_status']
                    : null;
            },
            function (string $action, array $_payload, array $response) use ($changeAvailability, $chargerId, $triggerMessage): void {
                if ($action === 'Reset') {
                    if (in_array($response['status'] ?? null, ['Accepted', 'Scheduled'], true)) {
                        $this->reportPendingResetUnavailable($chargerId);
                        $this->processPendingReset($chargerId);
                    }

                    return;
                }

                if ($action === 'TriggerMessage') {
                    if (($response['status'] ?? null) === 'Accepted') {
                        if ($triggerMessage->takeHeartbeatRequest()) {
                            $this->sendHeartbeat($chargerId);
                        }

                        foreach ($triggerMessage->takeStatusNotifications() as $statusNotification) {
                            $this->createClient($chargerId)->request('StatusNotification', $statusNotification);
                        }

                        foreach ($triggerMessage->takeTransactionSessions() as $session) {
                            $this->sendTriggeredTransactionEvent($chargerId, $session);
                        }

                        foreach ($triggerMessage->takeMeterValueRequests() as $meterValueRequest) {
                            $this->createClient($chargerId)->request('MeterValues', [
                                'evseId' => $meterValueRequest['evse_id'],
                                'meterValue' => $this->meterValue($meterValueRequest['meter_wh'], 'Trigger'),
                            ]);
                        }
                    }

                    return;
                }

                if ($action !== 'ChangeAvailability') {
                    return;
                }

                foreach ($changeAvailability->takeStatusChanges() as $change) {
                    $this->sendStatusNotification(
                        $chargerId,
                        $change['evse_id'],
                        $change['connector_id'],
                        $change['status'],
                    );
                }
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
        $this->availability->applyPendingChanges($chargerId);
        foreach ($outlets as $outlet) {
            $session = $this->outletSessions->find($chargerId, $outlet['evse_id'], $outlet['connector_id']);
            $this->sendStatusNotification(
                $chargerId,
                $outlet['evse_id'],
                $outlet['connector_id'],
                $this->availability->connectorStatus(
                    $chargerId,
                    $outlet['evse_id'],
                    $outlet['connector_id'],
                    $session['connector_status'] ?? 'Available',
                ),
            );
        }
    }

    private function reportPendingResetUnavailable(int $chargerId): bool
    {
        $pendingReset = $this->resets->pending($chargerId);
        if ($pendingReset === null || $pendingReset['type'] !== 'OnIdle'
            || $this->resets->isReconnecting($chargerId)
            || $this->resets->unavailableReported($chargerId)) {
            return false;
        }

        foreach ($this->outletSessions->allForCharger($chargerId) as $session) {
            if ($session['connector_status'] === 'Available') {
                $this->sendStatusNotification(
                    $chargerId,
                    $session['evse_id'],
                    $session['connector_id'],
                    'Unavailable',
                );
            }
        }

        $this->resets->markUnavailableReported($chargerId);

        return true;
    }

    private function processPendingReset(int $chargerId): bool
    {
        $pendingReset = $this->resets->pending($chargerId);
        if ($pendingReset === null || $this->resets->isReconnecting($chargerId)) {
            return false;
        }

        $activeSessions = array_values(array_filter(
            $this->outletSessions->allForCharger($chargerId),
            static fn (array $session): bool => $session['connector_status'] === 'Occupied',
        ));
        if ($activeSessions !== []) {
            if ($pendingReset['type'] === 'OnIdle') {
                return false;
            }

            $session = $activeSessions[0];
            $event = [
                'eventType' => 'Ended',
                'timestamp' => gmdate(DATE_ATOM),
                'triggerReason' => 'ImmediateReset',
                'seqNo' => $session['seq_no'] + 1,
                'transactionInfo' => [
                    'transactionId' => $session['transaction_id'],
                    'stoppedReason' => 'ImmediateReset',
                ],
                'evse' => ['id' => $session['evse_id'], 'connectorId' => $session['connector_id']],
            ];
            $meterValue = $this->transactionEndMeterValue($chargerId, $session['meter_wh']);
            if ($meterValue !== null) {
                $event['meterValue'] = $meterValue;
            }

            $queuedEvent = $this->queueTransactionEvent($chargerId, $event);
            $this->outletSessions->markAvailable(
                $chargerId,
                $session['evse_id'],
                $session['connector_id'],
                $session['transaction_id'],
                $session['meter_wh'],
            );
            $this->deliverTransactionEventsThrough($chargerId, $queuedEvent['id']);
            $this->reportReleasedOutlet($chargerId, $session['evse_id'], $session['connector_id']);

            return true;
        }

        if ($this->transactionEvents->hasPending($chargerId)) {
            return false;
        }

        $charger = $this->chargers->get($chargerId);
        if ($charger === null || !isset($this->sessions[$chargerId])) {
            return false;
        }

        $this->resets->markReconnecting($chargerId);
        $this->sessions[$chargerId]['connection'] = null;
        $this->sessions[$chargerId]['boot_payload'] = $this->resetBootPayload($charger);
        $this->sessions[$chargerId]['next_boot_at'] = microtime(true);
        $this->sessions[$chargerId]['next_heartbeat_at'] = null;
        $this->sessions[$chargerId]['reconnect_attempt'] = 1;
        $this->sessions[$chargerId]['next_reconnect_at'] = microtime(true) + 0.2;
        $this->chargers->setBootStatus($chargerId, 'Not connected');
        $this->chargers->setConnectionStatus($chargerId, 'Not connected');

        return true;
    }

    /**
     * @param array<string, mixed> $charger
     * @return array{chargingStation:array<string, mixed>,reason:string}
     */
    private function resetBootPayload(array $charger): array
    {
        return [
            'chargingStation' => array_filter([
                'model' => $charger['model'],
                'vendorName' => $charger['vendor_name'],
                'serialNumber' => $charger['serial_number'] !== '' ? $charger['serial_number'] : null,
            ], static fn (mixed $value): bool => $value !== null),
            'reason' => 'RemoteReset',
        ];
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
        if ($session !== null && $session['remote_start_pending']) {
            return $this->connectRemoteStartedVehicle($chargerId, $session);
        }

        if ($this->resets->blocksNewTransactions($chargerId)) {
            throw new RuntimeException('The charger is waiting to reset and cannot start a new transaction.');
        }

        if ($session === null || $session['connector_status'] !== 'Available'
            || !$this->availability->canStartTransaction($chargerId, $evseId, $connectorId)) {
            throw new RuntimeException('The selected outlet is not available.');
        }

        $transactionId = $this->chargers->nextTransactionId($chargerId);
        $meterWh = $session['meter_wh'];
        $transactionEvent = [
            'eventType' => 'Started',
            'timestamp' => gmdate(DATE_ATOM),
            'triggerReason' => 'CablePluggedIn',
            'seqNo' => 0,
            'transactionInfo' => ['transactionId' => $transactionId, 'chargingState' => 'EVConnected'],
            'evse' => ['id' => $evseId, 'connectorId' => $connectorId],
        ];
        $meterValue = $this->transactionBeginMeterValue($chargerId, $meterWh);
        if ($meterValue !== null) {
            $transactionEvent['meterValue'] = $meterValue;
        }

        $queuedEvent = $this->queueTransactionEvent($chargerId, $transactionEvent);
        $this->outletSessions->markOccupied($chargerId, $evseId, $connectorId, $transactionId);
        $this->sendStatusNotification($chargerId, $evseId, $connectorId, 'Occupied');
        $this->deliverTransactionEventsThrough($chargerId, $queuedEvent['id']);

        return ['status' => 'PluggedIn', 'transactionId' => $transactionId];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status:string,transactionId:string}
     */
    private function remoteStart(int $chargerId, array $payload): array
    {
        $evseId = $this->requiredOutletId($payload, 'evseId');
        $connectorId = $this->requiredOutletId($payload, 'connectorId');
        $remoteStartId = $payload['remoteStartId'] ?? null;
        $idToken = $payload['idToken'] ?? null;
        $session = $this->outletSessions->find($chargerId, $evseId, $connectorId);
        if ($session === null || $session['connector_status'] !== 'Available'
            || !$this->availability->canStartTransaction($chargerId, $evseId, $connectorId)
            || !is_int($remoteStartId) || !is_array($idToken)) {
            throw new RuntimeException('The accepted remote-start request is no longer valid for this outlet.');
        }

        $transactionId = $this->chargers->nextTransactionId($chargerId);
        $connectionTimeout = $this->deviceModelSettingInt($chargerId, 'EVConnectionTimeOut', 30, 1);
        $deadline = gmdate(DATE_ATOM, time() + $connectionTimeout);
        $this->outletSessions->beginRemoteStart(
            $chargerId,
            $evseId,
            $connectorId,
            $transactionId,
            $idToken['idToken'],
            $deadline,
        );
        $transactionEvent = [
            'eventType' => 'Started',
            'timestamp' => gmdate(DATE_ATOM),
            'triggerReason' => 'RemoteStart',
            'seqNo' => 0,
            'transactionInfo' => [
                'transactionId' => $transactionId,
                'remoteStartId' => $remoteStartId,
            ],
            'evse' => ['id' => $evseId, 'connectorId' => $connectorId],
            'idToken' => $idToken,
        ];
        $meterValue = $this->transactionBeginMeterValue($chargerId, $session['meter_wh']);
        if ($meterValue !== null) {
            $transactionEvent['meterValue'] = $meterValue;
        }

        $queuedEvent = $this->queueTransactionEvent($chargerId, $transactionEvent);
        $this->sendStatusNotification($chargerId, $evseId, $connectorId, 'Occupied');
        $result = $this->deliverTransactionEventsThrough($chargerId, $queuedEvent['id']);
        if (($result['idTokenInfo']['status'] ?? null) !== 'Accepted') {
            $this->outletSessions->markAvailable($chargerId, $evseId, $connectorId, $transactionId, $session['meter_wh']);
            $this->reportReleasedOutlet($chargerId, $evseId, $connectorId);
            throw new OcppProtocolException('The CSMS did not accept the remote-start idToken in the TransactionEvent response.');
        }

        return ['status' => 'AwaitingVehicle', 'transactionId' => $transactionId];
    }

    /**
     * @param array<string, mixed> $session
     * @return array{status:string,transactionId:string}
     */
    private function connectRemoteStartedVehicle(int $chargerId, array $session): array
    {
        $transactionId = $session['transaction_id'];
        if (!is_string($transactionId) || $transactionId === '') {
            throw new RuntimeException('The remote-start transaction is missing its transaction ID.');
        }

        $evseId = $session['evse_id'];
        $connectorId = $session['connector_id'];
        $connectedSequence = $session['seq_no'] + 1;
        $connectedEvent = [
            'eventType' => 'Updated',
            'timestamp' => gmdate(DATE_ATOM),
            'triggerReason' => 'CablePluggedIn',
            'seqNo' => $connectedSequence,
            'transactionInfo' => [
                'transactionId' => $transactionId,
                'chargingState' => 'EVConnected',
            ],
            'evse' => ['id' => $evseId, 'connectorId' => $connectorId],
        ];
        $queuedConnectedEvent = $this->queueTransactionEvent($chargerId, $connectedEvent);
        $this->outletSessions->markRemoteStartConnected(
            $chargerId,
            $evseId,
            $connectorId,
            $transactionId,
            $connectedSequence,
        );
        $this->deliverTransactionEventsThrough($chargerId, $queuedConnectedEvent['id']);

        $chargingSequence = $connectedSequence + 1;
        $chargingEvent = [
            'eventType' => 'Updated',
            'timestamp' => gmdate(DATE_ATOM),
            'triggerReason' => 'ChargingStateChanged',
            'seqNo' => $chargingSequence,
            'transactionInfo' => [
                'transactionId' => $transactionId,
                'chargingState' => 'Charging',
            ],
            'evse' => ['id' => $evseId, 'connectorId' => $connectorId],
        ];
        $queuedChargingEvent = $this->queueTransactionEvent($chargerId, $chargingEvent);
        $this->outletSessions->updateSequence($chargerId, $evseId, $connectorId, $transactionId, $chargingSequence);
        $this->deliverTransactionEventsThrough($chargerId, $queuedChargingEvent['id']);

        return ['status' => 'PluggedIn', 'transactionId' => $transactionId];
    }

    private function expireRemoteStart(int $chargerId): bool
    {
        $dueSessions = $this->outletSessions->dueRemoteStarts($chargerId, gmdate(DATE_ATOM));
        if ($dueSessions === []) {
            return false;
        }

        $session = $dueSessions[0];
        $endedSequence = $session['seq_no'] + 1;
        $event = [
            'eventType' => 'Ended',
            'timestamp' => gmdate(DATE_ATOM),
            'triggerReason' => 'TimeLimitReached',
            'seqNo' => $endedSequence,
            'transactionInfo' => [
                'transactionId' => $session['transaction_id'],
                'stoppedReason' => 'Timeout',
            ],
            'evse' => ['id' => $session['evse_id'], 'connectorId' => $session['connector_id']],
        ];
        $meterValue = $this->transactionEndMeterValue($chargerId, $session['meter_wh']);
        if ($meterValue !== null) {
            $event['meterValue'] = $meterValue;
        }

        $queuedEvent = $this->queueTransactionEvent($chargerId, $event);
        $this->outletSessions->markAvailable(
            $chargerId,
            $session['evse_id'],
            $session['connector_id'],
            $session['transaction_id'],
            $session['meter_wh'],
        );
        $this->deliverTransactionEventsThrough($chargerId, $queuedEvent['id']);
        $this->reportReleasedOutlet($chargerId, $session['evse_id'], $session['connector_id']);

        return true;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status:string}
     */
    private function remoteStop(int $chargerId, array $payload): array
    {
        $evseId = $this->requiredOutletId($payload, 'evseId');
        $connectorId = $this->requiredOutletId($payload, 'connectorId');
        $transactionId = $payload['transactionId'] ?? null;
        $session = $this->outletSessions->find($chargerId, $evseId, $connectorId);
        if (!is_string($transactionId) || $session === null
            || $session['connector_status'] !== 'Occupied'
            || $session['transaction_id'] !== $transactionId) {
            throw new RuntimeException('The accepted remote-stop request no longer matches an active transaction.');
        }

        if ($session['remote_start_pending']) {
            $endedEvent = [
                'eventType' => 'Ended',
                'timestamp' => gmdate(DATE_ATOM),
                'triggerReason' => 'RemoteStop',
                'seqNo' => $session['seq_no'] + 1,
                'transactionInfo' => [
                    'transactionId' => $transactionId,
                    'stoppedReason' => 'Remote',
                ],
                'evse' => ['id' => $evseId, 'connectorId' => $connectorId],
            ];
            $meterValue = $this->transactionEndMeterValue($chargerId, $session['meter_wh']);
            if ($meterValue !== null) {
                $endedEvent['meterValue'] = $meterValue;
            }

            $queuedEvent = $this->queueTransactionEvent($chargerId, $endedEvent);
            $this->outletSessions->markAvailable(
                $chargerId,
                $evseId,
                $connectorId,
                $transactionId,
                $session['meter_wh'],
            );
            $this->deliverTransactionEventsThrough($chargerId, $queuedEvent['id']);
            $this->reportReleasedOutlet($chargerId, $evseId, $connectorId);

            return ['status' => 'Stopped'];
        }

        $sequence = $session['seq_no'] + 1;
        $transactionEvent = [
            'eventType' => 'Updated',
            'timestamp' => gmdate(DATE_ATOM),
            'triggerReason' => 'RemoteStop',
            'seqNo' => $sequence,
            'transactionInfo' => [
                'transactionId' => $transactionId,
                'chargingState' => 'EVConnected',
            ],
            'evse' => ['id' => $evseId, 'connectorId' => $connectorId],
        ];
        $queuedEvent = $this->queueTransactionEvent($chargerId, $transactionEvent);
        $this->outletSessions->markRemoteStopRequested($chargerId, $evseId, $connectorId, $transactionId, $sequence);
        $this->deliverTransactionEventsThrough($chargerId, $queuedEvent['id']);

        return ['status' => 'StopRequested'];
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

        if (isset($payload['transactionId']) && $payload['transactionId'] !== $session['transaction_id']) {
            throw new RuntimeException('The active outlet transaction changed before remote stop completed.');
        }

        $sequence = $session['seq_no'] + 1;
        $meterWh = $session['meter_wh'] + self::SIMULATED_SESSION_ENERGY_WH;
        $transactionEvent = [
            'eventType' => 'Ended',
            'timestamp' => gmdate(DATE_ATOM),
            'triggerReason' => 'EVCommunicationLost',
            'seqNo' => $sequence,
            'transactionInfo' => [
                'transactionId' => $session['transaction_id'],
                'chargingState' => 'Idle',
                'stoppedReason' => $session['stop_reason'] === 'Remote' ? 'Remote' : 'EVDisconnected',
            ],
            'evse' => ['id' => $evseId, 'connectorId' => $connectorId],
        ];
        $meterValues = $session['meter_end_samples'];
        $meterValue = $this->transactionEndMeterValue($chargerId, $meterWh);
        if ($meterValue !== null) {
            $meterValues = [...$meterValues, ...$meterValue];
        }
        if ($meterValues !== []) {
            $transactionEvent['meterValue'] = $meterValues;
        }

        $queuedEvent = $this->queueTransactionEvent($chargerId, $transactionEvent);
        $this->outletSessions->markAvailable($chargerId, $evseId, $connectorId, $session['transaction_id'], $meterWh);
        $this->deliverTransactionEventsThrough($chargerId, $queuedEvent['id']);
        $this->reportReleasedOutlet($chargerId, $evseId, $connectorId);

        return ['status' => 'Unplugged'];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status:string}
     */
    private function localStop(int $chargerId, array $payload): array
    {
        $outlet = $payload['_outlet'] ?? null;
        if (!is_array($outlet) || !is_int($outlet['evse_id'] ?? null) || !is_int($outlet['connector_id'] ?? null)) {
            throw new RuntimeException('The local RFID stop does not identify a valid outlet.');
        }

        $evseId = $outlet['evse_id'];
        $connectorId = $outlet['connector_id'];
        $idToken = $payload['idToken'] ?? null;
        $session = $this->outletSessions->find($chargerId, $evseId, $connectorId);
        if (!is_array($idToken) || !is_string($idToken['idToken'] ?? null)
            || $session === null || $session['connector_status'] !== 'Occupied'
            || $session['transaction_id'] === null || $session['remote_start_pending']
            || $session['stop_reason'] !== null || $session['id_token'] !== $idToken['idToken']) {
            throw new RuntimeException('The presented RFID does not match an active transaction that can be stopped.');
        }

        $sequence = $session['seq_no'] + 1;
        $transactionEvent = [
            'eventType' => 'Updated',
            'timestamp' => gmdate(DATE_ATOM),
            'triggerReason' => 'StopAuthorized',
            'seqNo' => $sequence,
            'transactionInfo' => [
                'transactionId' => $session['transaction_id'],
                'chargingState' => 'EVConnected',
            ],
            'evse' => ['id' => $evseId, 'connectorId' => $connectorId],
            'idToken' => $idToken,
        ];
        $queuedEvent = $this->queueTransactionEvent($chargerId, $transactionEvent);
        $this->outletSessions->markLocalStopRequested(
            $chargerId,
            $evseId,
            $connectorId,
            $session['transaction_id'],
            $idToken['idToken'],
            $sequence,
        );
        if (($this->sessions[$chargerId]['connection'] ?? null) instanceof WebSocketConnection) {
            $this->deliverTransactionEventsThrough($chargerId, $queuedEvent['id']);
        }

        return ['status' => 'LocalStopRequested'];
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

        if ($session['id_token'] === $idToken['idToken']) {
            $chargingSequence = $session['seq_no'] + 1;
            $chargingEvent = [
                'eventType' => 'Updated',
                'timestamp' => gmdate(DATE_ATOM),
                'triggerReason' => 'ChargingStateChanged',
                'seqNo' => $chargingSequence,
                'transactionInfo' => ['transactionId' => $session['transaction_id'], 'chargingState' => 'Charging'],
                'evse' => ['id' => $outlet['evse_id'], 'connectorId' => $outlet['connector_id']],
            ];
            $queuedEvent = $this->queueTransactionEvent($chargerId, $chargingEvent);
            $this->outletSessions->attachToken(
                $chargerId,
                $outlet['evse_id'],
                $outlet['connector_id'],
                $session['transaction_id'],
                $idToken['idToken'],
                $chargingSequence,
            );
            $this->deliverTransactionEventsThrough($chargerId, $queuedEvent['id']);

            return;
        }

        $sequence = $session['seq_no'] + 1;
        $authorizationEvent = [
            'eventType' => 'Updated',
            'timestamp' => gmdate(DATE_ATOM),
            'triggerReason' => 'Authorized',
            'seqNo' => $sequence,
            'transactionInfo' => ['transactionId' => $session['transaction_id']],
            'evse' => ['id' => $outlet['evse_id'], 'connectorId' => $outlet['connector_id']],
            'idToken' => $idToken,
        ];
        $queuedAuthorization = $this->queueTransactionEvent($chargerId, $authorizationEvent);
        $this->outletSessions->attachToken(
            $chargerId,
            $outlet['evse_id'],
            $outlet['connector_id'],
            $session['transaction_id'],
            $idToken['idToken'],
            $sequence,
        );
        $authorizationResult = $this->deliverTransactionEventsThrough($chargerId, $queuedAuthorization['id']);
        if (($authorizationResult['idTokenInfo']['status'] ?? null) !== 'Accepted') {
            $this->outletSessions->clearToken(
                $chargerId,
                $outlet['evse_id'],
                $outlet['connector_id'],
                $session['transaction_id'],
                $session['seq_no'],
            );
            throw new OcppProtocolException('The CSMS did not accept the idToken in the TransactionEvent response.');
        }

        $chargingSequence = $sequence + 1;
        $chargingEvent = [
            'eventType' => 'Updated',
            'timestamp' => gmdate(DATE_ATOM),
            'triggerReason' => 'ChargingStateChanged',
            'seqNo' => $chargingSequence,
            'transactionInfo' => ['transactionId' => $session['transaction_id'], 'chargingState' => 'Charging'],
            'evse' => ['id' => $outlet['evse_id'], 'connectorId' => $outlet['connector_id']],
        ];
        $queuedCharging = $this->queueTransactionEvent($chargerId, $chargingEvent);
        $this->outletSessions->attachToken(
            $chargerId,
            $outlet['evse_id'],
            $outlet['connector_id'],
            $session['transaction_id'],
            $idToken['idToken'],
            $chargingSequence,
        );
        $this->deliverTransactionEventsThrough($chargerId, $queuedCharging['id']);
    }

    private function reportReleasedOutlet(int $chargerId, int $evseId, int $connectorId): void
    {
        if ($this->resets->blocksNewTransactions($chargerId)) {
            $this->sendStatusNotification($chargerId, $evseId, $connectorId, 'Unavailable');

            return;
        }

        $changes = $this->availability->applyPendingChanges($chargerId);
        $reportedCurrentOutlet = false;
        foreach ($changes as $change) {
            if ($change['evse_id'] === $evseId && $change['connector_id'] === $connectorId) {
                $reportedCurrentOutlet = true;
            }

            $this->sendStatusNotification(
                $chargerId,
                $change['evse_id'],
                $change['connector_id'],
                $change['status'],
            );
        }

        if (!$reportedCurrentOutlet) {
            $session = $this->outletSessions->find($chargerId, $evseId, $connectorId);
            $status = $this->availability->connectorStatus(
                $chargerId,
                $evseId,
                $connectorId,
                $session['connector_status'] ?? 'Available',
            );
            $this->sendStatusNotification($chargerId, $evseId, $connectorId, $status);
        }
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

    private function sendHeartbeat(int $chargerId): void
    {
        $result = $this->createClient($chargerId)->request('Heartbeat', new \stdClass());
        if (!isset($result['currentTime']) || !is_string($result['currentTime'])) {
            throw new OcppProtocolException('The CSMS Heartbeat response did not include currentTime.');
        }
    }

    /**
     * @param array{evse_id:int,connector_id:int,connector_status:string,transaction_id:?string,id_token:?string,seq_no:int,meter_wh:int,stop_reason:?string,meter_updated_at:?string,meter_ended_at:?string,meter_end_samples:list<array<string,mixed>>,remote_start_deadline:?string,remote_start_pending:bool} $session
     */
    private function sendTriggeredTransactionEvent(int $chargerId, array $session): void
    {
        $transactionId = $session['transaction_id'];
        if (!is_string($transactionId) || $transactionId === '') {
            throw new RuntimeException('The active transaction is missing its transaction ID.');
        }

        $timestamp = gmdate(DATE_ATOM);
        $transactionInfo = ['transactionId' => $transactionId];
        if (!$session['remote_start_pending']) {
            $transactionInfo['chargingState'] = $session['id_token'] !== null && $session['stop_reason'] === null
                ? 'Charging'
                : 'EVConnected';
        }

        $event = [
            'eventType' => 'Updated',
            'timestamp' => $timestamp,
            'triggerReason' => 'Trigger',
            'seqNo' => $session['seq_no'] + 1,
            'transactionInfo' => $transactionInfo,
            'evse' => ['id' => $session['evse_id'], 'connectorId' => $session['connector_id']],
        ];
        if ($this->deviceModelSettings->get($chargerId, 'TxUpdatedMeasurands') === 'Energy.Active.Import.Register') {
            $event['meterValue'] = $this->meterValue($session['meter_wh'], 'Trigger', $timestamp);
        }

        $queuedEvent = $this->transactionEvents->enqueueForActiveTransaction(
            $chargerId,
            $event,
            $this->outletSessions,
            $session['evse_id'],
            $session['connector_id'],
            $transactionId,
            $session['seq_no'],
        );
        $this->deliverTransactionEventsThrough($chargerId, $queuedEvent['id']);
    }

    /**
     * @return list<array{timestamp:string,sampledValue:list<array{value:int,context:string,measurand:string,unitOfMeasure:array{unit:string}}>}>|null
     */
    private function transactionBeginMeterValue(int $chargerId, int $meterWh): ?array
    {
        if ($this->deviceModelSettings->get($chargerId, 'TxStartedMeasurands') !== 'Energy.Active.Import.Register') {
            return null;
        }

        return $this->meterValue($meterWh, 'Transaction.Begin');
    }

    /**
     * @return list<array{timestamp:string,sampledValue:list<array{value:int,context:string,measurand:string,unitOfMeasure:array{unit:string}}>}>|null
     */
    private function transactionEndMeterValue(int $chargerId, int $meterWh): ?array
    {
        if ($this->deviceModelSettings->get($chargerId, 'TxEndedMeasurands') !== 'Energy.Active.Import.Register') {
            return null;
        }

        return $this->meterValue($meterWh, 'Transaction.End');
    }

    /**
     * @return list<array{timestamp:string,sampledValue:list<array{value:int,context:string,measurand:string,unitOfMeasure:array{unit:string}}>}>
     */
    private function meterValue(int $meterWh, string $context, ?string $sampledAt = null): array
    {
        return [[
            'timestamp' => $sampledAt ?? gmdate(DATE_ATOM),
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
     * @return array{id:int,charger_id:int,transaction_id:string,seq_no:int,message_id:string,payload:array<string,mixed>,status:string,attempt_count:int,next_attempt_at:?int}
     */
    private function queueTransactionEvent(int $chargerId, array $payload): array
    {
        return $this->transactionEvents->enqueue($chargerId, $payload);
    }

    private function deliverPendingTransactionEvent(int $chargerId): bool
    {
        $queuedEvent = $this->transactionEvents->nextPending($chargerId);
        if ($queuedEvent === null) {
            return false;
        }

        $this->deliverTransactionEventsThrough($chargerId, $queuedEvent['id']);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function deliverTransactionEventsThrough(int $chargerId, int $targetId): array
    {
        $targetResponse = null;
        while (($queuedEvent = $this->transactionEvents->nextPending($chargerId)) !== null) {
            try {
                $response = $this->createClient($chargerId)->request(
                    'TransactionEvent',
                    $queuedEvent['payload'],
                    $queuedEvent['message_id'],
                );
                $this->transactionEvents->markDelivered($queuedEvent['id']);
                if ($queuedEvent['id'] === $targetId) {
                    $targetResponse = $response;
                }
            } catch (Throwable $exception) {
                $this->transactionEvents->recordFailure(
                    $queuedEvent['id'],
                    $exception->getMessage(),
                    $this->transactionEventMaxAttempts($chargerId),
                    $this->transactionEventRetryIntervalSeconds($chargerId),
                );
                throw $exception;
            }
        }

        if ($targetResponse === null) {
            throw new TransactionEventRetryPendingException('The transaction event is queued until its retry interval expires.');
        }

        return $targetResponse;
    }

    private function transactionEventMaxAttempts(int $chargerId): int
    {
        $configuredValue = filter_var(
            $this->deviceModelSettings->get($chargerId, 'MessageAttemptsTransactionEvent'),
            FILTER_VALIDATE_INT,
        );

        return is_int($configuredValue) && $configuredValue > 0 ? $configuredValue : 3;
    }

    private function transactionEventRetryIntervalSeconds(int $chargerId): int
    {
        $configuredValue = filter_var(
            $this->deviceModelSettings->get($chargerId, 'MessageAttemptIntervalTransactionEvent'),
            FILTER_VALIDATE_INT,
        );

        return is_int($configuredValue) && $configuredValue >= 0 ? $configuredValue : 60;
    }

    private function isOcppCallError(Throwable $exception): bool
    {
        return $exception instanceof OcppProtocolException
            && str_starts_with($exception->getMessage(), 'CSMS returned OCPP-J CALLERROR ');
    }

    private function sendPeriodicMeterValue(int $chargerId): bool
    {
        $interval = filter_var(
            $this->deviceModelSettings->get($chargerId, 'TxUpdatedInterval'),
            FILTER_VALIDATE_INT,
        );
        if (!is_int($interval) || $interval < 1
            || $this->deviceModelSettings->get($chargerId, 'TxUpdatedMeasurands') !== 'Energy.Active.Import.Register') {
            return false;
        }

        foreach ($this->outletSessions->allForCharger($chargerId) as $session) {
            if ($session['connector_status'] !== 'Occupied' || $session['transaction_id'] === null
                || $session['id_token'] === null || $session['stop_reason'] !== null
                || $session['remote_start_pending']) {
                continue;
            }

            $lastSampleAt = $session['meter_updated_at'] === null
                ? false
                : strtotime($session['meter_updated_at']);
            if ($lastSampleAt !== false && time() < $lastSampleAt + $interval) {
                continue;
            }

            $sequence = $session['seq_no'] + 1;
            $meterWh = $session['meter_wh'] + self::SIMULATED_PERIODIC_ENERGY_WH;
            $sampledAt = gmdate(DATE_ATOM);
            $transactionEvent = [
                'eventType' => 'Updated',
                'timestamp' => $sampledAt,
                'triggerReason' => 'MeterValuePeriodic',
                'seqNo' => $sequence,
                'meterValue' => $this->meterValue($meterWh, 'Sample.Periodic'),
                'transactionInfo' => [
                    'transactionId' => $session['transaction_id'],
                    'chargingState' => 'Charging',
                ],
                'evse' => ['id' => $session['evse_id'], 'connectorId' => $session['connector_id']],
            ];
            $queuedEvent = $this->queueTransactionEvent($chargerId, $transactionEvent);
            $this->outletSessions->recordMeterSample(
                $chargerId,
                $session['evse_id'],
                $session['connector_id'],
                $session['transaction_id'],
                $meterWh,
                $sequence,
                $sampledAt,
            );
            $this->deliverTransactionEventsThrough($chargerId, $queuedEvent['id']);

            return true;
        }

        return false;
    }

    private function sendClockAlignedMeterValues(int $chargerId): bool
    {
        $interval = filter_var(
            $this->deviceModelSettings->get($chargerId, 'Interval'),
            FILTER_VALIDATE_INT,
        );
        if (!is_int($interval) || $interval < 1
            || $this->deviceModelSettings->get($chargerId, 'Measurands') !== 'Energy.Active.Import.Register') {
            unset($this->alignedMeterSchedules[$chargerId]);

            return false;
        }

        $now = time();
        $schedule = new ClockAlignedMeterValueSchedule();
        if (!isset($this->alignedMeterSchedules[$chargerId])
            || $this->alignedMeterSchedules[$chargerId]['interval'] !== $interval) {
            $this->alignedMeterSchedules[$chargerId] = [
                'interval' => $interval,
                'next_at' => $schedule->nextSampleAt($interval, $now) ?? PHP_INT_MAX,
            ];

            return false;
        }

        $sampleTimestamp = $this->alignedMeterSchedules[$chargerId]['next_at'];
        if ($now < $sampleTimestamp) {
            return false;
        }

        $this->alignedMeterSchedules[$chargerId]['next_at'] = $schedule->nextSampleAt($interval, $sampleTimestamp) ?? PHP_INT_MAX;
        $sampledAt = gmdate('Y-m-d\TH:i:s\Z', $sampleTimestamp);
        $outlets = $this->outletSessions->allForCharger($chargerId);
        $activeTransactionsByEvse = [];
        foreach ($outlets as $outlet) {
            if ($outlet['connector_status'] !== 'Occupied' || !is_string($outlet['transaction_id'])
                || $outlet['transaction_id'] === '') {
                continue;
            }

            $activeTransactionsByEvse[$outlet['evse_id']][] = $outlet;
        }

        $sendDuringIdle = $this->deviceModelSettings->get($chargerId, 'SendDuringIdle') === 'true';
        foreach ($activeTransactionsByEvse as $transactions) {
            if ($sendDuringIdle) {
                continue;
            }

            foreach ($transactions as $session) {
                $sequence = $session['seq_no'] + 1;
                $meterWh = $session['meter_wh'] + self::SIMULATED_PERIODIC_ENERGY_WH;
                $transactionEvent = [
                    'eventType' => 'Updated',
                    'timestamp' => $sampledAt,
                    'triggerReason' => 'MeterValueClock',
                    'seqNo' => $sequence,
                    'meterValue' => $this->meterValue($meterWh, 'Sample.Clock', $sampledAt),
                    'transactionInfo' => [
                        'transactionId' => $session['transaction_id'],
                        'chargingState' => $session['remote_start_pending'] ? 'Idle' : 'Charging',
                    ],
                    'evse' => ['id' => $session['evse_id'], 'connectorId' => $session['connector_id']],
                ];
                $queuedEvent = $this->queueTransactionEvent($chargerId, $transactionEvent);
                $this->outletSessions->recordMeterSample(
                    $chargerId,
                    $session['evse_id'],
                    $session['connector_id'],
                    $session['transaction_id'],
                    $meterWh,
                    $sequence,
                    $sampledAt,
                );
                $this->deliverTransactionEventsThrough($chargerId, $queuedEvent['id']);
            }
        }

        $metersByEvse = [];
        foreach ($outlets as $outlet) {
            $evseId = $outlet['evse_id'];
            if (isset($activeTransactionsByEvse[$evseId])) {
                continue;
            }

            $metersByEvse[$evseId] = ($metersByEvse[$evseId] ?? 0) + $outlet['meter_wh'];
        }

        foreach ($metersByEvse as $evseId => $meterWh) {
            $this->createClient($chargerId)->request('MeterValues', [
                'evseId' => (int) $evseId,
                'meterValue' => $this->meterValue($meterWh, 'Sample.Clock', $sampledAt),
            ]);
        }

        return $activeTransactionsByEvse !== [] || $metersByEvse !== [];
    }

    private function sampleTransactionEndMeterValue(int $chargerId): bool
    {
        $interval = filter_var(
            $this->deviceModelSettings->get($chargerId, 'TxEndedInterval'),
            FILTER_VALIDATE_INT,
        );
        if (!is_int($interval) || $interval < 1
            || $this->deviceModelSettings->get($chargerId, 'TxEndedMeasurands') !== 'Energy.Active.Import.Register') {
            return false;
        }

        foreach ($this->outletSessions->allForCharger($chargerId) as $session) {
            if ($session['connector_status'] !== 'Occupied' || $session['transaction_id'] === null
                || $session['remote_start_pending']) {
                continue;
            }

            $lastSampleAt = $session['meter_ended_at'] === null
                ? false
                : strtotime($session['meter_ended_at']);
            if ($lastSampleAt !== false && time() < $lastSampleAt + $interval) {
                continue;
            }

            $sampledAt = gmdate(DATE_ATOM);
            $meterWh = $session['meter_wh'] + self::SIMULATED_PERIODIC_ENERGY_WH;
            $meterValue = $this->meterValue($meterWh, 'Sample.Periodic', $sampledAt)[0];
            $this->outletSessions->recordTransactionEndSample(
                $chargerId,
                $session['evse_id'],
                $session['connector_id'],
                $session['transaction_id'],
                $meterWh,
                $sampledAt,
                $meterValue,
            );

            return true;
        }

        return false;
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
