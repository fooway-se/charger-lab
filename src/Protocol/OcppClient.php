<?php

declare(strict_types=1);

namespace ChargerEmulator\Protocol;

use Closure;
use JsonException;

/**
 * Sends OCPP-J calls and validates their correlated results.
 */
final class OcppClient
{
    /**
     * @param null|Closure(string, string, string): void $exchangeObserver Receives direction, action, and raw OCPP-J JSON.
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly ?Closure $exchangeObserver = null,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function request(string $action, array $payload): array
    {
        $messageId = bin2hex(random_bytes(16));
        $call = [2, $messageId, $action, $payload];

        $outgoing = json_encode($call, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->connection->send($outgoing);
        $this->observe('outgoing', $action, $outgoing);

        while (true) {
            $incoming = $this->connection->receive();
            $reply = $this->decode($incoming);
            if (is_array($reply) && ($reply[0] ?? null) === 2) {
                $this->answerIncomingCall($reply, $incoming);
                continue;
            }

            $this->observe('incoming', $action, $incoming);
            break;
        }

        if (!is_array($reply) || !isset($reply[0]) || !in_array($reply[0], [3, 4], true)) {
            throw new OcppProtocolException('Expected an OCPP-J CALLRESULT or CALLERROR response.');
        }

        if (!is_string($reply[1]) || $reply[1] !== $messageId) {
            throw new OcppProtocolException('The OCPP-J response uniqueId does not match the request.');
        }

        if ($reply[0] === 4) {
            if (count($reply) !== 5 || !is_string($reply[2] ?? null) || !is_string($reply[3] ?? null)) {
                throw new OcppProtocolException('The OCPP-J CALLERROR response is malformed.');
            }

            throw new OcppProtocolException("CSMS returned OCPP-J CALLERROR {$reply[2]}: {$reply[3]}");
        }

        if (count($reply) !== 3) {
            throw new OcppProtocolException('The OCPP-J CALLRESULT frame is malformed.');
        }

        if (!is_array($reply[2])) {
            throw new OcppProtocolException('The CALLRESULT payload must be an object.');
        }

        return $reply[2];
    }

    public function answerIncomingCallMessage(string $message): void
    {
        $this->answerIncomingCall($this->decode($message), $message);
    }

    /**
     * @param mixed $reply
     */
    private function answerIncomingCall(mixed $reply, string $message): void
    {
        if (!is_array($reply) || count($reply) !== 4 || ($reply[0] ?? null) !== 2
            || !is_string($reply[1] ?? null) || !is_string($reply[2] ?? null) || !is_array($reply[3] ?? null)) {
            throw new OcppProtocolException('The CSMS sent a malformed OCPP-J CALL.');
        }

        $action = $reply[2];
        $this->observe('incoming', $action, $message);
        $error = json_encode([
            4,
            $reply[1],
            'NotImplemented',
            'Action not supported by this emulator: ' . $action,
            new \stdClass(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->connection->send($error);
        $this->observe('outgoing', $action, $error);
    }

    /**
     * @return mixed
     */
    private function decode(string $message): mixed
    {
        try {
            return json_decode($message, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new OcppProtocolException('The CSMS sent invalid JSON.', previous: $exception);
        }
    }

    private function observe(string $direction, string $action, string $message): void
    {
        if ($this->exchangeObserver !== null) {
            ($this->exchangeObserver)($direction, $action, $message);
        }
    }
}
