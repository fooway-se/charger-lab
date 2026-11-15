<?php

declare(strict_types=1);

namespace ChargerEmulator\Protocol;

/** TLS versions allowed for OCPP 2.0.1 secure transport. */
final class TlsPolicy
{
    public static function clientCryptoMethod(): int
    {
        $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $method |= constant('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT');
        }

        return $method;
    }

    public static function isVersionNegotiationFailure(string $message): bool
    {
        return preg_match('/(?:unsupported protocol|protocol version|no protocols available)/i', $message) === 1;
    }
}
