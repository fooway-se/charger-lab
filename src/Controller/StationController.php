<?php

declare(strict_types=1);

namespace ChargerEmulator\Controller;

use ChargerEmulator\Domain\OutletMap;
use ChargerEmulator\Http\Request;
use ChargerEmulator\Http\Response;
use ChargerEmulator\Protocol\OcppClient;
use ChargerEmulator\Protocol\OcppConnectionUrl;
use ChargerEmulator\Protocol\WebSocketConnection;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\ExchangeRepository;
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
    ) {
    }

    public function state(Request $request): Response
    {
        $defaultCsmsUrl = $this->settings->getCsmsUrl();
        $chargers = [];
        foreach ($this->chargers->all() as $charger) {
            $charger['uses_default_csms'] = (bool) $charger['uses_default_csms'];
            if ($charger['uses_default_csms']) {
                $charger['csms_url'] = $defaultCsmsUrl;
            }
            $charger['outlets'] = (new OutletMap((int) $charger['outlet_count'], $charger['outlet_topology']))->outlets();
            $charger['entries'] = $this->logs->recent((int) $charger['id']);
            $chargers[] = $charger;
        }

        return Response::json(200, [
            'settings' => ['default_csms_url' => $defaultCsmsUrl],
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

        $this->chargers->save($id, $charger);

        return Response::json(200, ['notice' => 'Charger configuration saved.', 'charger' => $this->chargers->get($id)]);
    }

    public function boot(Request $request): Response
    {
        return $this->executeOcppAction($request, 'BootNotification');
    }

    public function authorize(Request $request): Response
    {
        return $this->executeOcppAction($request, 'Authorize');
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

        $this->chargers->delete($id);

        return Response::json(200, ['notice' => 'Charger removed.']);
    }

    private function executeOcppAction(Request $request, string $action): Response
    {
        try {
            $input = $request->body();
            $id = $this->chargerId($input);
            $charger = $this->validatedCharger($id, $input);
            $token = $action === 'Authorize' ? $this->requiredString($request->body(), 'rfid_token') : null;
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

        try {
            $client = new OcppClient(
                new WebSocketConnection(OcppConnectionUrl::fromEndpoint($charger['csms_url'], $charger['station_identity'])),
                function (string $direction, string $message) use ($action, $id): void {
                    $this->logs->record($id, $direction, $action, $message);
                },
            );
            $result = $client->request($action, $payload);
            if ($action === 'BootNotification') {
                $this->chargers->setBootStatus($id, (string) ($result['status'] ?? 'Unknown response'));
                $notice = 'BootNotification received: ' . (string) ($result['status'] ?? 'unknown') . '.';
            } else {
                $notice = 'RFID token response: ' . (string) ($result['idTokenInfo']['status'] ?? 'Unknown response') . '.';
            }

            return Response::json(200, ['notice' => $notice, 'result' => $result]);
        } catch (Throwable $exception) {
            $this->logs->record($id, 'system', $action, $exception->getMessage());
            if ($action === 'BootNotification') {
                $this->chargers->setBootStatus($id, 'Connection failed');
            }

            return Response::json(502, ['error' => $exception->getMessage()]);
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{station_identity:string,csms_url:string,vendor_name:string,model:string,serial_number:string,outlet_count:int,outlet_topology:string,uses_default_csms:bool}
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

        new OutletMap($charger['outlet_count'], $charger['outlet_topology']);

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

    private function validateCsmsUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['ws', 'wss'], true) || !isset($parts['host'])) {
            throw new InvalidArgumentException('Enter a CSMS WebSocket URL starting with ws:// or wss://.');
        }
    }
}
