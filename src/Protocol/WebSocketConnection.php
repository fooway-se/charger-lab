<?php

declare(strict_types=1);

namespace ChargerEmulator\Protocol;

use RuntimeException;
use Throwable;

/**
 * Opens an OCPP 2.0.1 WebSocket and exchanges masked text frames using PHP streams.
 */
final class WebSocketConnection implements Connection
{
    /**
     * @var resource
     */
    private $stream;

    public function __construct(string $url, private readonly int $timeoutSeconds = 8)
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || !in_array($parts['scheme'], ['ws', 'wss'], true)) {
            throw new RuntimeException('CSMS URL must start with ws:// or wss://.');
        }

        $secure = $parts['scheme'] === 'wss';
        $port = $parts['port'] ?? ($secure ? 443 : 80);
        $host = $parts['host'];
        $addressHost = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $address = ($secure ? 'ssl' : 'tcp') . '://' . $addressHost . ':' . $port;
        $context = stream_context_create($secure ? [
            'ssl' => ['peer_name' => $host, 'verify_peer' => true, 'verify_peer_name' => true],
        ] : []);

        $stream = @stream_socket_client($address, $errorNumber, $errorMessage, $timeoutSeconds, STREAM_CLIENT_CONNECT, $context);
        if (!is_resource($stream)) {
            throw new RuntimeException("Could not connect to CSMS ({$errorMessage}).", $errorNumber);
        }

        $this->stream = $stream;
        stream_set_timeout($this->stream, $timeoutSeconds);

        try {
            $this->handshake($parts, $host, $port, $secure);
        } catch (Throwable $exception) {
            fclose($this->stream);
            throw $exception;
        }
    }

    public function send(string $message): void
    {
        $this->writeFrame(0x1, $message);
    }

    public function receive(): string
    {
        $fragmentedPayload = null;

        while (true) {
            $header = $this->readExact(2);
            $first = ord($header[0]);
            $second = ord($header[1]);
            $isFinal = ($first & 0x80) !== 0;
            $opcode = $first & 0x0f;
            $length = $second & 0x7f;

            if (($second & 0x80) !== 0) {
                throw new OcppProtocolException('CSMS WebSocket frames must be unmasked.');
            }

            if ($length === 126) {
                $length = unpack('n', $this->readExact(2))[1];
            } elseif ($length === 127) {
                $wideLength = unpack('Nhigh/Nlow', $this->readExact(8));
                if ($wideLength['high'] !== 0) {
                    throw new OcppProtocolException('CSMS WebSocket frame is too large.');
                }

                $length = $wideLength['low'];
            }

            if ($length > 1_048_576) {
                throw new OcppProtocolException('CSMS WebSocket frame exceeds the 1 MiB limit.');
            }

            $payload = $this->readExact($length);

            if ($opcode === 0x8) {
                throw new RuntimeException('CSMS closed the WebSocket connection.');
            }

            if ($opcode === 0x9) {
                if (!$isFinal || $length > 125) {
                    throw new OcppProtocolException('CSMS sent an invalid WebSocket ping frame.');
                }

                $this->writeFrame(0xA, $payload);
                continue;
            }

            if ($opcode === 0xA) {
                continue;
            }

            if ($opcode === 0x1) {
                if ($fragmentedPayload !== null) {
                    throw new OcppProtocolException('CSMS started a new message before completing a fragmented one.');
                }

                if ($isFinal) {
                    return $payload;
                }

                $fragmentedPayload = $payload;
                continue;
            }

            if ($opcode !== 0x0 || $fragmentedPayload === null) {
                throw new OcppProtocolException('Expected a text WebSocket message from the CSMS.');
            }

            $fragmentedPayload .= $payload;
            if (strlen($fragmentedPayload) > 1_048_576) {
                throw new OcppProtocolException('CSMS WebSocket message exceeds the 1 MiB limit.');
            }

            if ($isFinal) {
                return $fragmentedPayload;
            }
        }
    }

    private function handshake(array $parts, string $host, int $port, bool $secure): void
    {
        $key = base64_encode(random_bytes(16));
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $hostHeader = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $defaultPort = $secure ? 443 : 80;
        if ($port !== $defaultPort) {
            $hostHeader .= ':' . $port;
        }

        $request = "GET {$path} HTTP/1.1\r\n"
            . "Host: {$hostHeader}\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "Sec-WebSocket-Protocol: ocpp2.0.1\r\n\r\n";
        $this->writeAll($request);

        $status = fgets($this->stream);
        if (!is_string($status) || !preg_match('/^HTTP\/1\.\d 101\b/', $status)) {
            throw new RuntimeException('CSMS rejected the WebSocket upgrade.');
        }

        $headers = [];
        while (($line = fgets($this->stream)) !== false && $line !== "\r\n" && $line !== "\n") {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        $expectedAccept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if (($headers['sec-websocket-accept'] ?? '') !== $expectedAccept) {
            throw new RuntimeException('CSMS returned an invalid WebSocket accept key.');
        }

        if (($headers['sec-websocket-protocol'] ?? '') !== 'ocpp2.0.1') {
            throw new RuntimeException('CSMS did not select the ocpp2.0.1 subprotocol.');
        }
    }

    private function writeFrame(int $opcode, string $payload): void
    {
        $length = strlen($payload);
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

    private function readExact(int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($this->stream, $length - strlen($data));
            if (!is_string($chunk) || $chunk === '') {
                $meta = stream_get_meta_data($this->stream);
                throw new RuntimeException(($meta['timed_out'] ?? false) ? 'Timed out waiting for CSMS.' : 'CSMS closed the connection.');
            }

            $data .= $chunk;
        }

        return $data;
    }

    private function writeAll(string $data): void
    {
        $offset = 0;
        while ($offset < strlen($data)) {
            $written = fwrite($this->stream, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Could not write to the CSMS connection.');
            }

            $offset += $written;
        }
    }

    public function __destruct()
    {
        if (isset($this->stream) && is_resource($this->stream)) {
            fclose($this->stream);
        }
    }
}
