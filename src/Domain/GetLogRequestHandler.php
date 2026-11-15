<?php

declare(strict_types=1);

namespace ChargerEmulator\Domain;

use ChargerEmulator\Protocol\OcppCallErrorException;

/** Validates CSMS GetLog requests and provides deterministic emulator log filenames. */
final class GetLogRequestHandler
{
    private const LOG_TYPES = ['DiagnosticsLog', 'SecurityLog'];

    public function handle(array $payload): array
    {
        $log = $payload['log'] ?? null;
        if (array_diff(array_keys($payload), ['customData', 'log', 'logType', 'requestId', 'retries', 'retryInterval']) !== []
            || !CustomDataValidator::isValid($payload['customData'] ?? null, array_key_exists('customData', $payload))
            || !is_int($payload['requestId'] ?? null) || $payload['requestId'] < 0 || $payload['requestId'] > 2_147_483_647
            || !in_array($payload['logType'] ?? null, self::LOG_TYPES, true)
            || !is_array($log)
            || array_diff(array_keys($log), ['customData', 'latestTimestamp', 'oldestTimestamp', 'remoteLocation']) !== []
            || !CustomDataValidator::isValid($log['customData'] ?? null, array_key_exists('customData', $log))
            || !$this->validLocation($log['remoteLocation'] ?? null)
            || !$this->validTimestampIfPresent($log, 'oldestTimestamp')
            || !$this->validTimestampIfPresent($log, 'latestTimestamp')
            || !$this->validIntegerIfPresent($payload, 'retries')
            || !$this->validIntegerIfPresent($payload, 'retryInterval')) {
            throw new OcppCallErrorException('FormationViolation', 'GetLog requires valid log type, request ID, location and retry settings.');
        }

        $location = parse_url($log['remoteLocation']);
        if (!is_array($location) || !in_array($location['scheme'] ?? null, ['http', 'https'], true)) {
            return ['status' => 'Rejected'];
        }

        $prefix = $payload['logType'] === 'SecurityLog' ? 'security' : 'diagnostics';

        return ['status' => 'Accepted', 'filename' => $prefix . '-' . $payload['requestId'] . '.log'];
    }

    private function validLocation(mixed $value): bool
    {
        if (!is_string($value) || $value === '' || strlen($value) > 512 || preg_match('/[\x00-\x20\x7f]/', $value) === 1) {
            return false;
        }

        try {
            $parts = parse_url($value);
        } catch (\ValueError) {
            return false;
        }

        return is_array($parts)
            && in_array($parts['scheme'] ?? null, ['http', 'https', 'ftp', 'ftps'], true)
            && isset($parts['host'])
            && !isset($parts['fragment']);
    }

    private function validTimestampIfPresent(array $payload, string $field): bool
    {
        if (!array_key_exists($field, $payload)) {
            return true;
        }

        return is_string($payload[$field])
            && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/', $payload[$field]) === 1
            && strtotime($payload[$field]) !== false;
    }

    private function validIntegerIfPresent(array $payload, string $field): bool
    {
        return !array_key_exists($field, $payload)
            || (is_int($payload[$field]) && $payload[$field] >= 0 && $payload[$field] <= 2_147_483_647);
    }
}
