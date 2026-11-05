<?php

declare(strict_types=1);

namespace ChargerEmulator\Controller;

use ChargerEmulator\Domain\OutletMap;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\WorkspaceSettingsRepository;
use InvalidArgumentException;

/**
 * Parses and validates charger request fields before the controller persists them.
 */
final class ChargerInput
{
    public function __construct(
        private readonly ChargerRepository $chargers,
        private readonly WorkspaceSettingsRepository $settings,
    ) {
    }

    public function validatedCharger(int $id, array $input): array
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

    public function chargerId(array $input): int
    {
        $id = $this->integerValue($input, 'charger_id', 0);
        if ($id < 1 || $this->chargers->get($id) === null) {
            throw new InvalidArgumentException('Select a valid charger.');
        }

        return $id;
    }

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

    public function integerValue(array $input, string $key, int $fallback): int
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

    public function requiredString(array $input, string $key): string
    {
        if (!isset($input[$key]) || !is_string($input[$key])) {
            throw new InvalidArgumentException("{$key} must be a string.");
        }

        return trim($input[$key]);
    }

    public function validateCsmsUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['ws', 'wss'], true) || !isset($parts['host'])) {
            throw new InvalidArgumentException('Enter a CSMS WebSocket URL starting with ws:// or wss://.');
        }
    }

    public function validateTlsServerName(string $serverName): void
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

    public function booleanValue(array $input, string $key, bool $fallback): bool
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
}
