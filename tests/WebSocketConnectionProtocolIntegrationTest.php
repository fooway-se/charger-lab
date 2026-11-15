<?php

declare(strict_types=1);

use ChargerEmulator\Protocol\OcppProtocolException;
use ChargerEmulator\Protocol\TlsVersionException;
use ChargerEmulator\Protocol\WebSocketConnection;
use ChargerEmulator\Domain\CertificateManagementManager;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\OcppCertificateRepository;

function startWebSocketProtocolPeer(string $mode): array
{
    $process = proc_open(
        [PHP_BINARY, __DIR__ . '/fixtures/websocket-peer.php'],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
        $pipes,
        dirname(__DIR__),
        ['WEBSOCKET_TEST_MODE' => $mode],
    );
    expect(!is_resource($process))->toBeFalse('Could not start the WebSocket test peer.');

    $ready = fgets($pipes[1]);
    expect(!is_string($ready) || !preg_match('/^READY:(\d+)(?::([A-Za-z0-9+\/=]+))?\s*$/', $ready, $matches))->toBeFalse('The WebSocket test peer did not start.');

    $certificate = isset($matches[2]) ? base64_decode($matches[2], true) : '';

    return [(int) $matches[1], $process, $pipes, $certificate];
}

function stopWebSocketProtocolPeer(array $peer): void
{
    [, $process, $pipes] = $peer;
    proc_terminate($process);
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
}

it('rejects a WebSocket upgrade without required response headers', function (string $mode, string $message): void {
    $peer = startWebSocketProtocolPeer($mode);

    try {
        expect(fn () => new WebSocketConnection("ws://127.0.0.1:{$peer[0]}/", timeoutSeconds: 2))
            ->toThrow(RuntimeException::class, $message);
    } finally {
        stopWebSocketProtocolPeer($peer);
    }
})->with([
    ['missing-upgrade', 'Upgrade'],
    ['missing-connection', 'Connection'],
    ['unexpected-extension', 'extension'],
    ['duplicate-accept', 'accept key'],
    ['invalid-accept', 'accept key'],
    ['incorrect-subprotocol', 'subprotocol'],
    ['invalid-status', 'HTTP 200'],
    ['no-response', 'Timed out'],
    ['incomplete-handshake', 'Timed out'],
    ['closed-handshake', 'closed'],
    ['oversized-header', 'oversized'],
    ['oversized-headers', 'size limit'],
    ['malformed-header', 'malformed'],
])->group('integration');

it('rejects invalid WebSocket frame headers and close payloads', function (string $mode): void {
    $peer = startWebSocketProtocolPeer($mode);

    try {
        $connection = new WebSocketConnection("ws://127.0.0.1:{$peer[0]}/", timeoutSeconds: 2);
        expect(fn () => $connection->receive())->toThrow(OcppProtocolException::class);
    } finally {
        stopWebSocketProtocolPeer($peer);
    }
})->with([
    'reserved-bits',
    'unknown-opcode',
    'masked-server-frame',
    'unexpected-continuation',
    'interleaved-data-frame',
    'fragmented-ping',
    'oversized-ping',
    'fragmented-pong',
    'short-close',
    'invalid-close-code',
    'invalid-close-reason',
    'invalid-utf8',
    'nonminimal-frame',
    'oversized-frame',
    'oversized-fragmented-message',
])->group('integration');

it('reports a connection closed unexpectedly after the handshake', function (): void {
    $peer = startWebSocketProtocolPeer('disconnect');
    $connection = new WebSocketConnection("ws://127.0.0.1:{$peer[0]}/", timeoutSeconds: 2);

    try {
        expect(fn () => $connection->receive())->toThrow(RuntimeException::class, 'closed');
    } finally {
        stopWebSocketProtocolPeer($peer);
    }
})->group('integration');

it('rejects outgoing OCPP messages above the frame size limit', function (): void {
    $peer = startWebSocketProtocolPeer('valid');
    $connection = new WebSocketConnection("ws://127.0.0.1:{$peer[0]}/", timeoutSeconds: 2);

    try {
        expect(fn () => $connection->send(str_repeat('x', 1_048_577)))
            ->toThrow(OcppProtocolException::class, 'exceeds the 1 MiB limit');
    } finally {
        stopWebSocketProtocolPeer($peer);
    }
})->group('integration');

