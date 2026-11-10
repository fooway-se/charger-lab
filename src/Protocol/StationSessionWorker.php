<?php

declare(strict_types=1);

namespace ChargerEmulator\Protocol;

use ChargerEmulator\Domain\BaseReportRequestHandler;
use ChargerEmulator\Domain\ChangeAvailabilityRequestHandler;
use ChargerEmulator\Domain\DeviceModel;
use ChargerEmulator\Domain\OutletMap;
use ChargerEmulator\Domain\RemoteStartRequestHandler;
use ChargerEmulator\Domain\RemoteStopRequestHandler;
use ChargerEmulator\Domain\ResetRequestHandler;
use ChargerEmulator\Domain\SetNetworkProfileRequestHandler;
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
use ChargerEmulator\Storage\NetworkProfileRepository;
use ChargerEmulator\Storage\WorkspaceSettingsRepository;
use RuntimeException;
use Throwable;

/**
 * Owns open OCPP connections and executes queued actions outside HTTP requests.
 */
final class StationSessionWorker
{
    private const SIMULATED_SESSION_ENERGY_WH = 1000;

    /** @var array<int, array{connection:?WebSocketConnection,url:string,connection_timeout:int,username:string,password:string,tls_server_name:string,tls_ca_certificate:string,heartbeat_interval:int,next_heartbeat_at:?float,boot_payload:array<string,mixed>,next_boot_at:?float,reconnect_attempt:int,next_reconnect_at:?float,network_profiles:array,network_profile_index:int,network_profile_attempts:int}> */
    private array $sessions = [];

    private readonly TransactionEventDelivery $transactionEventDelivery;

