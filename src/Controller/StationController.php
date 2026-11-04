<?php

declare(strict_types=1);

namespace ChargerEmulator\Controller;

use ChargerEmulator\Domain\OutletMap;
use ChargerEmulator\Http\Request;
use ChargerEmulator\Http\Response;
use ChargerEmulator\Protocol\OcppConnectionUrl;
use ChargerEmulator\Protocol\WebSocketConnection;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\ExchangeRepository;
use ChargerEmulator\Storage\OcppCommandRepository;
use ChargerEmulator\Storage\OutletSessionRepository;
use ChargerEmulator\Storage\WorkspaceSettingsRepository;
use InvalidArgumentException;
use Throwable;

/**
 * Coordinates station configuration, OCPP requests, and the local exchange log.
 */
final class StationController
{
    public function __construct(
        private readonly ChargerRepository $chargers,
        private readonly ExchangeRepository $logs,
        private readonly WorkspaceSettingsRepository $settings,
        private readonly OcppCommandRepository $commands,
        private readonly OutletSessionRepository $outletSessions,
    ) {
    }

    public function state(Request $request): Response
    {
        $defaultCsmsUrl = $this->settings->getCsmsUrl();
        $tlsSettings = $this->settings->getTlsSettings();
        $chargers = [];
        foreach ($this->chargers->all() as $charger) {
            $charger['uses_default_csms'] = (bool) $charger['uses_default_csms'];
            if ($charger['uses_default_csms']) {
                $charger['csms_url'] = $defaultCsmsUrl;
            }

            $charger = $this->publicCharger($charger);

            $outletStates = [];
            foreach ($this->outletSessions->allForCharger((int) $charger['id']) as $outletState) {
                $outletStates[$outletState['evse_id'] . ':' . $outletState['connector_id']] = $outletState;
            }

            $charger['outlets'] = array_map(static function (array $outlet) use ($outletStates): array {
                $state = $outletStates[$outlet['evse_id'] . ':' . $outlet['connector_id']] ?? null;
                $outlet['connector_status'] = $state['connector_status'] ?? 'Available';
                $outlet['transaction_id'] = $state['transaction_id'] ?? null;
                $outlet['remote_start_pending'] = $state['remote_start_pending'] ?? false;
                // Accepted tokens start simulated energy transfer unless the vehicle is still pending or a remote stop was requested.
                $outlet['is_charging'] = $state !== null
                    && $state['connector_status'] === 'Occupied'
                    && $state['id_token'] !== null
                    && $state['stop_reason'] === null
                    && !$state['remote_start_pending'];

                return $outlet;
            }, (new OutletMap((int) $charger['outlet_count'], $charger['outlet_topology']))->outlets());
            $charger['entries'] = $this->logs->recent((int) $charger['id']);
            $chargers[] = $charger;
        }

        return Response::json(200, [
            'settings' => [
                'default_csms_url' => $defaultCsmsUrl,
                'tls_server_name' => $tlsSettings['tls_server_name'],
                'has_tls_ca_certificate' => $tlsSettings['tls_ca_certificate'] !== '',
            ],
            'chargers' => $chargers,
        ]);
    }

    public function saveWorkspaceSettings(Request $request): Response
    {
        try {
            $url = $this->requiredString($request->body(), 'default_csms_url');
            $this->validateCsmsUrl($url);
        } catch (InvalidArgumentException $exception) {
            return Response::json(422, ['error' => $exception->getMessage()]);
        }

        $this->settings->saveCsmsUrl($url);

        return Response::json(200, ['notice' => 'Workspace CSMS URL saved.', 'default_csms_url' => $url]);
    }