it('completes a WSS handshake with a trusted custom CA', function (): void {
    $peer = startWebSocketProtocolPeer('tls-valid');

    try {
        $connection = new WebSocketConnection(
            "wss://127.0.0.1:{$peer[0]}/",
            timeoutSeconds: 2,
            tlsServerName: 'localhost',
            tlsCaCertificatePem: $peer[3],
        );

        expect($connection->receive())->toBe('{}');
    } finally {
        stopWebSocketProtocolPeer($peer);
    }
})->group('integration');

it('completes a WSS handshake with TLS 1.2', function (): void {
    $peer = startWebSocketProtocolPeer('tls-v1.2-valid');

    try {
        $connection = new WebSocketConnection(
            "wss://127.0.0.1:{$peer[0]}/",
            timeoutSeconds: 2,
            tlsServerName: 'localhost',
            tlsCaCertificatePem: $peer[3],
        );

        expect($connection->receive())->toBe('{}');
    } finally {
        stopWebSocketProtocolPeer($peer);
    }
})->group('integration');

it('identifies a CSMS that rejects the minimum TLS version', function (): void {
    $peer = startWebSocketProtocolPeer('tls-version-too-low');

    try {
        expect(fn () => new WebSocketConnection("wss://127.0.0.1:{$peer[0]}/", timeoutSeconds: 2))
            ->toThrow(TlsVersionException::class, 'TLS version');
    } finally {
        stopWebSocketProtocolPeer($peer);
    }
})->group('integration');

it('trusts a CSMS root certificate installed in the OCPP certificate store', function (): void {
    $peer = startWebSocketProtocolPeer('tls-valid');

    try {
        $database = Database::connect(':memory:');
        $chargerId = (new ChargerRepository($database))->createDefault();
        $certificates = new OcppCertificateRepository($database);
        $certificates->install($chargerId, 'CSMSRootCertificate', $peer[3], hash('sha256', $peer[3]));
        $certificateManagement = new CertificateManagementManager($certificates);
        $connection = new WebSocketConnection(
            "wss://127.0.0.1:{$peer[0]}/",
            timeoutSeconds: 2,
            tlsServerName: 'localhost',
            tlsCaCertificatePem: $certificateManagement->csmsTlsTrustBundle($chargerId, '')
                ?? throw new RuntimeException('Installed CSMS root was not available to TLS.'),
        );

        expect($connection->receive())->toBe('{}');
    } finally {
        stopWebSocketProtocolPeer($peer);
    }
})->group('integration');

it('rejects an untrusted WSS certificate', function (): void {
    $peer = startWebSocketProtocolPeer('tls-invalid-certificate');

    try {
        set_error_handler(static fn (int $severity): bool => $severity === E_WARNING, E_WARNING);
        try {
            expect(fn () => new WebSocketConnection("wss://127.0.0.1:{$peer[0]}/", timeoutSeconds: 2))
                ->toThrow(RuntimeException::class, 'Could not connect to CSMS');
        } finally {
            restore_error_handler();
        }
    } finally {
        stopWebSocketProtocolPeer($peer);
    }
})->group('integration');

it('receives a fragmented text message', function (): void {
    $peer = startWebSocketProtocolPeer('fragmented-text');
    $connection = new WebSocketConnection("ws://127.0.0.1:{$peer[0]}/", timeoutSeconds: 2);

    try {
        expect($connection->receive())->toBe('{}');
    } finally {
        stopWebSocketProtocolPeer($peer);
    }
})->group('integration');

it('answers a ping with a pong carrying the same payload', function (): void {
    $peer = startWebSocketProtocolPeer('ping-pong');
    $connection = new WebSocketConnection("ws://127.0.0.1:{$peer[0]}/", timeoutSeconds: 2);

    try {
        expect($connection->receive())->toBe('{}');
    } finally {
        stopWebSocketProtocolPeer($peer);
    }
})->group('integration');

it('rejects sends after the CSMS closes the WebSocket', function (): void {
    $peer = startWebSocketProtocolPeer('close-after-handshake');
    $connection = new WebSocketConnection("ws://127.0.0.1:{$peer[0]}/", timeoutSeconds: 2);

    try {
        expect(fn () => $connection->receive())->toThrow(RuntimeException::class, 'closed');
        expect(fn () => $connection->send('{}'))->toThrow(RuntimeException::class, 'closed');
    } finally {
        stopWebSocketProtocolPeer($peer);
    }
})->group('integration');

