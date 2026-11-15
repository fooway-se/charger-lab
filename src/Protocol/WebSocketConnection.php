<?php

declare(strict_types=1);

namespace ChargerEmulator\Protocol;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Opens an OCPP 2.0.1 WebSocket and exchanges masked text frames using PHP streams.
 */
final class WebSocketConnection implements Connection
{
    private const MAX_FRAME_SIZE = 1_048_576;

    private const MAX_MESSAGE_SIZE = 1_048_576;

    private const MAX_HANDSHAKE_SIZE = 16_384;

    private const MAX_HEADER_LINE_SIZE = 8_192;

    /**
     * @var resource
     */
    private $stream;

    private string $readBuffer = '';

    private string $handshakeBuffer = '';

    private ?string $fragmentedPayload = null;

    private bool $streamClosed = false;

    public function __construct(
        string $url,
        private readonly int $timeoutSeconds = 8,
        private readonly ?string $username = null,
        private readonly ?string $password = null,
        private readonly ?string $tlsServerName = null,
        private readonly ?string $tlsCaCertificatePem = null,
    ) {
        if ($timeoutSeconds < 1) {
            throw new InvalidArgumentException('The WebSocket timeout must be at least one second.');
        }

        if ($password !== null && $password !== '' && $username === null) {
            throw new InvalidArgumentException('A username is required when a CSMS password is configured.');
        }

        if (preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            throw new InvalidArgumentException('CSMS WebSocket URL contains whitespace or control characters.');
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || !in_array($parts['scheme'], ['ws', 'wss'], true)) {
            throw new RuntimeException('CSMS URL must start with ws:// or wss://.');
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('CSMS WebSocket URL must not include user information or a fragment.');
        }

        $path = $parts['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        }

        $requestTarget = $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
        if (preg_match(
            '#\A/(?:[A-Za-z0-9._~!$&\'()*+,;=:@/-]|%[A-Fa-f0-9]{2})*(?:\?(?:[A-Za-z0-9._~!$&\'()*+,;=:@/?-]|%[A-Fa-f0-9]{2})*)?\z#D',
            $requestTarget,
        ) !== 1) {
            throw new InvalidArgumentException('CSMS WebSocket URL must contain a valid origin-form request target.');
        }

        $secure = $parts['scheme'] === 'wss';
        $port = $parts['port'] ?? ($secure ? 443 : 80);
        $host = $parts['host'];
        $addressHost = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $address = 'tcp://' . $addressHost . ':' . $port;
        $sslOptions = [
            'crypto_method' => TlsPolicy::clientCryptoMethod(),
            'peer_name' => $tlsServerName ?? $host,
            'verify_peer' => true,
            'verify_peer_name' => true,
        ];
        $caFile = null;
        if ($secure && $tlsCaCertificatePem !== null && $tlsCaCertificatePem !== '') {
            if (!str_contains($tlsCaCertificatePem, '-----BEGIN CERTIFICATE-----')
                || @openssl_x509_read($tlsCaCertificatePem) === false) {
                throw new InvalidArgumentException('The configured TLS CA certificate is not valid PEM X.509 data.');
            }

            $caFile = tempnam(sys_get_temp_dir(), 'charger-csms-ca-');
            if (!is_string($caFile)) {
                throw new RuntimeException('Could not prepare the configured CSMS CA certificate.');
            }

            if (file_put_contents($caFile, $tlsCaCertificatePem) === false) {
                unlink($caFile);
                throw new RuntimeException('Could not write the configured CSMS CA certificate.');
            }

            if (!chmod($caFile, 0600)) {
                unlink($caFile);
                throw new RuntimeException('Could not secure the configured CSMS CA certificate.');
            }

            $sslOptions['cafile'] = $caFile;
        }

        $context = stream_context_create($secure ? ['ssl' => $sslOptions] : []);
        $streamWarning = '';
        set_error_handler(static function (int $severity, string $message) use (&$streamWarning): bool {
            if (($severity & E_WARNING) !== 0) {
                $streamWarning = $message;

                return true;
            }

            return false;
        });
        $errorNumber = 0;
        $errorMessage = '';
        try {
            $stream = stream_socket_client($address, $errorNumber, $errorMessage, $timeoutSeconds, STREAM_CLIENT_CONNECT, $context);
            if (!is_resource($stream)) {
                throw new RuntimeException("Could not connect to CSMS ({$errorMessage}).", $errorNumber);
            }

            $this->stream = $stream;
            if (!stream_set_timeout($this->stream, $timeoutSeconds)) {
                throw new RuntimeException('Could not set the CSMS WebSocket timeout.');
            }

            if ($secure && stream_socket_enable_crypto($this->stream, true, TlsPolicy::clientCryptoMethod()) !== true) {
                throw new RuntimeException('Could not connect to CSMS (TLS handshake failed).', $errorNumber);
            }

            if (!stream_set_blocking($this->stream, false)) {
                throw new RuntimeException('Could not configure the CSMS WebSocket as nonblocking.');
            }

            $this->handshake($requestTarget, $host, $port, $secure);
            $this->readBuffer = $this->handshakeBuffer;
            $this->handshakeBuffer = '';
        } catch (Throwable $exception) {
            if (isset($this->stream) && is_resource($this->stream)) {
                fclose($this->stream);
            }
            if ($secure && TlsPolicy::isVersionNegotiationFailure($errorMessage . ' ' . $streamWarning . ' ' . $exception->getMessage())) {
                throw new TlsVersionException('The CSMS rejected the TLS version required by OCPP 2.0.1.', $errorNumber, $exception);
            }

            throw $exception;
        } finally {
            restore_error_handler();
            if (is_string($caFile) && is_file($caFile)) {
                unlink($caFile);
            }
        }
    }

    public function send(string $message): void
    {
        if ($this->streamClosed) {
            throw new RuntimeException('CSMS WebSocket connection is closed.');
        }

        if (preg_match('//u', $message) !== 1) {
            throw new OcppProtocolException('Cannot send invalid UTF-8 in a WebSocket text message.');
        }

        $this->writeFrame(0x1, $message);
    }

    public function receive(): string
    {
        $deadline = microtime(true) + $this->timeoutSeconds;
        while (true) {
            $message = $this->readAvailable($deadline);
            if ($message !== null) {
                return $message;
            }

            $this->waitForStream(false, $deadline, 'Could not wait for a CSMS WebSocket frame.', 'Timed out waiting for CSMS.');
        }
    }

    public function receiveAvailable(): ?string
    {
        return $this->readAvailable(null);
    }

    private function readAvailable(?float $deadline): ?string
    {
        while (true) {
            if ($deadline !== null && microtime(true) >= $deadline) {
                throw new RuntimeException('Timed out waiting for CSMS.');
            }

            $message = $this->messageFromBuffer();
            if ($message !== null) {
                return $message;
            }

            if ($this->streamClosed) {
                throw new RuntimeException($this->readBuffer === ''
                    ? 'CSMS closed the connection.'
                    : 'CSMS closed in the middle of a WebSocket frame.');
            }

            if ($deadline !== null) {
                $this->waitForStream(false, $deadline, 'Could not wait for CSMS data.', 'Timed out waiting for CSMS.');
            }

            $chunk = fread($this->stream, 8192);
            if ($chunk === false) {
                throw new RuntimeException('Could not read from the CSMS WebSocket.');
            }

            if ($chunk === '') {
                if (feof($this->stream)) {
                    $this->streamClosed = true;
                    continue;
                }

                return null;
            }

            $this->readBuffer .= $chunk;
        }
    }

    private function messageFromBuffer(): ?string
    {
        while (strlen($this->readBuffer) >= 2) {
            $first = ord($this->readBuffer[0]);
            $second = ord($this->readBuffer[1]);
            $isFinal = ($first & 0x80) !== 0;
            $reservedBits = $first & 0x70;
            $opcode = $first & 0x0f;
            $isMasked = ($second & 0x80) !== 0;
            $length = $second & 0x7f;
            $headerLength = 2;

            if ($reservedBits !== 0) {
                throw new OcppProtocolException('CSMS WebSocket frame uses unsupported reserved bits.');
            }

            if (!in_array($opcode, [0x0, 0x1, 0x8, 0x9, 0xA], true)) {
                throw new OcppProtocolException('CSMS sent an unsupported WebSocket opcode.');
            }

            if ($isMasked) {
                throw new OcppProtocolException('CSMS WebSocket frames must be unmasked.');
            }

            if ($opcode >= 0x8 && (!$isFinal || $length > 125)) {
                throw new OcppProtocolException('CSMS sent an invalid WebSocket control frame.');
            }

            if ($length === 126) {
                $headerLength += 2;
                if (strlen($this->readBuffer) < $headerLength) {
                    return null;
                }

                $length = unpack('n', substr($this->readBuffer, 2, 2))[1];
                if ($length < 126) {
                    throw new OcppProtocolException('CSMS used a non-minimal WebSocket payload length.');
                }
            } elseif ($length === 127) {
                $headerLength += 8;
                if (strlen($this->readBuffer) < $headerLength) {
                    return null;
                }

                $wideLength = unpack('Nhigh/Nlow', substr($this->readBuffer, 2, 8));
                if ($wideLength['high'] !== 0) {
                    throw new OcppProtocolException('CSMS WebSocket frame is too large.');
                }

                $length = $wideLength['low'];
                if ($length <= 0xffff) {
                    throw new OcppProtocolException('CSMS used a non-minimal WebSocket payload length.');
                }
            }

            if ($length > self::MAX_FRAME_SIZE) {
                throw new OcppProtocolException('CSMS WebSocket frame exceeds the 1 MiB limit.');
            }

            if ($opcode === 0x0 && $this->fragmentedPayload === null) {
                throw new OcppProtocolException('CSMS sent a continuation frame without a fragmented message.');
            }

            if ($opcode === 0x1 && $this->fragmentedPayload !== null) {
                throw new OcppProtocolException('CSMS started a new message before completing a fragmented one.');
            }

            if ($opcode === 0x0 && strlen($this->fragmentedPayload) + $length > self::MAX_MESSAGE_SIZE) {
                throw new OcppProtocolException('CSMS WebSocket message exceeds the 1 MiB limit.');
            }

            if (strlen($this->readBuffer) < $headerLength + $length) {
                return null;
            }

            $payload = substr($this->readBuffer, $headerLength, $length);
            $this->readBuffer = substr($this->readBuffer, $headerLength + $length);
            if ($opcode === 0x8) {
                $this->validateClosePayload($payload);
                $this->writeFrame(0x8, $payload);
                $this->streamClosed = true;
                fclose($this->stream);
                throw new RuntimeException('CSMS closed the WebSocket connection.');
            }

            if ($opcode === 0x9) {
                $this->writeFrame(0xA, $payload);
                continue;
            }

            if ($opcode === 0xA) {
                continue;
            }

            if ($opcode === 0x1) {
                if (!$isFinal) {
                    $this->fragmentedPayload = $payload;
                    continue;
                }

                $message = $payload;
            } else {
                $this->fragmentedPayload .= $payload;
                if (!$isFinal) {
                    continue;
                }

                $message = $this->fragmentedPayload;
                $this->fragmentedPayload = null;
            }

            if (preg_match('//u', $message) !== 1) {
                throw new OcppProtocolException('CSMS sent invalid UTF-8 in a WebSocket text message.');
            }

            return $message;
        }

        return null;
    }

    private function validateClosePayload(string $payload): void
    {
        if (strlen($payload) === 1) {
            throw new OcppProtocolException('CSMS sent an invalid WebSocket close payload.');
        }

        if ($payload === '') {
            return;
        }

        $statusCode = unpack('n', substr($payload, 0, 2))[1];
        $validCode = ($statusCode >= 1000 && $statusCode <= 1014 && !in_array($statusCode, [1004, 1005, 1006, 1010], true))
            || ($statusCode >= 3000 && $statusCode <= 4999);
        if (!$validCode || preg_match('//u', substr($payload, 2)) !== 1) {
            throw new OcppProtocolException('CSMS sent an invalid WebSocket close status or reason.');
        }
    }

    private function handshake(string $requestTarget, string $host, int $port, bool $secure): void
    {
        $deadline = microtime(true) + $this->timeoutSeconds;
        $key = base64_encode(random_bytes(16));
        $hostHeader = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $defaultPort = $secure ? 443 : 80;
        if ($port !== $defaultPort) {
            $hostHeader .= ':' . $port;
        }

        $request = "GET {$requestTarget} HTTP/1.1\r\n"
            . "Host: {$hostHeader}\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n";
        if ($this->password !== null && $this->password !== '') {
            $request .= 'Authorization: Basic ' . base64_encode($this->username . ':' . $this->password) . "\r\n";
        }

        $request .= "Sec-WebSocket-Protocol: ocpp2.0.1\r\n\r\n";
        $this->writeAll($request, $deadline);

        $responseBytes = 0;
        $status = $this->readHandshakeLine($responseBytes, $deadline);
        if (preg_match('/\AHTTP\/1\.1 101[ \t][\x09\x20-\x7E\x80-\xFF]*\r\n\z/', $status) !== 1) {
            $statusCode = preg_match('/^HTTP\/1\.\d\s+(\d{3})\b/', $status, $statusMatches) === 1
                ? $statusMatches[1]
                : null;
            $message = is_string($statusCode)
                ? "CSMS rejected the WebSocket upgrade with HTTP {$statusCode}."
                : 'CSMS returned a malformed WebSocket status line.';

            throw new RuntimeException($message);
        }

        $headers = [];
        while (true) {
            $line = $this->readHandshakeLine($responseBytes, $deadline);
            if ($line === "\r\n") {
                break;
            }

            $headerLine = substr($line, 0, -2);
            if (preg_match("/^([!#$%&'*+.^_`|~0-9A-Za-z-]+):[ \\t]*(.*)$/D", $headerLine, $matches) !== 1
                || preg_match('/[\\x00-\\x08\\x0A-\\x1F\\x7F]/', $matches[2]) === 1) {
                throw new RuntimeException('CSMS returned a malformed WebSocket response header.');
            }

            $headers[strtolower($matches[1])][] = trim($matches[2]);
        }

        $expectedAccept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if (!$this->containsHeaderToken($headers['upgrade'] ?? [], 'websocket')) {
            throw new RuntimeException('CSMS returned an invalid WebSocket Upgrade header.');
        }

        if (!$this->containsHeaderToken($headers['connection'] ?? [], 'upgrade')) {
            throw new RuntimeException('CSMS returned an invalid WebSocket Connection header.');
        }

        if (count($headers['sec-websocket-accept'] ?? []) !== 1
            || $headers['sec-websocket-accept'][0] !== $expectedAccept) {
            throw new RuntimeException('CSMS returned an invalid WebSocket accept key.');
        }

        if (isset($headers['sec-websocket-extensions'])) {
            throw new RuntimeException('CSMS selected an unsupported WebSocket extension.');
        }

        if (($headers['sec-websocket-protocol'] ?? []) !== ['ocpp2.0.1']) {
            throw new RuntimeException('CSMS did not select the ocpp2.0.1 subprotocol.');
        }
    }

    private function readHandshakeLine(int &$responseBytes, float $deadline): string
    {
        while (($lineEnd = strpos($this->handshakeBuffer, "\r\n")) === false) {
            if (strlen($this->handshakeBuffer) >= self::MAX_HEADER_LINE_SIZE) {
                throw new RuntimeException('CSMS returned an oversized WebSocket response header line.');
            }

            $this->waitForStream(false, $deadline, 'Could not wait for the CSMS WebSocket handshake.', 'Timed out waiting for the CSMS WebSocket handshake.');
            $chunk = fread($this->stream, min(8192, self::MAX_HEADER_LINE_SIZE + 1 - strlen($this->handshakeBuffer)));
            if ($chunk === false || ($chunk === '' && feof($this->stream))) {
                throw new RuntimeException('CSMS closed during the WebSocket handshake.');
            }

            if ($chunk !== '') {
                $this->handshakeBuffer .= $chunk;
            }
        }

        $line = substr($this->handshakeBuffer, 0, $lineEnd + 2);
        $this->handshakeBuffer = substr($this->handshakeBuffer, $lineEnd + 2);

        $responseBytes += strlen($line);
        if ($responseBytes > self::MAX_HANDSHAKE_SIZE) {
            throw new RuntimeException('CSMS WebSocket response headers exceed the size limit.');
        }

        return $line;
    }

    private function containsHeaderToken(array $values, string $token): bool
    {
        foreach ($values as $value) {
            foreach (explode(',', $value) as $headerToken) {
                if (strcasecmp(trim($headerToken), $token) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    private function writeFrame(int $opcode, string $payload): void
    {
        $length = strlen($payload);
        if ($length > self::MAX_FRAME_SIZE) {
            throw new OcppProtocolException('OCPP WebSocket frame exceeds the 1 MiB limit.');
        }

        $frame = chr(0x80 | $opcode);
        if ($length < 126) {
            $frame .= chr(0x80 | $length);
        } elseif ($length <= 0xffff) {
            $frame .= chr(0x80 | 126) . pack('n', $length);
        } else {
            $frame .= chr(0x80 | 127) . pack('N2', 0, $length);
        }

        $mask = random_bytes(4);
        $frame .= $mask;
        for ($index = 0; $index < $length; $index++) {
            $frame .= $payload[$index] ^ $mask[$index % 4];
        }

        $this->writeAll($frame);
    }

    private function writeAll(string $data, ?float $deadline = null): void
    {
        $deadline ??= microtime(true) + $this->timeoutSeconds;
        $offset = 0;
        while ($offset < strlen($data)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Timed out writing to the CSMS connection.');
            }

            $written = fwrite($this->stream, substr($data, $offset));
            if ($written === false) {
                throw new RuntimeException('Could not write to the CSMS connection.');
            }

            if ($written === 0) {
                $this->waitForStream(true, $deadline, 'Could not wait for the CSMS connection to accept data.', 'Timed out writing to the CSMS connection.');
                continue;
            }

            $offset += $written;
        }
    }

    private function waitForStream(bool $writable, float $deadline, string $failure, string $timeout): void
    {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            throw new RuntimeException($timeout);
        }

        $seconds = (int) $remaining;
        $microseconds = (int) (($remaining - $seconds) * 1_000_000);
        $read = $writable ? [] : [$this->stream];
        $write = $writable ? [$this->stream] : [];
        $except = [];
        $ready = stream_select($read, $write, $except, $seconds, $microseconds);
        if ($ready === false) {
            throw new RuntimeException($failure);
        }

        if ($ready === 0) {
            throw new RuntimeException($timeout);
        }
    }

    public function __destruct()
    {
        if (isset($this->stream) && is_resource($this->stream)) {
            fclose($this->stream);
        }
    }
}
