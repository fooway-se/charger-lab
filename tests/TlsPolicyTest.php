<?php

declare(strict_types=1);

use ChargerEmulator\Protocol\TlsPolicy;

it('allows TLS 1.2 and newer protocols for OCPP secure connections', function (): void {
    $expected = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
        | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? constant('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') : 0);

    expect(TlsPolicy::clientCryptoMethod())->toBe($expected);
});

it('identifies lower-version TLS handshake failures without mislabeling certificate failures', function (string $message, bool $expected): void {
    expect(TlsPolicy::isVersionNegotiationFailure($message))->toBe($expected);
})->with([
    ['tlsv1 alert protocol version', true],
    ['unsupported protocol', true],
    ['certificate verify failed', false],
    ['connection refused', false],
]);
