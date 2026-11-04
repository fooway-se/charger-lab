<?php

declare(strict_types=1);

namespace ChargerEmulator\Protocol;

use InvalidArgumentException;

/**
 * Derives station-specific OCPP-J connection URLs from CSMS endpoint URLs.
 */
final class OcppConnectionUrl
{
    public static function fromEndpoint(string $endpointUrl, string $stationIdentity): string
    {
        $parts = self::parse($endpointUrl);
        $path = rtrim((string) ($parts['path'] ?? ''), '/') . '/' . rawurlencode($stationIdentity);

        return self::build($parts, $path);
    }

    public static function endpointFromConnectionUrl(string $connectionUrl, string $stationIdentity): string
    {
        $parts = self::parse($connectionUrl);
        $path = (string) ($parts['path'] ?? '');
        $identitySuffix = '/' . rawurlencode($stationIdentity);
        if (!str_ends_with($path, $identitySuffix)) {
            return $connectionUrl;
        }

        $parts['path'] = substr($path, 0, -strlen($identitySuffix));

        return self::build($parts, (string) $parts['path']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function parse(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || !in_array($parts['scheme'], ['ws', 'wss'], true)) {
            throw new InvalidArgumentException('CSMS endpoint must start with ws:// or wss://.');
        }

        return $parts;
    }

    /**
     * @param array<string, mixed> $parts
     */
    private static function build(array $parts, string $path): string
    {
        $host = (string) $parts['host'];
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }

        $authority = '';
        if (isset($parts['user'])) {
            $authority = $parts['user'];
            if (isset($parts['pass'])) {
                $authority .= ':' . $parts['pass'];
            }
            $authority .= '@';
        }
        $authority .= $host;
        if (isset($parts['port'])) {
            $authority .= ':' . $parts['port'];
        }

        $url = $parts['scheme'] . '://' . $authority . $path;
        if (isset($parts['query'])) {
            $url .= '?' . $parts['query'];
        }
        if (isset($parts['fragment'])) {
            $url .= '#' . $parts['fragment'];
        }

        return $url;
    }
}
