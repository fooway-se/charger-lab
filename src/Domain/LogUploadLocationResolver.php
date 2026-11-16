<?php

declare(strict_types=1);

namespace ChargerEmulator\Domain;

/** Repoints CSMS loopback log-upload URLs to the reachable CSMS hostname for Docker workers. */
final class LogUploadLocationResolver
{
    public function resolve(string $remoteLocation, ?string $csmsUrl): string
    {
        if ($csmsUrl === null) {
            return $remoteLocation;
        }

        try {
            $location = parse_url($remoteLocation);
            $endpoint = parse_url($csmsUrl);
        } catch (\ValueError) {
            return $remoteLocation;
        }

        if (!is_array($location) || !is_array($endpoint)
            || !isset($location['host'], $endpoint['host'])
            || !$this->isLoopbackHost($location['host'])
            || $this->isLoopbackHost($endpoint['host'])) {
            return $remoteLocation;
        }

        return $this->withHost($location, $endpoint['host']);
    }

    private function isLoopbackHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return true;
        }

        $address = @inet_pton($host);
        if ($address === false) {
            return false;
        }

        return strlen($address) === 4
            ? ord($address[0]) === 127
            : $address === inet_pton('::1');
    }

    private function withHost(array $parts, string $host): string
    {
        $url = $parts['scheme'] . '://';
        if (isset($parts['user'])) {
            $url .= $parts['user'];
            if (isset($parts['pass'])) {
                $url .= ':' . $parts['pass'];
            }
            $url .= '@';
        }

        $url .= str_contains($host, ':') ? '[' . $host . ']' : $host;
        if (isset($parts['port'])) {
            $url .= ':' . $parts['port'];
        }

        $url .= $parts['path'] ?? '';
        if (isset($parts['query'])) {
            $url .= '?' . $parts['query'];
        }

        return $url;
    }
}
