<?php

declare(strict_types=1);

namespace ChargerEmulator\Protocol;

use ChargerEmulator\Domain\BaseReportRequestHandler;
use ChargerEmulator\Domain\ChangeAvailabilityRequestHandler;
use ChargerEmulator\Domain\ClearCacheRequestHandler;
use ChargerEmulator\Domain\CertificateManagementManager;
use ChargerEmulator\Domain\CustomerInformationRequestHandler;
use ChargerEmulator\Domain\DataTransferRequestHandler;
use ChargerEmulator\Domain\DeviceModel;
use ChargerEmulator\Domain\FirmwareSignatureException;
use ChargerEmulator\Domain\FirmwareInstallationVerificationException;
use ChargerEmulator\Domain\FirmwareUpdateManager;
use ChargerEmulator\Domain\LogRetrievalManager;
use ChargerEmulator\Domain\GetLocalListVersionRequestHandler;
use ChargerEmulator\Domain\MeterMeasurands;
use ChargerEmulator\Domain\OutletMap;
use ChargerEmulator\Domain\RemoteStartRequestHandler;
use ChargerEmulator\Domain\RemoteStopRequestHandler;
use ChargerEmulator\Domain\ResetRequestHandler;
use ChargerEmulator\Domain\SendLocalListRequestHandler;
use ChargerEmulator\Domain\SimulatedMeter;
use ChargerEmulator\Domain\SetNetworkProfileRequestHandler;
use ChargerEmulator\Domain\TransactionStatusRequestHandler;
use ChargerEmulator\Domain\TriggerMessageRequestHandler;
use ChargerEmulator\Domain\UnlockConnectorRequestHandler;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\AvailabilityRepository;
use ChargerEmulator\Storage\DeviceModelSettingsRepository;
use ChargerEmulator\Storage\ExchangeRepository;
use ChargerEmulator\Storage\OcppCommandRepository;
use ChargerEmulator\Storage\OutletSessionRepository;
use ChargerEmulator\Storage\TransactionEventOutboxRepository;
use ChargerEmulator\Storage\ResetRepository;
use ChargerEmulator\Storage\NetworkProfileRepository;
use ChargerEmulator\Storage\LocalAuthorizationListRepository;
use ChargerEmulator\Storage\OfflineStatusRepository;
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

    private readonly LogRetrievalManager $logRetrievals;

    /** @var array<int, true> */
    private array $firmwareUnavailableReported = [];

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
        private readonly LocalAuthorizationListRepository $authorizationLists,
        private readonly OfflineStatusRepository $offlineStatuses,
        private readonly ?FirmwareUpdateManager $firmwareUpdates = null,
        private readonly ?CertificateManagementManager $certificateManagement = null,
    ) {
        $this->transactionEventDelivery = new TransactionEventDelivery(
            $this->transactionEvents,
            $this->outletSessions,
            $this->deviceModelSettings,
            fn (int $chargerId): OcppClient => $this->createOcppClient($chargerId),
            $this->offlineStatuses,
        );
        $this->meterValueReporter = new MeterValueReporter(
            $this->outletSessions,
            $this->deviceModelSettings,
            $this->transactionEventDelivery,
            fn (int $chargerId): OcppClient => $this->createOcppClient($chargerId),
        );
        $this->logRetrievals = new LogRetrievalManager($this->logs);
    }

    public function run(int $pollMicroseconds = 20_000): never
    {
        $this->commands->recoverInterrupted();
        $this->firmwareUpdates?->recoverInterrupted();
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

        $isOfflinePhysicalAction = in_array($command['action'], ['PlugIn', 'Unplug'], true)
            && ($this->sessions[$chargerId]['connection'] ?? null) === null
            && ($charger['boot_status'] ?? null) === 'Accepted';
        if (!$isOfflinePhysicalAction) {
            $this->ensureOcppConnection($chargerId, $charger, $command['action']);
        }
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
            $payload['chargingStation']['firmwareVersion'] = $this->firmwareVersion($chargerId);
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
        $tlsCaCertificate = $this->tlsTrustBundle($chargerId, $tlsSettings['tls_ca_certificate']);
        if ($session !== null
            && $session['url'] === $connectionUrl
            && $session['username'] === $username
            && $session['password'] === $password
            && $session['tls_server_name'] === $tlsSettings['tls_server_name']
            && $session['tls_ca_certificate'] === ($tlsCaCertificate ?? '')
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
            'tls_ca_certificate' => $tlsCaCertificate ?? '',
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
            tlsCaCertificatePem: $tlsCaCertificate,
        );
        $activeProfile = $this->sessions[$chargerId]['network_profiles'][$this->sessions[$chargerId]['network_profile_index']];
        $this->networkProfiles->rememberSuccessful($chargerId, $profile, $username, $password);
        $this->deviceModelSettings->set($chargerId, 'ActiveNetworkProfile', (string) $this->activeNetworkProfileSlot($chargerId, $activeProfile));
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
                    $tlsCaCertificate = $this->tlsTrustBundle($chargerId, $tlsSettings['tls_ca_certificate']);
                    $this->sessions[$chargerId]['tls_server_name'] = $tlsSettings['tls_server_name'];
                    $this->sessions[$chargerId]['tls_ca_certificate'] = $tlsCaCertificate ?? '';
                    $this->meterValueReporter->resetSchedule($chargerId);
                    $this->sessions[$chargerId]['connection'] = new WebSocketConnection(
                        $session['url'],
                        timeoutSeconds: $session['connection_timeout'],
                        username: $session['username'],
                        password: $session['password'],
                        tlsServerName: $tlsSettings['tls_server_name'] !== '' ? $tlsSettings['tls_server_name'] : null,
                        tlsCaCertificatePem: $tlsCaCertificate,
                    );
                    $this->sessions[$chargerId]['reconnect_attempt'] = 0;
                    $this->sessions[$chargerId]['next_reconnect_at'] = null;
                    $this->sessions[$chargerId]['network_profile_attempts'] = 0;
                    $profile = $session['network_profiles'][$session['network_profile_index']]['connectionData'];
                    $this->networkProfiles->rememberSuccessful($chargerId, $profile, $session['username'], $session['password']);
                    $this->deviceModelSettings->set(
                        $chargerId,
                        'ActiveNetworkProfile',
                        (string) $this->activeNetworkProfileSlot(
                            $chargerId,
                            $session['network_profiles'][$session['network_profile_index']],
                        ),
                    );
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

                $incomingFrameJson = $session['connection']->receiveAvailable();
                if ($incomingFrameJson !== null) {
                    $bootStatus = $this->currentBootStatus($this->chargers->get($chargerId));
                    $this->createOcppClient($chargerId)->answerIncomingCallMessage($incomingFrameJson, $bootStatus);

                    return true;
                }

                if ($this->reportOfflineStatusChanges($chargerId)) {
                    return true;
                }

                if ($this->processLogRetrieval($chargerId)) {
                    return true;
                }

                if ($session['next_heartbeat_at'] !== null && microtime(true) >= $session['next_heartbeat_at']) {
                    $this->sendHeartbeat($chargerId);
                    $interval = $this->sessions[$chargerId]['heartbeat_interval'];
                    $this->sessions[$chargerId]['next_heartbeat_at'] = microtime(true) + $interval;
                    $this->chargers->setConnectionStatus($chargerId, 'Connected');

                    return true;
                }

                if ($this->processFirmwareUpdate($chargerId)) {
                    return true;
                }

                if ($this->reportPendingResetUnavailable($chargerId)) {
                    return true;
                }

                if ($this->processPendingReset($chargerId)) {
                    return true;
                }

                if ($this->expireRemoteStart($chargerId) || $this->expireAuthorizationFirstStart($chargerId)) {
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
            $firmwareUpdate = $this->firmwareUpdates?->current($chargerId);
            $firmwareRebootPending = ($firmwareUpdate['state'] ?? null) === 'InstallRebooting';
            if (($charger['boot_status'] ?? null) !== 'Accepted' && !$resetReconnecting && !$firmwareRebootPending) {
                continue;
            }
            if (($charger['boot_status'] ?? null) === 'Accepted') {
                $this->offlineStatuses->begin($chargerId);
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
                'boot_payload' => ($resetReconnecting || $firmwareRebootPending)
                    ? $this->resetBootPayload($charger, $firmwareRebootPending ? 'FirmwareUpdate' : 'RemoteReset')
                    : [],
                'next_boot_at' => ($resetReconnecting || $firmwareRebootPending) ? microtime(true) : null,
                'reconnect_attempt' => 1,
                'next_reconnect_at' => microtime(true) + $this->nextReconnectDelay($chargerId, 1),
                'network_profiles' => $profiles,
                'network_profile_index' => 0,
                'network_profile_attempts' => 0,
            ];
            $this->selectNetworkProfile($chargerId, 0, $charger);
            if ($firmwareRebootPending) {
                $this->chargers->setBootStatus($chargerId, 'Not connected');
            }
        }
    }

    private function scheduleReconnect(int $chargerId): void
    {
        if (!isset($this->sessions[$chargerId])) {
            return;
        }

        // If a status exchange failed during a synchronous download or install phase,
        // put that phase back into a schedulable state before this charger reconnects.
        $this->firmwareUpdates?->recoverInterrupted();

        $connectionFailedBeforeOpen = $this->sessions[$chargerId]['connection'] === null;
        $charger = $this->chargers->get($chargerId);
        if (!$connectionFailedBeforeOpen && ($charger['boot_status'] ?? null) === 'Accepted') {
            $this->offlineStatuses->begin($chargerId);
        }
        if ($connectionFailedBeforeOpen) {
            $attempts = $this->sessions[$chargerId]['network_profile_attempts'] + 1;
            $maximumAttempts = $this->deviceModelSettingInt($chargerId, 'NetworkProfileConnectionAttempts', 3, 1);
            if ($attempts >= $maximumAttempts) {
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

        $firmwareUpdate = $this->firmwareUpdates?->current($chargerId);
        if (($firmwareUpdate['state'] ?? null) === 'InstallRebooting') {
            $charger = $this->chargers->get($chargerId);
            if ($charger !== null) {
                $this->sessions[$chargerId]['boot_payload'] = $this->resetBootPayload($charger, 'FirmwareUpdate');
                $this->sessions[$chargerId]['next_boot_at'] = $this->sessions[$chargerId]['next_reconnect_at'];
                $this->sessions[$chargerId]['next_heartbeat_at'] = null;
                $this->chargers->setBootStatus($chargerId, 'Not connected');
            }
        }
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
        $profiles = $profiles === [] ? $this->networkProfiles->prioritized($chargerId, $charger, '0') : $profiles;
        foreach ($profiles as &$profile) {
            $profile['password'] = $charger['csms_password'];
        }
        unset($profile);

        return $profiles;
    }

    private function basicAuthPasswordUpdateAccepted(array $payload, array $response): bool
    {
        foreach ($payload['setVariableData'] ?? [] as $index => $variable) {
            if (($variable['component']['name'] ?? null) === 'SecurityCtrlr'
                && ($variable['variable']['name'] ?? null) === 'BasicAuthPassword'
                && ($response['setVariableResult'][$index]['attributeStatus'] ?? null) === 'Accepted') {
                return true;
            }
        }

        return false;
    }

    private function reconnectAfterBasicAuthPasswordChange(int $chargerId): void
    {
        if (!isset($this->sessions[$chargerId])) {
            return;
        }

        $charger = $this->chargers->get($chargerId);
        if ($charger === null) {
            return;
        }

        $password = (string) $charger['csms_password'];
        foreach ($this->sessions[$chargerId]['network_profiles'] as &$profile) {
            $profile['password'] = $password;
        }
        unset($profile);

        $this->sessions[$chargerId]['password'] = $password;
        if (($charger['boot_status'] ?? null) === 'Accepted') {
            $this->offlineStatuses->begin($chargerId);
        }
        $this->sessions[$chargerId]['connection'] = null;
        $this->sessions[$chargerId]['next_reconnect_at'] = microtime(true);
        $this->sessions[$chargerId]['reconnect_attempt'] = 0;
        $this->sessions[$chargerId]['network_profile_attempts'] = 0;
        $this->sessions[$chargerId]['next_heartbeat_at'] = null;
        $this->transactionEvents->markPendingOffline($chargerId);
    }

    private function redactBasicAuthPasswordFromLog(string $direction, string $action, string $message): string
    {
        if ($direction !== 'incoming' || $action !== 'SetVariables') {
            return $message;
        }

        $frame = json_decode($message, true);
        if (!is_array($frame) || !is_array($frame[3] ?? null)) {
            return '[redacted SetVariables request]';
        }

        foreach ($frame[3]['setVariableData'] ?? [] as $index => $variable) {
            if (($variable['component']['name'] ?? null) === 'SecurityCtrlr'
                && ($variable['variable']['name'] ?? null) === 'BasicAuthPassword'
                && array_key_exists('attributeValue', $variable)) {
                $frame[3]['setVariableData'][$index]['attributeValue'] = '[redacted]';
            }
        }

        return json_encode($frame, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
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
            $firmwareUpdate = $this->firmwareUpdates?->current($chargerId);
            if (($firmwareUpdate['state'] ?? null) === 'InstallRebooting') {
                $this->sendFirmwareStatusNotification($chargerId, (int) $firmwareUpdate['request_id'], 'Installed');
                $this->sendSecurityEventNotification($chargerId, 'FirmwareUpdated');
                $this->firmwareUpdates?->complete($chargerId);
            }
            $this->reportAvailableOutlets($chargerId, $charger);
            unset($this->firmwareUnavailableReported[$chargerId]);
            $this->offlineStatuses->clear($chargerId);
            $this->sendSecurityEventNotification($chargerId, 'StartupOfTheDevice');

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
            fn (string $password): mixed => $this->chargers->setCsmsPassword($chargerId, $password),
            fn (array $component, array $variable): ?string => $this->runtimeDeviceModelVariableValue($chargerId, $component, $variable),
            $this->networkProfiles,
            fn (string $priority): bool => $this->canApplyNetworkConfigurationPriority($chargerId, $priority),
            fn (array $_outlet): bool => $this->firmwareBlocksNewSessions($chargerId),
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
        $unlockConnector = new UnlockConnectorRequestHandler($chargerId, $this->outletSessions);
        $dataTransfer = new DataTransferRequestHandler();
        $customerInformation = new CustomerInformationRequestHandler($chargerId, $this->authorizationLists);
        $clearCache = new ClearCacheRequestHandler();
        $localListVersion = new GetLocalListVersionRequestHandler($chargerId, $this->authorizationLists);
        $sendLocalList = new SendLocalListRequestHandler($chargerId, $this->authorizationLists);

        $connection = $this->sessions[$chargerId]['connection'];
        if (!$connection instanceof WebSocketConnection) {
            throw new RuntimeException('The charger is waiting to reconnect to the CSMS.');
        }

        return new OcppClient(
            $connection,
            function (string $direction, string $action, string $message) use ($chargerId): void {
                $this->logs->record(
                    $chargerId,
                    $direction,
                    $action,
                    $this->redactBasicAuthPasswordFromLog($direction, $action, $message),
                );
            },
            function (string $action, array $payload) use ($deviceModel, $baseReport, $changeAvailability, $remoteStart, $remoteStop, $reset, $transactionStatus, $triggerMessage, $networkProfile, $unlockConnector, $dataTransfer, $customerInformation, $clearCache, $localListVersion, $sendLocalList, $chargerId): ?array {
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

                if ($action === 'UnlockConnector') {
                    return $unlockConnector->unlock($payload);
                }

                if ($action === 'DataTransfer') {
                    return $dataTransfer->transfer($payload);
                }

                if ($action === 'GetLog') {
                    return $this->logRetrievals->request($chargerId, $payload);
                }

                if ($action === 'CustomerInformation') {
                    return $customerInformation->request($payload);
                }

                if ($action === 'ClearCache') {
                    return $clearCache->clear($payload);
                }

                if ($action === 'GetLocalListVersion') {
                    return $localListVersion->getVersion($payload);
                }

                if ($action === 'SendLocalList') {
                    return $sendLocalList->send($payload);
                }

                if ($action === 'ChangeAvailability') {
                    return $changeAvailability->requestChange($payload);
                }

                if ($action === 'RequestStartTransaction') {
                    if ($this->firmwareBlocksNewSessions($chargerId)) {
                        return ['status' => 'Rejected'];
                    }

                    return $remoteStart->requestStart($payload);
                }

                if ($action === 'RequestStopTransaction') {
                    return $remoteStop->requestStop($payload);
                }

                if ($action === 'Reset') {
                    return $reset->requestReset($payload);
                }

                if ($action === 'UpdateFirmware') {
                    return $this->firmwareUpdates?->requestUpdate($chargerId, $payload);
                }

                if ($action === 'InstallCertificate') {
                    return $this->certificateManagement?->install($chargerId, $payload);
                }

                if ($action === 'GetInstalledCertificateIds') {
                    return $this->certificateManagement?->getInstalledCertificateIds($chargerId, $payload);
                }

                if ($action === 'DeleteCertificate') {
                    return $this->certificateManagement?->delete($chargerId, $payload);
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
            function (string $action, array $_payload, array $response) use ($baseReport, $changeAvailability, $chargerId, $triggerMessage, $customerInformation): void {
                if ($action === 'SetVariables') {
                    if ($this->basicAuthPasswordUpdateAccepted($_payload, $response)) {
                        $this->reconnectAfterBasicAuthPasswordChange($chargerId);
                    }

                    return;
                }

                if ($action === 'Reset') {
                    if (in_array($response['status'] ?? null, ['Accepted', 'Scheduled'], true)) {
                        $this->reportPendingResetUnavailable($chargerId);
                        $this->processPendingReset($chargerId);
                    }

                    return;
                }

                if ($action === 'UpdateFirmware') {
                    $securityEvent = $this->firmwareUpdates?->takePendingSecurityEvent();
                    if (is_string($securityEvent)) {
                        $this->sendSecurityEventNotification($chargerId, $securityEvent);
                    }

                    if (($response['status'] ?? null) === 'Accepted' && $this->firmwareUpdates !== null) {
                        $update = $this->firmwareUpdates->current($chargerId);
                        if ((int) ($update['request_id'] ?? -1) === (int) ($_payload['requestId'] ?? -2)) {
                            $this->sendFirmwareStatusNotification($chargerId, (int) $update['request_id'], 'DownloadScheduled');
                            if ($this->firmwareBlocksNewSessions($chargerId)) {
                                $this->reportFirmwareUnavailable($chargerId);
                            }
                        }
                    }

                    return;
                }

                if ($action === 'GetLog' && in_array($response['status'] ?? null, ['Accepted', 'AcceptedCanceled'], true)) {
                    $this->logRetrievals->acceptedResponse($chargerId, (int) ($_payload['requestId'] ?? -1));
                    $this->sendPendingLogStatusNotifications($chargerId);

                    return;
                }

                if ($action === 'CustomerInformation' && ($response['status'] ?? null) === 'Accepted') {
                    $notifications = $customerInformation->takeNotifications();
                    if ($notifications === []) {
                        $customerInformation->responseSent();
                    }
                    foreach ($notifications as $notification) {
                        $this->createOcppClient($chargerId)->request('NotifyCustomerInformation', $notification);
                    }
                    if ($notifications !== []) {
                        $customerInformation->notificationsSent();
                    }

                    return;
                }

                if ($action === 'TriggerMessage') {
                    if (($response['status'] ?? null) === 'Accepted') {
                        if ($triggerMessage->takeHeartbeatRequest()) {
                            $this->sendHeartbeat($chargerId);
                        }

                        if ($triggerMessage->takeFirmwareStatusNotificationRequest()) {
                            $update = $this->firmwareUpdates?->current($chargerId);
                            if (is_array($update)
                                && is_numeric($update['request_id'] ?? null)
                                && in_array($update['state'] ?? null, [
                                    'DownloadScheduled', 'Downloading', 'Downloaded', 'InstallScheduled', 'Installing', 'InstallRebooting',
                                ], true)) {
                                $this->sendFirmwareStatusNotification($chargerId, (int) $update['request_id'], $update['state']);
                            } else {
                                $this->sendFirmwareStatusNotification($chargerId, null, 'Idle');
                            }
                        }

                        foreach ($triggerMessage->takeStatusNotifications() as $statusNotification) {
                            $this->createOcppClient($chargerId)->request('StatusNotification', $statusNotification);
                        }

                        foreach ($triggerMessage->takeTransactionSessions() as $session) {
                            $this->sendTriggeredTransactionEvent($chargerId, $session);
                        }

                        foreach ($triggerMessage->takeMeterValueRequests() as $meterValueRequest) {
                            $measurands = MeterMeasurands::parse(
                                $this->deviceModelSettings->get($chargerId, 'Measurands') ?? '',
                            ) ?? [];
                            $this->createOcppClient($chargerId)->request('MeterValues', [
                                'evseId' => $meterValueRequest['evse_id'],
                                'meterValue' => $this->meterValueReporter->value(
                                    $meterValueRequest['meter_wh'],
                                    'Trigger',
                                    measurands: $measurands,
                                    powerW: $meterValueRequest['power_w'],
                                ),
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

    private function activeNetworkProfileSlot(int $chargerId, array $profile): int
    {
        $slot = $profile['slot'] ?? null;
        if (is_int($slot) && $slot >= 0) {
            return $slot;
        }
        if (is_string($slot) && ctype_digit($slot)) {
            return (int) $slot;
        }

        return $this->deviceModelSettingInt($chargerId, 'ActiveNetworkProfile', 0, 0);
    }

    private function runtimeDeviceModelVariableValue(int $chargerId, array $component, array $variable): ?string
    {
        $componentName = $component['name'] ?? null;
        $variableName = $variable['name'] ?? null;
        if (!is_string($componentName) || !is_string($variableName)) {
            return null;
        }

        if ($componentName === 'SecurityCtrlr') {
            if ($variableName === 'CertificateEntries') {
                return (string) ($this->certificateManagement?->certificateCount($chargerId) ?? 0);
            }
            if ($variableName === 'SecurityProfile') {
                $session = $this->sessions[$chargerId] ?? null;
                $profile = is_array($session)
                    ? ($session['network_profiles'][$session['network_profile_index']]['connectionData']['securityProfile'] ?? null)
                    : null;
                if (is_int($profile)) {
                    return (string) $profile;
                }

                $charger = $this->chargers->get($chargerId);

                return str_starts_with((string) ($charger['csms_url'] ?? ''), 'wss://') ? '2' : '1';
            }
        }

        if ($componentName === 'OCPPCommCtrlr' && $variableName === 'MessageTimeout'
            && ($variable['instance'] ?? null) === 'Default') {
            return (string) ($this->sessions[$chargerId]['connection_timeout'] ?? 8);
        }

        return null;
    }

    private function canApplyNetworkConfigurationPriority(int $chargerId, string $priority): bool
    {
        $currentSecurityProfile = (int) ($this->runtimeDeviceModelVariableValue(
            $chargerId,
            ['name' => 'SecurityCtrlr'],
            ['name' => 'SecurityProfile'],
        ) ?? '1');
        $charger = $this->chargers->get($chargerId);
        if ($charger === null) {
            return false;
        }

        foreach (explode(',', $priority) as $slotValue) {
            $slot = (int) $slotValue;
            $profile = $this->networkProfiles->get($chargerId, $slot);
            $securityProfile = is_int($profile['securityProfile'] ?? null)
                ? $profile['securityProfile']
                : ($slot === 0 && str_starts_with((string) ($charger['csms_url'] ?? ''), 'wss://') ? 2 : 1);
            if ($securityProfile < $currentSecurityProfile || $securityProfile > 2) {
                return false;
            }
            if ($securityProfile > $currentSecurityProfile
                && $this->tlsTrustBundle(
                    $chargerId,
                    $this->workspaceSettings->getTlsSettings()['tls_ca_certificate'],
                ) === null) {
                return false;
            }
        }

        return true;
    }

    private function tlsTrustBundle(int $chargerId, string $workspaceCaCertificate): ?string
    {
        if ($this->certificateManagement !== null) {
            return $this->certificateManagement->csmsTlsTrustBundle($chargerId, $workspaceCaCertificate);
        }

        $workspaceCaCertificate = trim($workspaceCaCertificate);

        return $workspaceCaCertificate === '' ? null : $workspaceCaCertificate;
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
            if ($session['connector_status'] === 'Available' && $session['transaction_id'] === null) {
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
            static fn (array $session): bool => $session['transaction_id'] !== null,
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

    private function resetBootPayload(array $charger, string $reason = 'RemoteReset'): array
    {
        return [
            'chargingStation' => array_filter([
                'model' => $charger['model'],
                'vendorName' => $charger['vendor_name'],
                'serialNumber' => $charger['serial_number'] !== '' ? $charger['serial_number'] : null,
                'firmwareVersion' => $this->firmwareVersion((int) $charger['id']),
            ], static fn (mixed $value): bool => $value !== null),
            'reason' => $reason,
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

        if ($session !== null && $session['authorization_pending']) {
            return $this->connectAuthorizationFirstVehicle($chargerId, $session);
        }

        if ($this->firmwareBlocksNewSessions($chargerId)) {
            throw new RuntimeException('The charger is waiting to install firmware and cannot start a new transaction.');
        }

        if ($this->resets->blocksNewTransactions($chargerId)) {
            throw new RuntimeException('The charger is waiting to reset and cannot start a new transaction.');
        }

        if ($this->firmwareBlocksNewSessions($chargerId)
            || $session === null || $session['connector_status'] !== 'Available'
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
        if (($this->sessions[$chargerId]['connection'] ?? null) instanceof WebSocketConnection) {
            $this->transactionEventDelivery->deliverThrough($chargerId, $queuedEvent['id']);
        }

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

    private function connectAuthorizationFirstVehicle(int $chargerId, array $session): array
    {
        $transactionId = $session['transaction_id'];
        if (!is_string($transactionId) || $transactionId === '') {
            throw new RuntimeException('The authorization-first transaction is missing its transaction ID.');
        }

        $evseId = $session['evse_id'];
        $connectorId = $session['connector_id'];
        $connectedSequence = $session['seq_no'] + 1;
        $this->outletSessions->markAuthorizationFirstConnected(
            $chargerId,
            $evseId,
            $connectorId,
            $transactionId,
            $connectedSequence,
        );
        $this->sendStatusNotification($chargerId, $evseId, $connectorId, 'Occupied');

        $chargingEvent = [
            'eventType' => 'Updated',
            'timestamp' => gmdate(DATE_ATOM),
            'triggerReason' => 'ChargingStateChanged',
            'seqNo' => $connectedSequence,
            'transactionInfo' => [
                'transactionId' => $transactionId,
                'chargingState' => 'Charging',
            ],
            'evse' => ['id' => $evseId, 'connectorId' => $connectorId],
        ];
        $queuedChargingEvent = $this->transactionEventDelivery->enqueue($chargerId, $chargingEvent);
        $this->transactionEventDelivery->deliverThrough($chargerId, $queuedChargingEvent['id']);

        return ['status' => 'PluggedIn', 'transactionId' => $transactionId];
    }

    private function expireRemoteStart(int $chargerId): bool
    {
        $dueSessions = $this->outletSessions->dueRemoteStarts($chargerId, gmdate(DATE_ATOM));
        if ($dueSessions === []) {
            return false;
        }

        $this->endOutletTransaction($chargerId, $dueSessions[0], 'EVConnectTimeout', 'Timeout');

        return true;
    }

    private function expireAuthorizationFirstStart(int $chargerId): bool
    {
        $dueSessions = $this->outletSessions->dueAuthorizationFirstStarts($chargerId, gmdate(DATE_ATOM));
        if ($dueSessions === []) {
            return false;
        }

        $this->endOutletTransaction(
            $chargerId,
            $dueSessions[0],
            'EVConnectTimeout',
            'Timeout',
            reportCurrentStatus: false,
        );

        return true;
    }

    private function remoteStop(int $chargerId, array $payload): array
    {
        $evseId = $this->requiredOutletId($payload, 'evseId');
        $connectorId = $this->requiredOutletId($payload, 'connectorId');
        $transactionId = $payload['transactionId'] ?? null;
        $session = $this->outletSessions->find($chargerId, $evseId, $connectorId);
        if (!is_string($transactionId) || $session === null || $session['transaction_id'] !== $transactionId) {
            throw new RuntimeException('The accepted remote-stop request no longer matches an active transaction.');
        }

        if ($session['authorization_pending']) {
            $this->endOutletTransaction($chargerId, $session, 'RemoteStop', 'Remote', reportCurrentStatus: false);

            return ['status' => 'Stopped'];
        }

        if ($session['connector_status'] !== 'Occupied') {
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
        if ($session !== null && $session['connector_status'] === 'Available'
            && $session['transaction_id'] === null) {
            $this->startAuthorizationFirstTransaction($chargerId, $outlet, $session, $idToken);

            return;
        }

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

    private function startAuthorizationFirstTransaction(int $chargerId, array $outlet, array $session, array $idToken): void
    {
        if ($this->firmwareBlocksNewSessions($chargerId)
            || !$this->availability->canStartTransaction($chargerId, $outlet['evse_id'], $outlet['connector_id'])
            || $this->resets->blocksNewTransactions($chargerId)) {
            throw new RuntimeException('The selected outlet is no longer available for an authorization-first transaction.');
        }

        $transactionId = $this->chargers->nextTransactionId($chargerId);
        $timeout = $this->deviceModelSettingInt($chargerId, 'EVConnectionTimeOut', 30, 1);
        $deadline = gmdate(DATE_ATOM, time() + $timeout);
        $this->outletSessions->beginAuthorizationFirst(
            $chargerId,
            $outlet['evse_id'],
            $outlet['connector_id'],
            $transactionId,
            $idToken['idToken'],
            $deadline,
        );

        $event = [
            'eventType' => 'Started',
            'timestamp' => gmdate(DATE_ATOM),
            'triggerReason' => 'Authorized',
            'seqNo' => 0,
            'transactionInfo' => ['transactionId' => $transactionId],
            'evse' => ['id' => $outlet['evse_id'], 'connectorId' => $outlet['connector_id']],
            'idToken' => $idToken,
        ];
        $meterValue = $this->meterValueReporter->beginTransactionValue($chargerId, $session['meter_wh']);
        if ($meterValue !== null) {
            $event['meterValue'] = $meterValue;
        }

        $queuedEvent = $this->transactionEventDelivery->enqueue($chargerId, $event);
        $result = $this->transactionEventDelivery->deliverThrough($chargerId, $queuedEvent['id']);
        if (($result['idTokenInfo']['status'] ?? null) !== 'Accepted') {
            $this->outletSessions->markAvailable(
                $chargerId,
                $outlet['evse_id'],
                $outlet['connector_id'],
                $transactionId,
                $session['meter_wh'],
            );
            throw new OcppProtocolException('The CSMS did not accept the authorization-first idToken in the TransactionEvent response.');
        }
    }

    private function reportReleasedOutlet(int $chargerId, int $evseId, int $connectorId, bool $reportCurrentStatus = true): void
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

        if (!$reportedCurrentOutlet && $reportCurrentStatus) {
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
        bool $reportCurrentStatus = true,
    ): void {
        $timestamp = gmdate(DATE_ATOM);
        $transactionInfo = [
            'transactionId' => $session['transaction_id'],
            'stoppedReason' => $stoppedReason,
        ];
        if ($chargingState !== null) {
            $transactionInfo['chargingState'] = $chargingState;
        }

        $latestSession = $this->outletSessions->find(
            $chargerId,
            $session['evse_id'],
            $session['connector_id'],
        );
        $meterValues = [
            ...($latestSession['meter_end_samples'] ?? $session['meter_end_samples'] ?? []),
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
        if (($this->sessions[$chargerId]['connection'] ?? null) instanceof WebSocketConnection) {
            $this->transactionEventDelivery->deliverThrough($chargerId, $queuedEvent['id']);
        }
        $this->reportReleasedOutlet($chargerId, $session['evse_id'], $session['connector_id'], $reportCurrentStatus);
    }

    private function sendStatusNotification(int $chargerId, int $evseId, int $connectorId, string $status): void
    {
        if ($status === 'Available' && $this->firmwareBlocksNewSessions($chargerId)) {
            $status = 'Unavailable';
            $this->firmwareUnavailableReported[$chargerId] = true;
        }

        $connection = $this->sessions[$chargerId]['connection'] ?? null;
        if (!$connection instanceof WebSocketConnection) {
            $this->offlineStatuses->markConnectorChanged($chargerId, $evseId, $connectorId);

            return;
        }

        try {
            $this->createOcppClient($chargerId)->request('StatusNotification', [
                'timestamp' => gmdate(DATE_ATOM),
                'connectorStatus' => $status,
                'evseId' => $evseId,
                'connectorId' => $connectorId,
            ]);
        } catch (Throwable $exception) {
            if (!$this->isOcppCallError($exception)) {
                $this->offlineStatuses->begin($chargerId);
                $this->offlineStatuses->markConnectorChanged($chargerId, $evseId, $connectorId);
            }

            throw $exception;
        }
    }

    private function reportOfflineStatusChanges(int $chargerId): bool
    {
        $pending = $this->offlineStatuses->pending($chargerId);
        $charger = $this->chargers->get($chargerId);
        if ($pending === null
            || !isset($this->sessions[$chargerId])
            || !$this->sessions[$chargerId]['connection'] instanceof WebSocketConnection
            || ($charger['boot_status'] ?? null) !== 'Accepted') {
            return false;
        }

        $threshold = $this->deviceModelSettingInt($chargerId, 'OfflineThreshold', 0, 0);
        $reportAll = microtime(true) - $pending['offline_since'] > $threshold;
        if ($reportAll) {
            $outlets = $this->outletSessions->allForCharger($chargerId);
        } else {
            $outlets = [];
            foreach ($pending['changed_connectors'] as $connector) {
                $outlet = $this->outletSessions->find($chargerId, $connector['evse_id'], $connector['connector_id']);
                if ($outlet !== null) {
                    $outlets[] = $outlet;
                }
            }
        }

        foreach ($outlets as $outlet) {
            $status = $this->availability->connectorStatus(
                $chargerId,
                $outlet['evse_id'],
                $outlet['connector_id'],
                $outlet['connector_status'],
            );
            $this->sendStatusNotification($chargerId, $outlet['evse_id'], $outlet['connector_id'], $status);
        }

        $this->offlineStatuses->clear($chargerId);

        return true;
    }

    private function processLogRetrieval(int $chargerId): bool
    {
        if ($this->sendPendingLogStatusNotifications($chargerId)) {
            return true;
        }

        return $this->logRetrievals->uploadWhenDue($chargerId);
    }

    private function sendPendingLogStatusNotifications(int $chargerId): bool
    {
        $sent = false;
        while (($notification = $this->logRetrievals->takeNextNotification($chargerId)) !== null) {
            $this->createOcppClient($chargerId)->request('LogStatusNotification', $notification);
            $sent = true;
        }

        return $sent;
    }

    private function processFirmwareUpdate(int $chargerId): bool
    {
        if ($this->firmwareUpdates === null) {
            return false;
        }

        $update = $this->firmwareUpdates->current($chargerId);
        $requestId = $update['request_id'];
        $state = $update['state'];
        if (!is_int($requestId) && !is_numeric($requestId)) {
            return false;
        }
        $requestId = (int) $requestId;

        if (isset($this->firmwareUnavailableReported[$chargerId])
            && !$this->firmwareBlocksNewSessions($chargerId)) {
            unset($this->firmwareUnavailableReported[$chargerId]);
            $charger = $this->chargers->get($chargerId);
            if ($charger !== null) {
                $this->reportAvailableOutlets($chargerId, $charger);
            }

            return true;
        }

        if ($state === 'DownloadScheduled' && $this->firmwareUpdates->isDue($update)) {
            if ($this->activeFirmwareBlockingTransactions($chargerId) !== []) {
                if ($this->firmwareBlocksNewSessions($chargerId)) {
                    $this->reportFirmwareUnavailable($chargerId);
                }

                return false;
            }
            if ($this->firmwareBlocksNewSessions($chargerId)) {
                $this->reportFirmwareUnavailable($chargerId);
            }
            if ((int) $update['download_attempts'] > 0) {
                $this->sendFirmwareStatusNotification($chargerId, $requestId, 'DownloadScheduled');
            }
            $this->firmwareUpdates->beginDownload($chargerId);
            $this->sendFirmwareStatusNotification($chargerId, $requestId, 'Downloading');
            try {
                $tlsSettings = $this->workspaceSettings->getTlsSettings();
                $package = $this->firmwareUpdates->downloadAndStore(
                    $chargerId,
                    $tlsSettings['tls_ca_certificate'] !== '' ? $tlsSettings['tls_ca_certificate'] : null,
                );
            } catch (Throwable $exception) {
                $this->firmwareUpdates->fail($chargerId, 'DownloadFailed', $exception->getMessage());
                $this->sendFirmwareStatusNotification($chargerId, $requestId, 'DownloadFailed');
                $this->logs->record($chargerId, 'system', 'UpdateFirmware', $exception->getMessage());

                return true;
            }

            $this->firmwareUpdates->recordDownloadedPackage($chargerId, $package);
            $this->sendFirmwareStatusNotification($chargerId, $requestId, 'Downloaded');
            try {
                $this->firmwareUpdates->verifyDownloadedPackage($chargerId);
            } catch (FirmwareSignatureException $exception) {
                $this->firmwareUpdates->fail($chargerId, 'InvalidSignature', $exception->getMessage());
                $this->sendSecurityEventNotification($chargerId, $exception->securityEventType);
                $this->sendFirmwareStatusNotification($chargerId, $requestId, 'InvalidSignature');
                $this->logs->record($chargerId, 'system', 'UpdateFirmware', $exception->getMessage());

                return true;
            } catch (Throwable $exception) {
                $this->firmwareUpdates->fail($chargerId, 'InvalidSignature', $exception->getMessage());
                $this->sendFirmwareStatusNotification($chargerId, $requestId, 'InvalidSignature');
                $this->logs->record($chargerId, 'system', 'UpdateFirmware', $exception->getMessage());

                return true;
            }

            $this->sendFirmwareStatusNotification($chargerId, $requestId, 'SignatureVerified');
            $installAt = $update['install_at'] === null ? null : (int) $update['install_at'];
            if ($installAt !== null && $installAt > time()) {
                $this->firmwareUpdates->scheduleInstall($chargerId, $installAt);
                $this->sendFirmwareStatusNotification($chargerId, $requestId, 'InstallScheduled');
            }

            return true;
        }

        if (in_array($state, ['Downloaded', 'InstallScheduled'], true)
            && $this->firmwareUpdates->isDue($update)) {
            $activeSessions = array_filter(
                $this->outletSessions->allForCharger($chargerId),
                static fn (array $session): bool => $session['transaction_id'] !== null,
            );
            if ($activeSessions !== []) {
                if ($this->firmwareBlocksNewSessions($chargerId)) {
                    $this->reportFirmwareUnavailable($chargerId);
                }
                if ($state === 'Downloaded') {
                    $this->firmwareUpdates->scheduleInstall($chargerId, time() + 30);
                    $this->sendFirmwareStatusNotification($chargerId, $requestId, 'InstallScheduled');
                } else {
                    $this->firmwareUpdates->scheduleInstall($chargerId, time() + 30);
                }

                return true;
            }

            $this->firmwareUpdates->beginInstall($chargerId, time() + 1);
            $this->sendFirmwareStatusNotification($chargerId, $requestId, 'Installing');

            return true;
        }

        if ($state === 'Installing' && $this->firmwareUpdates->isDue($update)) {
            try {
                $this->firmwareUpdates->prepareReboot($chargerId);
            } catch (FirmwareInstallationVerificationException $exception) {
                $this->firmwareUpdates->fail($chargerId, 'InstallVerificationFailed', $exception->getMessage());
                $this->sendFirmwareStatusNotification($chargerId, $requestId, 'InstallVerificationFailed');
                $this->logs->record($chargerId, 'system', 'UpdateFirmware', $exception->getMessage());

                return true;
            } catch (Throwable $exception) {
                $this->firmwareUpdates->fail($chargerId, 'InstallationFailed', $exception->getMessage());
                $this->sendFirmwareStatusNotification($chargerId, $requestId, 'InstallationFailed');
                $this->logs->record($chargerId, 'system', 'UpdateFirmware', $exception->getMessage());

                return true;
            }

            $this->sendFirmwareStatusNotification($chargerId, $requestId, 'InstallRebooting');
            $this->simulateFirmwareReboot($chargerId);

            return true;
        }

        return false;
    }

    private function firmwareBlocksNewSessions(int $chargerId): bool
    {
        if (($this->deviceModelSettings->get($chargerId, 'AllowNewSessionsPendingFirmwareUpdate') ?? 'false') === 'true'
            || $this->firmwareUpdates === null) {
            return false;
        }

        $update = $this->firmwareUpdates->current($chargerId);
        $state = $update['state'] ?? null;
        if (in_array($state, ['Downloading', 'Downloaded', 'Installing', 'InstallRebooting'], true)) {
            return true;
        }

        if ($state === 'InstallScheduled' && is_string($update['package_sha256'] ?? null)) {
            return true;
        }

        return $state === 'DownloadScheduled' && $this->firmwareUpdates->isDue($update);
    }

    /** @return list<array<string, mixed>> */
    private function activeFirmwareBlockingTransactions(int $chargerId): array
    {
        return array_values(array_filter(
            $this->outletSessions->allForCharger($chargerId),
            static fn (array $session): bool => $session['transaction_id'] !== null,
        ));
    }

    private function reportFirmwareUnavailable(int $chargerId): void
    {
        if (isset($this->firmwareUnavailableReported[$chargerId])) {
            return;
        }

        foreach ($this->outletSessions->allForCharger($chargerId) as $session) {
            if ($session['transaction_id'] !== null || $session['connector_status'] !== 'Available') {
                continue;
            }

            $status = $this->availability->connectorStatus(
                $chargerId,
                $session['evse_id'],
                $session['connector_id'],
                $session['connector_status'],
            );
            if ($status === 'Available') {
                $this->sendStatusNotification($chargerId, $session['evse_id'], $session['connector_id'], 'Unavailable');
            }
        }

        $this->firmwareUnavailableReported[$chargerId] = true;
    }

    private function simulateFirmwareReboot(int $chargerId): void
    {
        $charger = $this->chargers->get($chargerId);
        if ($charger === null || !isset($this->sessions[$chargerId])) {
            return;
        }

        $this->sessions[$chargerId]['connection'] = null;
        $this->sessions[$chargerId]['boot_payload'] = $this->resetBootPayload($charger, 'FirmwareUpdate');
        $this->sessions[$chargerId]['next_boot_at'] = microtime(true) + 0.2;
        $this->sessions[$chargerId]['next_heartbeat_at'] = null;
        $this->sessions[$chargerId]['reconnect_attempt'] = 1;
        $this->sessions[$chargerId]['next_reconnect_at'] = microtime(true) + 0.2;
        $this->sessions[$chargerId]['network_profile_attempts'] = 0;
        $this->chargers->setBootStatus($chargerId, 'Not connected');
        $this->chargers->setConnectionStatus($chargerId, 'Not connected');
    }

    private function sendFirmwareStatusNotification(int $chargerId, ?int $requestId, string $status): void
    {
        $payload = ['status' => $status];
        if ($requestId !== null) {
            $payload['requestId'] = $requestId;
        }
        $this->createOcppClient($chargerId)->request('FirmwareStatusNotification', $payload);
    }

    private function firmwareVersion(int $chargerId): string
    {
        return $this->firmwareUpdates?->firmwareVersion($chargerId) ?? '1.0.0';
    }

    private function sendHeartbeat(int $chargerId): void
    {
        $result = $this->createOcppClient($chargerId)->request('Heartbeat', new \stdClass());
        if (!isset($result['currentTime']) || !is_string($result['currentTime'])) {
            throw new OcppProtocolException('The CSMS Heartbeat response did not include currentTime.');
        }
    }

    private function sendSecurityEventNotification(int $chargerId, string $type): void
    {
        $this->createOcppClient($chargerId)->request('SecurityEventNotification', [
            'type' => $type,
            'timestamp' => gmdate('Y-m-d\\TH:i:s\\Z'),
        ]);
    }

    private function sendTriggeredTransactionEvent(int $chargerId, array $session): void
    {
        $transactionId = $session['transaction_id'];
        if (!is_string($transactionId) || $transactionId === '') {
            throw new RuntimeException('The active transaction is missing its transaction ID.');
        }

        $timestamp = gmdate(DATE_ATOM);
        $transactionInfo = ['transactionId' => $transactionId];
        if (!$session['remote_start_pending'] && !$session['authorization_pending']) {
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
        $measurands = MeterMeasurands::parse(
            $this->deviceModelSettings->get($chargerId, 'TxUpdatedMeasurands') ?? '',
        ) ?? [];
        if ($measurands !== []) {
            $event['meterValue'] = $this->meterValueReporter->value(
                $session['meter_wh'],
                'Trigger',
                $timestamp,
                $measurands,
                SimulatedMeter::powerForOutlet($session),
            );
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
