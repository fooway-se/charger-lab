<?php

declare(strict_types=1);

namespace ChargerEmulator\Controller;

use ChargerEmulator\Http\Request;
use ChargerEmulator\Http\Response;
use ChargerEmulator\Protocol\OcppClient;
use ChargerEmulator\Protocol\WebSocketConnection;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\ExchangeRepository;
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
    ) {
    }

    public function state(Request $request): Response
    {
        return Response::json(200, ['charger' => $this->chargers->get(), 'entries' => $this->logs->recent()]);
    }

    public function save(Request $request): Response
    {
        try {
            $charger = $this->validatedCharger($request->body());
        } catch (InvalidArgumentException $exception) {
            return Response::json(422, ['error' => $exception->getMessage()]);
        }

        $this->chargers->save($charger);

        return Response::json(200, ['notice' => 'Charger configuration saved.', 'charger' => $this->chargers->get()]);
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
        $this->logs->clear();

        return Response::json(200, ['notice' => 'Exchange log cleared.']);
    }

    private function executeOcppAction(Request $request, string $action): Response
    {
        try {
            $charger = $this->validatedCharger($request->body());
            $token = $action === 'Authorize' ? $this->requiredString($request->body(), 'rfid_token') : null;
        } catch (InvalidArgumentException $exception) {
            return Response::json(422, ['error' => $exception->getMessage()]);
        }

        if ($action === 'Authorize' && trim((string) $token) === '') {
            return Response::json(422, ['error' => 'Enter an RFID token to present.']);
        }

        $this->chargers->save($charger);
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
                new WebSocketConnection($charger['csms_url']),
                function (string $direction, string $message) use ($action): void {
                    $this->logs->record($direction, $action, $message);
                },
            );
            $result = $client->request($action, $payload);
            if ($action === 'BootNotification') {
                $this->chargers->setBootStatus((string) ($result['status'] ?? 'Unknown response'));
                $notice = 'BootNotification received: ' . (string) ($result['status'] ?? 'unknown') . '.';
            } else {
                $notice = 'RFID token response: ' . (string) ($result['idTokenInfo']['status'] ?? 'Unknown response') . '.';
            }

            return Response::json(200, ['notice' => $notice, 'result' => $result]);
        } catch (Throwable $exception) {
            $this->logs->record('system', $action, $exception->getMessage());
            if ($action === 'BootNotification') {
                $this->chargers->setBootStatus('Connection failed');
            }

            return Response::json(502, ['error' => $exception->getMessage()]);
        }
    }

    /**
     * @param array<string, mixed> $input
     *  @return array{station_identity:string,csms_url:string,vendor_name:string,model:string,serial_number:string}
     */
    private function validatedCharger(array $input): array
    {
        $current = $this->chargers->get();
        $charger = [
            'station_identity' => $this->stringValue($input, 'station_identity', $current['station_identity']),
            'csms_url' => $this->stringValue($input, 'csms_url', $current['csms_url']),
            'vendor_name' => $this->stringValue($input, 'vendor_name', $current['vendor_name']),
            'model' => $this->stringValue($input, 'model', $current['model']),
            'serial_number' => $this->stringValue($input, 'serial_number', $current['serial_number']),
        ];

        if ($charger['station_identity'] === '' || $charger['vendor_name'] === '' || $charger['model'] === '') {
            throw new InvalidArgumentException('Station identity, vendor, and model are required.');
        }

        $parts = parse_url($charger['csms_url']);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['ws', 'wss'], true) || !isset($parts['host'])) {
            throw new InvalidArgumentException('Enter a CSMS WebSocket URL starting with ws:// or wss://.');
        }

        return $charger;
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
    private function requiredString(array $input, string $key): string
    {
        if (!isset($input[$key]) || !is_string($input[$key])) {
            throw new InvalidArgumentException("{$key} must be a string.");
        }

        return trim($input[$key]);
    }
}