    public function saveTlsSettings(Request $request): Response
    {
        try {
            $input = $request->body();
            $serverName = $input['tls_server_name'] ?? '';
            if (!is_string($serverName)) {
                throw new InvalidArgumentException('TLS server name must be a string.');
            }
            $serverName = trim($serverName);
            $this->validateTlsServerName($serverName);

            $current = $this->settings->getTlsSettings();
            $clearCertificate = $this->booleanValue($input, 'clear_tls_ca_certificate', false);
            $certificate = $clearCertificate ? '' : ($input['tls_ca_certificate'] ?? '');
            if (!is_string($certificate)) {
                throw new InvalidArgumentException('TLS CA certificate must be a PEM string.');
            }
            if ($certificate === '' && !$clearCertificate) {
                $certificate = $current['tls_ca_certificate'];
            }
            if (strlen($certificate) > 65_536) {
                throw new InvalidArgumentException('TLS CA certificate must be 64 KiB or smaller.');
            }
            if ($certificate !== '' && (!str_contains($certificate, '-----BEGIN CERTIFICATE-----')
                || @openssl_x509_read($certificate) === false)) {
                throw new InvalidArgumentException('TLS CA certificate must contain a valid PEM X.509 certificate.');
            }
        } catch (InvalidArgumentException $exception) {
            return Response::json(422, ['error' => $exception->getMessage()]);
        }

        $this->settings->saveTlsSettings($serverName, $certificate);

        return Response::json(200, [
            'notice' => 'TLS trust settings saved.',
            'tls_server_name' => $serverName,
            'has_tls_ca_certificate' => $certificate !== '',
        ]);
    }

    public function testConnection(Request $request): Response
    {
        try {
            $chargerId = $this->chargerId($request->body());
        } catch (InvalidArgumentException $exception) {
            return Response::json(422, ['error' => $exception->getMessage()]);
        }

        $charger = $this->chargers->get($chargerId);
        if ($charger === null) {
            return Response::json(404, ['error' => 'The requested charger does not exist.']);
        }
        if ($charger['connection_status'] === 'Connected') {
            return Response::json(409, [
                'error' => 'The charger is already connected to a CSMS. Use the test bench, or reconnect after changing its setup.',
            ]);
        }

        $endpoint = (bool) $charger['uses_default_csms']
            ? $this->settings->getCsmsUrl()
            : $charger['csms_url'];
        $tlsSettings = $this->settings->getTlsSettings();

        try {
            $connectionUrl = OcppConnectionUrl::fromEndpoint($endpoint, $charger['station_identity']);
            $connection = new WebSocketConnection(
                $connectionUrl,
                timeoutSeconds: 5,
                username: $charger['station_identity'],
                password: $charger['csms_password'],
                tlsServerName: $tlsSettings['tls_server_name'] !== '' ? $tlsSettings['tls_server_name'] : null,
                tlsCaCertificatePem: $tlsSettings['tls_ca_certificate'] !== '' ? $tlsSettings['tls_ca_certificate'] : null,
            );
            unset($connection);
        } catch (Throwable $exception) {
            return Response::json(502, ['error' => 'Connection test failed: ' . $exception->getMessage()]);
        }

        return Response::json(200, [
            'notice' => 'WebSocket connection succeeded and negotiated ocpp2.0.1. No OCPP request was sent.',
        ]);
    }

    public function create(Request $request): Response
    {
        $id = $this->chargers->createDefault();

        return Response::json(201, ['notice' => 'Charger added.', 'charger_id' => $id]);
    }

    public function save(Request $request): Response
    {
        try {
            $input = $request->body();
            $id = $this->chargerId($input);
            $charger = $this->validatedCharger($id, $input);
        } catch (InvalidArgumentException $exception) {
            return Response::json(422, ['error' => $exception->getMessage()]);
        }

        $current = $this->chargers->get($id);
        if ($current !== null
            && $this->outletSessions->hasActiveTransactions($id)
            && ($current['station_identity'] !== $charger['station_identity']
                || $current['csms_url'] !== $charger['csms_url']
                || (int) $current['outlet_count'] !== $charger['outlet_count']
                || $current['outlet_topology'] !== $charger['outlet_topology'])) {
            return Response::json(409, ['error' => 'End outlet transactions before changing the charger identity, CSMS URL, or outlet map.']);
        }

        $this->chargers->save($id, $charger);

        return Response::json(200, [
            'notice' => 'Charger configuration saved.',
            'charger' => $this->publicCharger($this->chargers->get($id) ?? []),
        ]);
    }

    public function boot(Request $request): Response
    {
        return $this->executeOcppAction($request, 'BootNotification');
    }

    public function authorize(Request $request): Response
    {
        return $this->executeOcppAction($request, 'Authorize');
    }

    public function plugIn(Request $request): Response
    {
        return $this->executeOutletAction($request, 'PlugIn');
    }

    public function unplug(Request $request): Response
    {
        return $this->executeOutletAction($request, 'Unplug');
    }