    private readonly MeterValueReporter $meterValueReporter;

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
        private readonly NetworkProfileRepository $networkProfiles,
    ) {
        $this->transactionEventDelivery = new TransactionEventDelivery(
            $this->transactionEvents,
            $this->outletSessions,
            $this->deviceModelSettings,
            fn (int $chargerId): OcppClient => $this->createOcppClient($chargerId),
        );
        $this->meterValueReporter = new MeterValueReporter(
            $this->outletSessions,
            $this->deviceModelSettings,
            $this->transactionEventDelivery,
            fn (int $chargerId): OcppClient => $this->createOcppClient($chargerId),
        );
    }

    public function run(int $pollMicroseconds = 20_000): never
    {
        $this->commands->recoverInterrupted();
        $this->chargers->resetConnectionStatuses();
        $this->restoreAcceptedSessions();
        while (true) {
            if (!$this->runNextCommand() && !$this->pollOcppSessions()) {
                usleep($pollMicroseconds);
            }
        }
    }

    public function runNextCommand(): bool
    {
        $this->queueDueBootRetries();
        $queuedCommand = $this->commands->claimNext();
        if ($queuedCommand === null) {
            return false;
        }

        $chargerId = $queuedCommand['charger_id'];
        $hadSession = isset($this->sessions[$chargerId])
            && $this->sessions[$chargerId]['connection'] instanceof WebSocketConnection;
        try {
            $this->executeQueuedCommand($queuedCommand);
        } catch (Throwable $exception) {
            $csmsRejectedMessage = $this->isOcppCallError($exception);
            $retryWaiting = $exception instanceof TransactionEventRetryPendingException;
            if (!$csmsRejectedMessage && !$retryWaiting && $queuedCommand['action'] !== 'BootNotification' && $hadSession) {
                $this->scheduleReconnect($chargerId);
            } elseif (!$csmsRejectedMessage && !$retryWaiting && $queuedCommand['action'] === 'BootNotification' && isset($this->sessions[$chargerId])) {
                $this->scheduleReconnect($chargerId);
            } elseif (!$csmsRejectedMessage && !$retryWaiting) {
                unset($this->sessions[$chargerId]);
            }

            $this->logs->record($chargerId, 'system', $queuedCommand['action'], $exception->getMessage());
            if ($queuedCommand['action'] === 'BootNotification') {
                $retryStatus = $queuedCommand['payload']['_retry_status'] ?? null;
                if (is_string($retryStatus) && in_array($retryStatus, ['Pending', 'Rejected'], true)) {
                    $retryAt = gmdate(DATE_ATOM, time() + 300);
                    $this->chargers->setBootStatus($chargerId, $retryStatus, $retryAt);
                } else {
                    $this->chargers->setBootStatus($chargerId, 'Connection failed');
                }
            }
            if (!$csmsRejectedMessage && !$retryWaiting && ($queuedCommand['action'] === 'BootNotification' || $hadSession)) {
                $this->chargers->setConnectionStatus($chargerId, 'Connection failed');
            }

            $this->commands->fail($queuedCommand['id'], $exception->getMessage());
        }

        return true;
    }

    private function executeQueuedCommand(array $command): void
    {
        $chargerId = $command['charger_id'];
        $charger = $this->chargers->get($chargerId);
        if ($charger === null) {
            throw new RuntimeException('The requested charger no longer exists.');
        }

        if ($command['action'] === 'LocalStop') {
            $result = $this->localStop($chargerId, $command['payload']);
            $this->commands->complete($command['id'], $result);

            return;
        }

        if ($command['action'] === 'Disconnect') {
            unset($this->sessions[$chargerId]);
            $this->chargers->setConnectionStatus($chargerId, 'Not connected');
            $this->commands->complete($command['id'], ['status' => 'Disconnected']);

            return;
        }

        $this->ensureOcppConnection($chargerId, $charger, $command['action']);
        $outletResult = match ($command['action']) {
            'PlugIn' => $this->plugIn($chargerId, $command['payload']),
            'Unplug' => $this->unplug($chargerId, $command['payload']),
            'RemoteStart' => $this->remoteStart($chargerId, $command['payload']),
            'RemoteStop' => $this->remoteStop($chargerId, $command['payload']),
            default => null,
        };
        if ($outletResult !== null) {
            $this->commands->complete($command['id'], $outletResult);

            return;
        }

        $payload = $command['payload'];
        unset($payload['_retry_status']);
        $requestedOutlet = $payload['_outlet'] ?? null;
        unset($payload['_outlet']);
        if ($command['action'] === 'BootNotification') {
            $this->sessions[$chargerId]['boot_payload'] = $payload;
        }

        $result = $this->createOcppClient($chargerId)->request($command['action'], $payload);
        if ($command['action'] === 'Authorize'
            && ($result['idTokenInfo']['status'] ?? null) === 'Accepted'
            && is_array($requestedOutlet)) {
            $this->attachAuthorizedToken($chargerId, $requestedOutlet, $payload['idToken']);
        }
        if ($command['action'] === 'BootNotification') {
            $this->applyBootNotificationResponse($chargerId, $charger, $result);
        } else {
            $this->chargers->setConnectionStatus($chargerId, 'Connected');
        }

        $this->commands->complete($command['id'], $result);
    }

    private function ensureOcppConnection(int $chargerId, array $charger, string $action): void
    {
        $session = $this->sessions[$chargerId] ?? null;
        if ($session === null) {
            $profiles = $this->prioritizedNetworkProfiles($chargerId, $charger);
            $this->sessions[$chargerId] = [
                'connection' => null,
                'url' => '',
                'connection_timeout' => 8,
                'username' => $charger['station_identity'],
                'password' => $charger['csms_password'],
                'tls_server_name' => '',
                'tls_ca_certificate' => '',
                'heartbeat_interval' => 300,
                'next_heartbeat_at' => null,
                'boot_payload' => [],
                'next_boot_at' => null,
                'reconnect_attempt' => 0,
                'next_reconnect_at' => null,
                'network_profiles' => $profiles,
                'network_profile_index' => 0,
                'network_profile_attempts' => 0,
            ];
            $this->selectNetworkProfile($chargerId, 0, $charger);
            $session = $this->sessions[$chargerId];
        }

        $profileEntry = $session['network_profiles'][$session['network_profile_index']];
        $profile = $profileEntry['connectionData'];
        $username = $profileEntry['username'];
        $password = $profileEntry['password'];
        $connectionUrl = $session['url'];
        $tlsSettings = $this->workspaceSettings->getTlsSettings();
        if ($session !== null
            && $session['url'] === $connectionUrl
            && $session['username'] === $username
            && $session['password'] === $password
            && $session['tls_server_name'] === $tlsSettings['tls_server_name']
            && $session['tls_ca_certificate'] === $tlsSettings['tls_ca_certificate']
            && $session['connection'] !== null) {
            return;
        }

        unset($this->sessions[$chargerId]);
        if ($action !== 'BootNotification') {
            throw new RuntimeException('Connect the charger with BootNotification before sending other actions.');
        }

        $this->sessions[$chargerId] = [
            'connection' => null,
            'url' => $connectionUrl,
            'connection_timeout' => $profile['messageTimeout'],
            'username' => $username,
            'password' => $password,
            'tls_server_name' => $tlsSettings['tls_server_name'],
            'tls_ca_certificate' => $tlsSettings['tls_ca_certificate'],
            'heartbeat_interval' => 300,
            'next_heartbeat_at' => null,
            'boot_payload' => [],
            'next_boot_at' => null,
            'reconnect_attempt' => 0,
            'next_reconnect_at' => null,
            'network_profiles' => $session['network_profiles'],
            'network_profile_index' => $session['network_profile_index'],
            'network_profile_attempts' => 0,
        ];
        $this->sessions[$chargerId]['connection'] = new WebSocketConnection(
            $connectionUrl,
            timeoutSeconds: $profile['messageTimeout'],
            username: $username,
            password: $password,
            tlsServerName: $tlsSettings['tls_server_name'] !== '' ? $tlsSettings['tls_server_name'] : null,
            tlsCaCertificatePem: $tlsSettings['tls_ca_certificate'] !== '' ? $tlsSettings['tls_ca_certificate'] : null,
        );
        $this->networkProfiles->rememberSuccessful($chargerId, $profile, $username, $password);
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

    private function pollOcppSessions(): bool
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
                    $tlsSettings = $this->workspaceSettings->getTlsSettings();
                    $this->sessions[$chargerId]['tls_server_name'] = $tlsSettings['tls_server_name'];
                    $this->sessions[$chargerId]['tls_ca_certificate'] = $tlsSettings['tls_ca_certificate'];
                    $this->meterValueReporter->resetSchedule($chargerId);
                    $this->sessions[$chargerId]['connection'] = new WebSocketConnection(
                        $session['url'],
                        timeoutSeconds: $session['connection_timeout'],
                        username: $session['username'],
                        password: $session['password'],
                        tlsServerName: $tlsSettings['tls_server_name'] !== '' ? $tlsSettings['tls_server_name'] : null,
                        tlsCaCertificatePem: $tlsSettings['tls_ca_certificate'] !== '' ? $tlsSettings['tls_ca_certificate'] : null,
                    );
                    $this->sessions[$chargerId]['reconnect_attempt'] = 0;
                    $this->sessions[$chargerId]['next_reconnect_at'] = null;
                    $this->sessions[$chargerId]['network_profile_attempts'] = 0;
                    $profile = $session['network_profiles'][$session['network_profile_index']]['connectionData'];
                    $this->networkProfiles->rememberSuccessful($chargerId, $profile, $session['username'], $session['password']);
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

                    $bootNotificationResponse = $this->createOcppClient($chargerId)->request('BootNotification', $session['boot_payload']);
                    $this->applyBootNotificationResponse($chargerId, $charger, $bootNotificationResponse);

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

                if ($this->transactionEventDelivery->deliverPending($chargerId)) {
                    return true;
                }

                if ($this->meterValueReporter->sendPeriodic($chargerId)) {
                    return true;
                }

                if ($this->meterValueReporter->sendClockAligned($chargerId)) {
                    return true;
                }

                if ($this->meterValueReporter->sampleTransactionEnd($chargerId)) {
                    return true;
                }

                if ($this->transactionEventDelivery->deliverPending($chargerId)) {
                    return true;
                }

                $incomingFrameJson = $session['connection']->receiveAvailable();
                if ($incomingFrameJson !== null) {
                    $bootStatus = $this->currentBootStatus($this->chargers->get($chargerId));
                    $this->createOcppClient($chargerId)->answerIncomingCallMessage($incomingFrameJson, $bootStatus);

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

            $profiles = $this->prioritizedNetworkProfiles($chargerId, $charger);
            $tlsSettings = $this->workspaceSettings->getTlsSettings();
            $this->transactionEvents->markPendingOffline($chargerId);
            $this->sessions[$chargerId] = [
                'connection' => null,
                'url' => '',
                'connection_timeout' => 8,
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
                'network_profiles' => $profiles,
                'network_profile_index' => 0,
                'network_profile_attempts' => 0,
            ];
            $this->selectNetworkProfile($chargerId, 0, $charger);
        }
    }

    private function scheduleReconnect(int $chargerId): void
    {
        if (!isset($this->sessions[$chargerId])) {
            return;
        }

        $connectionFailedBeforeOpen = $this->sessions[$chargerId]['connection'] === null;
        if ($connectionFailedBeforeOpen) {
            $attempts = $this->sessions[$chargerId]['network_profile_attempts'] + 1;
            $maximumAttempts = $this->deviceModelSettingInt($chargerId, 'NetworkProfileConnectionAttempts', 3, 1);
            if ($attempts >= $maximumAttempts) {
                $charger = $this->chargers->get($chargerId);
                if ($charger === null) {
                    unset($this->sessions[$chargerId]);

                    return;
                }
                $index = ($this->sessions[$chargerId]['network_profile_index'] + 1)
                    % count($this->sessions[$chargerId]['network_profiles']);
                $this->selectNetworkProfile($chargerId, $index, $charger);
                $attempts = 0;
            }
            $this->sessions[$chargerId]['network_profile_attempts'] = $attempts;
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

    private function prioritizedNetworkProfiles(int $chargerId, array $charger): array
    {
        $priority = $this->deviceModelSettings->get($chargerId, 'NetworkConfigurationPriority') ?? '0';
        $profiles = $this->networkProfiles->prioritized($chargerId, $charger, $priority);

        return $profiles === [] ? $this->networkProfiles->prioritized($chargerId, $charger, '0') : $profiles;
    }

    private function selectNetworkProfile(int $chargerId, int $index, array $charger): void
    {
        $profile = $this->sessions[$chargerId]['network_profiles'][$index];
        $this->sessions[$chargerId]['network_profile_index'] = $index;
        $this->sessions[$chargerId]['url'] = OcppConnectionUrl::fromEndpoint(
            $profile['connectionData']['ocppCsmsUrl'],
            $charger['station_identity'],
        );
        $this->sessions[$chargerId]['connection_timeout'] = $profile['connectionData']['messageTimeout'];
        $this->sessions[$chargerId]['username'] = $profile['username'];
        $this->sessions[$chargerId]['password'] = $profile['password'];
    }

    private function deviceModelSettingInt(int $chargerId, string $variable, int $default, int $minimum): int
    {
        return $this->deviceModelSettings->getInteger($chargerId, $variable, $default, $minimum);
    }

    private function currentBootStatus(?array $charger): ?string
    {
        $bootStatus = $charger['boot_status'] ?? null;

        return is_string($bootStatus) ? $bootStatus : null;
    }

    private function applyBootNotificationResponse(int $chargerId, array $charger, array $bootNotificationResponse): void
    {
        if ($this->resets->isReconnecting($chargerId)) {
            $this->resets->complete($chargerId);
        }

        $bootStatus = is_string($bootNotificationResponse['status'] ?? null) ? $bootNotificationResponse['status'] : 'Unknown response';
        $retryIntervalSeconds = $bootNotificationResponse['interval'] ?? 300;
        $retryIntervalSeconds = is_int($retryIntervalSeconds) && $retryIntervalSeconds > 0 ? $retryIntervalSeconds : 300;
        if ($bootStatus === 'Accepted') {
            $this->sessions[$chargerId]['next_boot_at'] = null;
            $this->sessions[$chargerId]['heartbeat_interval'] = $retryIntervalSeconds;
            $this->sessions[$chargerId]['next_heartbeat_at'] = microtime(true) + $retryIntervalSeconds;
            $this->deviceModelSettings->set($chargerId, 'HeartbeatInterval', (string) $retryIntervalSeconds);
            $this->chargers->setBootStatus($chargerId, $bootStatus);
            $this->chargers->setConnectionStatus($chargerId, 'Connected');
            $this->reportAvailableOutlets($chargerId, $charger);

            return;
        }

        $this->sessions[$chargerId]['next_boot_at'] = microtime(true) + $retryIntervalSeconds;
        $retryAt = gmdate(DATE_ATOM, (int) ceil($this->sessions[$chargerId]['next_boot_at']));
        $this->chargers->setBootStatus($chargerId, $bootStatus, $retryAt);
        $this->sessions[$chargerId]['next_heartbeat_at'] = null;
        $this->chargers->setConnectionStatus($chargerId, $bootStatus);
    }

    private function createOcppClient(int $chargerId): OcppClient
    {
        $deviceModel = new DeviceModel(
            $this->outletSessions,
            $this->deviceModelSettings,
            $chargerId,
            $this->availability,
        );
        $baseReport = new BaseReportRequestHandler($deviceModel);
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
        $networkProfile = new SetNetworkProfileRequestHandler($this->networkProfiles, $chargerId);

        $connection = $this->sessions[$chargerId]['connection'];
        if (!$connection instanceof WebSocketConnection) {
            throw new RuntimeException('The charger is waiting to reconnect to the CSMS.');
        }

        return new OcppClient(
            $connection,
            function (string $direction, string $action, string $message) use ($chargerId): void {
                $this->logs->record($chargerId, $direction, $action, $message);
            },
            function (string $action, array $payload) use ($deviceModel, $baseReport, $changeAvailability, $remoteStart, $remoteStop, $reset, $transactionStatus, $triggerMessage, $networkProfile, $chargerId): ?array {
                $deviceModelResponse = $deviceModel->processVariableAction($action, $payload);
                if ($deviceModelResponse !== null) {
                    return $deviceModelResponse;
                }

                if ($action === 'GetBaseReport') {
                    return $baseReport->getBaseReport($payload);
                }

                if ($action === 'SetNetworkProfile') {
                    return $networkProfile->setNetworkProfile($payload);
                }

                if ($action === 'ChangeAvailability') {
                    return $changeAvailability->requestChange($payload);
                }

                if ($action === 'RequestStartTransaction') {
                    return $remoteStart->requestStart($payload);
                }

                if ($action === 'RequestStopTransaction') {
                    return $remoteStop->requestStop($payload);
                }

                if ($action === 'Reset') {
                    return $reset->requestReset($payload);
                }

                if ($action === 'TriggerMessage') {
                    $bootStatus = ($payload['requestedMessage'] ?? null) === 'BootNotification'
                        ? $this->currentBootStatus($this->chargers->get($chargerId))
                        : null;

                    return $triggerMessage->triggerMessage($payload, $bootStatus);
                }

                return $action === 'GetTransactionStatus' ? $transactionStatus->getTransactionStatus($payload) : null;
            },
            function () use ($chargerId): ?string {
                return $this->currentBootStatus($this->chargers->get($chargerId));
            },
            function (string $action, array $_payload, array $response) use ($baseReport, $changeAvailability, $chargerId, $triggerMessage): void {
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
                            $this->createOcppClient($chargerId)->request('StatusNotification', $statusNotification);
                        }

                        foreach ($triggerMessage->takeTransactionSessions() as $session) {
                            $this->sendTriggeredTransactionEvent($chargerId, $session);
                        }

                        foreach ($triggerMessage->takeMeterValueRequests() as $meterValueRequest) {
                            $this->createOcppClient($chargerId)->request('MeterValues', [
                                'evseId' => $meterValueRequest['evse_id'],
                                'meterValue' => $this->meterValueReporter->value($meterValueRequest['meter_wh'], 'Trigger'),
                            ]);
                        }
                    }

                    return;
                }

                if ($action === 'GetBaseReport' && ($response['status'] ?? null) === 'Accepted') {
                    $report = $baseReport->takeReport();
                    if ($report !== null) {
                        $this->createOcppClient($chargerId)->request('NotifyReport', $report);
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

            $this->endOutletTransaction($chargerId, $activeSessions[0], 'ImmediateReset', 'ImmediateReset');

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
        $this->sessions[$chargerId]['network_profiles'] = $this->prioritizedNetworkProfiles($chargerId, $charger);
        $this->sessions[$chargerId]['network_profile_attempts'] = 0;
        $this->selectNetworkProfile($chargerId, 0, $charger);
        $this->sessions[$chargerId]['boot_payload'] = $this->resetBootPayload($charger);
        $this->sessions[$chargerId]['next_boot_at'] = microtime(true);
        $this->sessions[$chargerId]['next_heartbeat_at'] = null;
        $this->sessions[$chargerId]['reconnect_attempt'] = 1;
        $this->sessions[$chargerId]['next_reconnect_at'] = microtime(true) + 0.2;
        $this->chargers->setBootStatus($chargerId, 'Not connected');
        $this->chargers->setConnectionStatus($chargerId, 'Not connected');

        return true;
    }

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
        $meterValue = $this->meterValueReporter->beginTransactionValue($chargerId, $meterWh);
        if ($meterValue !== null) {
            $transactionEvent['meterValue'] = $meterValue;
        }

        $queuedEvent = $this->transactionEventDelivery->enqueue($chargerId, $transactionEvent);
        $this->outletSessions->markOccupied($chargerId, $evseId, $connectorId, $transactionId);
        $this->sendStatusNotification($chargerId, $evseId, $connectorId, 'Occupied');
        $this->transactionEventDelivery->deliverThrough($chargerId, $queuedEvent['id']);

        return ['status' => 'PluggedIn', 'transactionId' => $transactionId];
    }

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
        $meterValue = $this->meterValueReporter->beginTransactionValue($chargerId, $session['meter_wh']);
        if ($meterValue !== null) {
            $transactionEvent['meterValue'] = $meterValue;
        }

        $queuedEvent = $this->transactionEventDelivery->enqueue($chargerId, $transactionEvent);
        $this->sendStatusNotification($chargerId, $evseId, $connectorId, 'Occupied');
        $result = $this->transactionEventDelivery->deliverThrough($chargerId, $queuedEvent['id']);
        if (($result['idTokenInfo']['status'] ?? null) !== 'Accepted') {
            $this->outletSessions->markAvailable($chargerId, $evseId, $connectorId, $transactionId, $session['meter_wh']);
            $this->reportReleasedOutlet($chargerId, $evseId, $connectorId);
            throw new OcppProtocolException('The CSMS did not accept the remote-start idToken in the TransactionEvent response.');
        }

        return ['status' => 'AwaitingVehicle', 'transactionId' => $transactionId];
    }

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
        $queuedConnectedEvent = $this->transactionEventDelivery->enqueue($chargerId, $connectedEvent);
        $this->outletSessions->markRemoteStartConnected(
            $chargerId,
            $evseId,
            $connectorId,
            $transactionId,
            $connectedSequence,
        );
        $this->transactionEventDelivery->deliverThrough($chargerId, $queuedConnectedEvent['id']);

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
        $queuedChargingEvent = $this->transactionEventDelivery->enqueue($chargerId, $chargingEvent);
        $this->outletSessions->updateSequence($chargerId, $evseId, $connectorId, $transactionId, $chargingSequence);
        $this->transactionEventDelivery->deliverThrough($chargerId, $queuedChargingEvent['id']);

        return ['status' => 'PluggedIn', 'transactionId' => $transactionId];
    }

    private function expireRemoteStart(int $chargerId): bool
    {
        $dueSessions = $this->outletSessions->dueRemoteStarts($chargerId, gmdate(DATE_ATOM));
        if ($dueSessions === []) {
            return false;
        }

        $this->endOutletTransaction($chargerId, $dueSessions[0], 'TimeLimitReached', 'Timeout');

        return true;
    }

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
            $this->endOutletTransaction($chargerId, $session, 'RemoteStop', 'Remote');

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
        $queuedEvent = $this->transactionEventDelivery->enqueue($chargerId, $transactionEvent);
        $this->outletSessions->markRemoteStopRequested($chargerId, $evseId, $connectorId, $transactionId, $sequence);
        $this->transactionEventDelivery->deliverThrough($chargerId, $queuedEvent['id']);

        return ['status' => 'StopRequested'];
    }

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

        $meterWh = $session['meter_wh'] + self::SIMULATED_SESSION_ENERGY_WH;
        $stoppedReason = $session['stop_reason'] === 'Remote' ? 'Remote' : 'EVDisconnected';
        $this->endOutletTransaction(
            $chargerId,
            $session,
            'EVCommunicationLost',
            $stoppedReason,
            chargingState: 'Idle',
            meterWh: $meterWh,
            meterValues: $session['meter_end_samples'],
        );

        return ['status' => 'Unplugged'];
    }

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
        $queuedEvent = $this->transactionEventDelivery->enqueue($chargerId, $transactionEvent);
        $this->outletSessions->markLocalStopRequested(
            $chargerId,
            $evseId,
            $connectorId,
            $session['transaction_id'],
            $idToken['idToken'],
            $sequence,
        );
        if (($this->sessions[$chargerId]['connection'] ?? null) instanceof WebSocketConnection) {
            $this->transactionEventDelivery->deliverThrough($chargerId, $queuedEvent['id']);
        }

        return ['status' => 'LocalStopRequested'];
    }

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
            $queuedEvent = $this->transactionEventDelivery->enqueue($chargerId, $chargingEvent);
            $this->outletSessions->attachToken(
                $chargerId,
                $outlet['evse_id'],
                $outlet['connector_id'],
                $session['transaction_id'],
                $idToken['idToken'],
                $chargingSequence,
            );
            $this->transactionEventDelivery->deliverThrough($chargerId, $queuedEvent['id']);

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
        $queuedAuthorization = $this->transactionEventDelivery->enqueue($chargerId, $authorizationEvent);
        $this->outletSessions->attachToken(
            $chargerId,
            $outlet['evse_id'],
            $outlet['connector_id'],
            $session['transaction_id'],
            $idToken['idToken'],
            $sequence,
        );
        $authorizationResult = $this->transactionEventDelivery->deliverThrough($chargerId, $queuedAuthorization['id']);
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
        $queuedCharging = $this->transactionEventDelivery->enqueue($chargerId, $chargingEvent);
        $this->outletSessions->attachToken(
            $chargerId,
            $outlet['evse_id'],
            $outlet['connector_id'],
            $session['transaction_id'],
            $idToken['idToken'],
            $chargingSequence,
        );
        $this->transactionEventDelivery->deliverThrough($chargerId, $queuedCharging['id']);
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

    private function endOutletTransaction(
        int $chargerId,
        array $session,
        string $triggerReason,
        string $stoppedReason,
        ?string $chargingState = null,
        ?int $meterWh = null,
        array $meterValues = [],
    ): void {
        $timestamp = gmdate(DATE_ATOM);
        $transactionInfo = [
            'transactionId' => $session['transaction_id'],
            'stoppedReason' => $stoppedReason,
        ];
        if ($chargingState !== null) {
            $transactionInfo['chargingState'] = $chargingState;
        }

        $meterValues = [
            ...$meterValues,
            ...($this->meterValueReporter->endTransactionValue($chargerId, $meterWh ?? $session['meter_wh']) ?? []),
        ];
        $event = [
            'eventType' => 'Ended',
            'timestamp' => $timestamp,
            'triggerReason' => $triggerReason,
            'seqNo' => $session['seq_no'] + 1,
            'transactionInfo' => $transactionInfo,
            'evse' => ['id' => $session['evse_id'], 'connectorId' => $session['connector_id']],
        ];
        if ($meterValues !== []) {
            $event['meterValue'] = $meterValues;
        }

        $queuedEvent = $this->transactionEventDelivery->enqueue($chargerId, $event);
        $this->outletSessions->markAvailable(
            $chargerId,
            $session['evse_id'],
            $session['connector_id'],
            $session['transaction_id'],
            $meterWh ?? $session['meter_wh'],
        );
        $this->transactionEventDelivery->deliverThrough($chargerId, $queuedEvent['id']);
        $this->reportReleasedOutlet($chargerId, $session['evse_id'], $session['connector_id']);
    }

    private function sendStatusNotification(int $chargerId, int $evseId, int $connectorId, string $status): void
    {
        $this->createOcppClient($chargerId)->request('StatusNotification', [
            'timestamp' => gmdate(DATE_ATOM),
            'connectorStatus' => $status,
            'evseId' => $evseId,
            'connectorId' => $connectorId,
        ]);
    }

    private function sendHeartbeat(int $chargerId): void
    {
        $result = $this->createOcppClient($chargerId)->request('Heartbeat', new \stdClass());
        if (!isset($result['currentTime']) || !is_string($result['currentTime'])) {
            throw new OcppProtocolException('The CSMS Heartbeat response did not include currentTime.');
        }
    }

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
            $event['meterValue'] = $this->meterValueReporter->value($session['meter_wh'], 'Trigger', $timestamp);
        }

        $queuedEvent = $this->transactionEventDelivery->enqueueForActiveTransaction(
            $chargerId,
            $event,
            $session['evse_id'],
            $session['connector_id'],
            $transactionId,
            $session['seq_no'],
        );
        $this->transactionEventDelivery->deliverThrough($chargerId, $queuedEvent['id']);
    }

    private function isOcppCallError(Throwable $exception): bool
    {
        return $exception instanceof OcppProtocolException
            && str_starts_with($exception->getMessage(), 'CSMS returned OCPP-J CALLERROR ');
    }

    private function requiredOutletId(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;
        if (!is_int($value) || $value < 1) {
            throw new RuntimeException('The outlet command contains an invalid EVSE or connector ID.');
        }

        return $value;
    }
}
