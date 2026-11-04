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

    private string $readBuffer = '';

    private ?string $fragmentedPayload = null;

    private bool $streamClosed = false;

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
            stream_set_blocking($this->stream, false);
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
        while (true) {
            $message = $this->receiveAvailable();
            if ($message !== null) {
                return $message;
            }

            $read = [$this->stream];
            $write = [];
            $except = [];
            $ready = stream_select($read, $write, $except, $this->timeoutSeconds);
            if ($ready === false) {
                throw new RuntimeException('Could not wait for a CSMS WebSocket frame.');
            }
            if ($ready === 0) {
                throw new RuntimeException('Timed out waiting for CSMS.');
            }
        }
    }

    public function receiveAvailable(): ?string
    {
        while (true) {
            $chunk = fread($this->stream, 8192);
            if ($chunk === false) {
                throw new RuntimeException('Could not read from the CSMS WebSocket.');
            }
            if ($chunk !== '') {
                $this->readBuffer .= $chunk;
            }
            if (feof($this->stream)) {
                $this->streamClosed = true;
            }
            if ($chunk === '') {
                break;
            }
        }

        while (true) {
            if (strlen($this->readBuffer) < 2) {
                if ($this->streamClosed) {
                    throw new RuntimeException('CSMS closed the connection.');
                }

                return null;
            }

            $first = ord($this->readBuffer[0]);
            $second = ord($this->readBuffer[1]);
            $isFinal = ($first & 0x80) !== 0;
            $opcode = $first & 0x0f;
            $isMasked = ($second & 0x80) !== 0;
            $length = $second & 0x7f;
            $headerLength = 2;
            if ($length === 126) {
                $headerLength += 2;
                if (strlen($this->readBuffer) < $headerLength) {
                    return $this->throwIfClosedOrReturnNull();
                }

                $length = unpack('n', substr($this->readBuffer, 2, 2))[1];
            } elseif ($length === 127) {
                $headerLength += 8;
                if (strlen($this->readBuffer) < $headerLength) {
                    return $this->throwIfClosedOrReturnNull();
                }

                $wideLength = unpack('Nhigh/Nlow', substr($this->readBuffer, 2, 8));
                if ($wideLength['high'] !== 0) {
                    throw new OcppProtocolException('CSMS WebSocket frame is too large.');
                }

                $length = $wideLength['low'];
            }

            if ($isMasked) {
                throw new OcppProtocolException('CSMS WebSocket frames must be unmasked.');
            }
            if ($length > 1_048_576) {
                throw new OcppProtocolException('CSMS WebSocket frame exceeds the 1 MiB limit.');
            }
            if (strlen($this->readBuffer) < $headerLength + $length) {
                return $this->throwIfClosedOrReturnNull();
            }

            $payload = substr($this->readBuffer, $headerLength, $length);
            $this->readBuffer = substr($this->readBuffer, $headerLength + $length);
            if ($opcode === 0x8) {
                $this->writeFrame(0x8, $payload);
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
                if ($this->fragmentedPayload !== null) {
                    throw new OcppProtocolException('CSMS started a new message before completing a fragmented one.');
                }
                if ($isFinal) {
                    return $payload;
                }

                $this->fragmentedPayload = $payload;
                continue;
            }
            if ($opcode !== 0x0 || $this->fragmentedPayload === null) {
                throw new OcppProtocolException('Expected a text WebSocket message from the CSMS.');
            }

            $this->fragmentedPayload .= $payload;
            if (strlen($this->fragmentedPayload) > 1_048_576) {
                throw new OcppProtocolException('CSMS WebSocket message exceeds the 1 MiB limit.');
            }
            if ($isFinal) {
                $message = $this->fragmentedPayload;
                $this->fragmentedPayload = null;

                return $message;
            }
        }
    }

    private function throwIfClosedOrReturnNull(): ?string
    {
        if ($this->streamClosed) {
            throw new RuntimeException('CSMS closed in the middle of a WebSocket frame.');
        }

        return null;
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

    private function writeAll(string $data): void
    {
        $offset = 0;
        while ($offset < strlen($data)) {
            $written = fwrite($this->stream, substr($data, $offset));
            if ($written === false) {
                throw new RuntimeException('Could not write to the CSMS connection.');
            }
            if ($written === 0) {
                $read = [];
                $write = [$this->stream];
                $except = [];
                if (stream_select($read, $write, $except, $this->timeoutSeconds) !== 1) {
                    throw new RuntimeException('Timed out writing to the CSMS connection.');
                }

                continue;
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