    public function clearLog(Request $request): Response
    {
        try {
            $this->logs->clear($this->chargerId($request->body()));
        } catch (InvalidArgumentException $exception) {
            return Response::json(422, ['error' => $exception->getMessage()]);
        }

        return Response::json(200, ['notice' => 'Exchange log cleared.']);
    }

    public function delete(Request $request): Response
    {
        try {
            $id = $this->chargerId($request->body());
        } catch (InvalidArgumentException $exception) {
            return Response::json(422, ['error' => $exception->getMessage()]);
        }

        if (count($this->chargers->all()) < 2) {
            return Response::json(409, ['error' => 'Keep at least one charger in the workspace.']);
        }
        if ($this->outletSessions->hasActiveTransactions($id)) {
            return Response::json(409, ['error' => 'Unplug all vehicles before removing this charger.']);
        }

        $disconnectId = $this->commands->enqueue($id, 'Disconnect', []);
        $disconnect = $this->commands->waitForCompletion($disconnectId);
        if ($disconnect === null || in_array($disconnect['status'], ['pending', 'processing'], true)) {
            return Response::json(504, ['error' => 'The station session worker did not respond in time.']);
        }
        if ($disconnect['status'] === 'failed') {
            return Response::json(502, ['error' => $disconnect['error'] ?? 'The charger could not be disconnected.']);
        }
        if ($this->outletSessions->hasActiveTransactions($id)) {
            return Response::json(409, ['error' => 'Unplug all vehicles before removing this charger.']);
        }

        $this->chargers->delete($id);

        return Response::json(200, ['notice' => 'Charger removed.']);
    }

    private function executeOcppAction(Request $request, string $action): Response
    {
        $outlet = null;
        try {
            $input = $request->body();
            $id = $this->chargerId($input);
            $charger = $this->validatedCharger($id, $input);
            $current = $this->chargers->get($id);
            if ($action === 'BootNotification'
                && in_array($current['boot_status'] ?? null, ['Pending', 'Rejected'], true)
                && is_string($current['boot_retry_at'] ?? null)
                && ($retryTimestamp = strtotime($current['boot_retry_at'])) !== false
                && $retryTimestamp > time()) {
                return Response::json(409, ['error' => 'Wait for the CSMS BootNotification retry interval before retrying.']);
            }
            if ($action === 'Authorize' && ($current['boot_status'] ?? null) !== 'Accepted') {
                return Response::json(409, ['error' => 'Accept BootNotification before authorizing a vehicle.']);
            }
            $token = $action === 'Authorize' ? $this->requiredString($request->body(), 'rfid_token') : null;
            if ($action === 'Authorize' && array_key_exists('outlet_number', $input)) {
                $outletNumber = $this->integerValue($input, 'outlet_number', 0);
                foreach ((new OutletMap($charger['outlet_count'], $charger['outlet_topology']))->outlets() as $candidate) {
                    if ($candidate['number'] === $outletNumber) {
                        $outlet = ['evse_id' => $candidate['evse_id'], 'connector_id' => $candidate['connector_id']];
                        break;
                    }
                }

                if ($outlet === null) {
                    throw new InvalidArgumentException('Select a valid outlet for this RFID token.');
                }

                $outletState = $this->outletSessions->find($id, $outlet['evse_id'], $outlet['connector_id']);
                if ($outletState === null || $outletState['connector_status'] !== 'Occupied' || $outletState['transaction_id'] === null) {
                    return Response::json(409, ['error' => 'Plug in a vehicle on the selected outlet before presenting a token.']);
                }
            }
        } catch (InvalidArgumentException $exception) {
            return Response::json(422, ['error' => $exception->getMessage()]);
        }

        if ($action === 'Authorize' && trim((string) $token) === '') {
            return Response::json(422, ['error' => 'Enter an RFID token to present.']);
        }

        $this->chargers->save($id, $charger);
        $payload = $action === 'BootNotification'
            ? [
                'chargingStation' => array_filter([
                    'model' => $charger['model'],
                    'vendorName' => $charger['vendor_name'],
                    'serialNumber' => $charger['serial_number'] !== '' ? $charger['serial_number'] : null,
                ], static fn (mixed $value): bool => $value !== null),
                'reason' => 'PowerUp',
            ]
            : ['idToken' => ['idToken' => $token, 'type' => 'ISO14443']];
        if ($outlet !== null) {
            $payload['_outlet'] = $outlet;
        }

        $commandId = $this->commands->enqueue($id, $action, $payload);
        $command = $this->commands->waitForCompletion($commandId);
        if ($command === null || in_array($command['status'], ['pending', 'processing'], true)) {
            return Response::json(504, ['error' => 'The station session worker did not respond in time.']);
        }

        if ($command['status'] === 'failed') {
            return Response::json(502, ['error' => $command['error'] ?? 'The OCPP request failed.']);
        }

        $result = is_array($command['result']) ? $command['result'] : [];
        if ($action === 'BootNotification') {
            $notice = 'BootNotification received: ' . (string) ($result['status'] ?? 'unknown') . '.';
        } else {
            $notice = 'RFID token response: ' . (string) ($result['idTokenInfo']['status'] ?? 'Unknown response') . '.';
        }

        return Response::json(200, ['notice' => $notice, 'result' => $result]);
    }