it('rejects malformed WebSocket URLs before sending an upgrade request', function (callable $url): void {
    $peer = startWebSocketProtocolPeer('valid');

    try {
        expect(fn () => new WebSocketConnection($url($peer[0]), timeoutSeconds: 2))
            ->toThrow(InvalidArgumentException::class, 'WebSocket URL');
    } finally {
        stopWebSocketProtocolPeer($peer);
    }
})->with([
    'CRLF in path' => fn (int $port): string => "ws://127.0.0.1:{$port}/path\r\nInjected: yes",
    'control character in query' => fn (int $port): string => "ws://127.0.0.1:{$port}/?token=bad\x01value",
    'control character in hostname' => fn (int $port): string => "ws://127.0.0.1\r\n:{$port}/ocpp",
    'space in request target' => fn (int $port): string => "ws://127.0.0.1:{$port}/bad path",
    'URL user information' => fn (int $port): string => "ws://user:password@127.0.0.1:{$port}/ocpp",
    'URL fragment' => fn (int $port): string => "ws://127.0.0.1:{$port}/ocpp#fragment",
])->group('integration');

it('rejects invalid WebSocket timeouts before connecting', function (int $timeout): void {
    expect(fn () => new WebSocketConnection('ws://127.0.0.1:1/', timeoutSeconds: $timeout))
        ->toThrow(InvalidArgumentException::class, 'at least one second');
})->with([0, -1])->group('integration');

it('rejects invalid UTF-8 in outgoing WebSocket text messages', function (): void {
    $peer = startWebSocketProtocolPeer('valid');
    $connection = new WebSocketConnection("ws://127.0.0.1:{$peer[0]}/", timeoutSeconds: 2);

    try {
        expect(fn () => $connection->send("{\"invalid\":\"\xFF\"}"))
            ->toThrow(OcppProtocolException::class, 'invalid UTF-8');
    } finally {
        stopWebSocketProtocolPeer($peer);
    }
})->group('integration');

it('uses origin-form when the WebSocket URL has a query but no path', function (): void {
    $peer = startWebSocketProtocolPeer('require-origin-form');

    try {
        $connection = new WebSocketConnection("ws://127.0.0.1:{$peer[0]}?token=value", timeoutSeconds: 2);

        expect($connection)->toBeInstanceOf(WebSocketConnection::class);
    } finally {
        stopWebSocketProtocolPeer($peer);
    }
})->group('integration');

it('uses one receive deadline across partial frame reads', function (): void {
    $peer = startWebSocketProtocolPeer('slow-frame');
    $connection = new WebSocketConnection("ws://127.0.0.1:{$peer[0]}/", timeoutSeconds: 1);
    $startedAt = microtime(true);

    try {
        expect(fn () => $connection->receive())->toThrow(RuntimeException::class, 'Timed out');
        expect(microtime(true) - $startedAt)->toBeLessThan(1.5);
    } finally {
        stopWebSocketProtocolPeer($peer);
    }
})->group('integration');

it('uses one deadline across the WebSocket handshake', function (): void {
    $peer = startWebSocketProtocolPeer('slow-handshake');
    $startedAt = microtime(true);

    try {
        expect(fn () => new WebSocketConnection("ws://127.0.0.1:{$peer[0]}/", timeoutSeconds: 1))
            ->toThrow(RuntimeException::class, 'Timed out');
        expect(microtime(true) - $startedAt)->toBeLessThan(1.5);
    } finally {
        stopWebSocketProtocolPeer($peer);
    }
})->group('integration');

it('keeps the receive deadline while handling control frames', function (): void {
    $peer = startWebSocketProtocolPeer('ping-flood');
    $connection = new WebSocketConnection("ws://127.0.0.1:{$peer[0]}/", timeoutSeconds: 1);
    $startedAt = microtime(true);

    try {
        expect(fn () => $connection->receive())->toThrow(RuntimeException::class, 'Timed out');
        expect(microtime(true) - $startedAt)->toBeLessThan(1.5);
    } finally {
        stopWebSocketProtocolPeer($peer);
    }
})->group('integration');