    private function executeOutletAction(Request $request, string $action): Response
    {
        try {
            $input = $request->body();
            $id = $this->chargerId($input);
            $charger = $this->chargers->get($id);
            if ($charger === null) {
                throw new InvalidArgumentException('Select a valid charger.');
            }

            $outletNumber = $this->integerValue($input, 'outlet_number', 0);
            $outlet = null;
            foreach ((new OutletMap((int) $charger['outlet_count'], $charger['outlet_topology']))->outlets() as $candidate) {
                if ($candidate['number'] === $outletNumber) {
                    $outlet = $candidate;
                    break;
                }
            }

            if ($outlet === null) {
                throw new InvalidArgumentException('Select a valid outlet.');
            }
            if ($charger['connection_status'] !== 'Connected' || $charger['boot_status'] !== 'Accepted') {
                return Response::json(409, ['error' => 'Connect the charger with BootNotification before simulating a vehicle.']);
            }

            $outletState = $this->outletSessions->find($id, $outlet['evse_id'], $outlet['connector_id']);
            if ($action === 'PlugIn' && ($outletState === null
                || ($outletState['connector_status'] !== 'Available' && !$outletState['remote_start_pending']))) {
                return Response::json(409, ['error' => 'The selected outlet is not available for a vehicle.']);
            }
            if ($action === 'Unplug' && ($outletState === null
                || $outletState['connector_status'] !== 'Occupied'
                || $outletState['remote_start_pending'])) {
                return Response::json(409, ['error' => 'The selected outlet has no vehicle to unplug.']);
            }
        } catch (InvalidArgumentException $exception) {
            return Response::json(422, ['error' => $exception->getMessage()]);
        }

        $commandId = $this->commands->enqueue($id, $action, [
            'evseId' => $outlet['evse_id'],
            'connectorId' => $outlet['connector_id'],
        ]);
        $command = $this->commands->waitForCompletion($commandId);
        if ($command === null || in_array($command['status'], ['pending', 'processing'], true)) {
            return Response::json(504, ['error' => 'The station session worker did not respond in time.']);
        }
        if ($command['status'] === 'failed') {
            return Response::json(502, ['error' => $command['error'] ?? 'The outlet action failed.']);
        }

        $result = is_array($command['result']) ? $command['result'] : [];

        return Response::json(200, ['notice' => $action === 'PlugIn' ? 'Vehicle plugged in.' : 'Vehicle unplugged.', 'result' => $result]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{station_identity:string,csms_url:string,csms_password:string,vendor_name:string,model:string,serial_number:string,outlet_count:int,outlet_topology:string,uses_default_csms:bool}
     */
    private function validatedCharger(int $id, array $input): array
    {
        $current = $this->chargers->get($id);
        if ($current === null) {
            throw new InvalidArgumentException('The requested charger does not exist.');
        }

        $charger = [
            'station_identity' => $this->stringValue($input, 'station_identity', $current['station_identity']),
            'csms_url' => $this->stringValue($input, 'csms_url', $current['csms_url']),
            'csms_password' => $this->passwordValue($input, $current['csms_password']),
            'vendor_name' => $this->stringValue($input, 'vendor_name', $current['vendor_name']),
            'model' => $this->stringValue($input, 'model', $current['model']),
            'serial_number' => $this->stringValue($input, 'serial_number', $current['serial_number']),
            'outlet_count' => $this->integerValue($input, 'outlet_count', (int) $current['outlet_count']),
            'outlet_topology' => $this->stringValue($input, 'outlet_topology', $current['outlet_topology']),
            'uses_default_csms' => $this->booleanValue($input, 'uses_default_csms', (bool) $current['uses_default_csms']),
        ];

        $charger['csms_url'] = $charger['uses_default_csms']
            ? $this->settings->getCsmsUrl()
            : $this->stringValue($input, 'csms_url', $current['csms_url']);

        if ($charger['station_identity'] === '' || $charger['vendor_name'] === '' || $charger['model'] === '') {
            throw new InvalidArgumentException('Station identity, vendor, and model are required.');
        }

        if ($this->chargers->identityInUse($charger['station_identity'], $id)) {
            throw new InvalidArgumentException('Each charger needs a unique station identity.');
        }

        $this->validateCsmsUrl($charger['csms_url']);
        if (str_starts_with($charger['csms_url'], 'wss://')
            && $charger['csms_password'] !== ''
            && strlen($charger['csms_password']) < 16) {
            throw new InvalidArgumentException('Security Profile 2 CSMS passwords must be at least 16 characters.');
        }

        new OutletMap($charger['outlet_count'], $charger['outlet_topology']);

        return $charger;
    }

    /**
     * @param array<string, mixed> $charger
     * @return array<string, mixed>
     */
    private function publicCharger(array $charger): array
    {
        $charger['has_password'] = ($charger['csms_password'] ?? '') !== '';
        unset($charger['csms_password']);

        return $charger;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function chargerId(array $input): int
    {
        $id = $this->integerValue($input, 'charger_id', 0);
        if ($id < 1 || $this->chargers->get($id) === null) {
            throw new InvalidArgumentException('Select a valid charger.');
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function stringValue(array $input, string $key, string $fallback): string
    {
        if (!array_key_exists($key, $input)) {
            return $fallback;
        }

        if (!is_string($input[$key])) {
            throw new InvalidArgumentException("{$key} must be a string.");
        }

        return trim($input[$key]);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function integerValue(array $input, string $key, int $fallback): int
    {
        if (!array_key_exists($key, $input)) {
            return $fallback;
        }

        $value = $input[$key];
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new InvalidArgumentException("{$key} must be a whole number.");
    }

    /**
     * @param array<string, mixed> $input
     */
    private function requiredString(array $input, string $key): string
    {
        if (!isset($input[$key]) || !is_string($input[$key])) {
            throw new InvalidArgumentException("{$key} must be a string.");
        }

        return trim($input[$key]);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function booleanValue(array $input, string $key, bool $fallback): bool
    {
        if (!array_key_exists($key, $input)) {
            return $fallback;
        }

        if (is_bool($input[$key])) {
            return $input[$key];
        }
        if (is_string($input[$key]) && in_array(strtolower($input[$key]), ['1', 'true', 'on'], true)) {
            return true;
        }
        if (is_string($input[$key]) && in_array(strtolower($input[$key]), ['0', 'false', 'off'], true)) {
            return false;
        }

        throw new InvalidArgumentException("{$key} must be a boolean value.");
    }

    /**
     * @param array<string, mixed> $input
     */
    private function passwordValue(array $input, string $currentPassword): string
    {
        if ($this->booleanValue($input, 'clear_csms_password', false)) {
            return '';
        }

        $password = $input['csms_password'] ?? '';
        if (!is_string($password)) {
            throw new InvalidArgumentException('csms_password must be a string.');
        }

        if ($password === '') {
            return $currentPassword;
        }
        if (strlen($password) > 1024) {
            throw new InvalidArgumentException('CSMS password must be 1024 bytes or fewer.');
        }

        return $password;
    }

    private function validateCsmsUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['ws', 'wss'], true) || !isset($parts['host'])) {
            throw new InvalidArgumentException('Enter a CSMS WebSocket URL starting with ws:// or wss://.');
        }
    }

    private function validateTlsServerName(string $serverName): void
    {
        if ($serverName === '') {
            return;
        }

        if (strlen($serverName) > 253
            || (filter_var($serverName, FILTER_VALIDATE_IP) === false
                && preg_match('/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(?:\.(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?))*$/D', $serverName) !== 1)) {
            throw new InvalidArgumentException('TLS server name must be a hostname or IP address.');
        }
    }
}
