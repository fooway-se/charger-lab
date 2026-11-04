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
     * @param null|Closure(string, string): void $exchangeObserver Receives direction and raw OCPP-J JSON.
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly ?Closure $exchangeObserver = null,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *  @return array<string, mixed>
     */
    public function request(string $action, array $payload): array
    {
        $messageId = bin2hex(random_bytes(16));
        $call = [2, $messageId, $action, $payload];

        try {
            $outgoing = json_encode($call, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $this->connection->send($outgoing);
            if ($this->exchangeObserver !== null) {
                ($this->exchangeObserver)('outgoing', $outgoing);
            }

            $incoming = $this->connection->receive();
            if ($this->exchangeObserver !== null) {
                ($this->exchangeObserver)('incoming', $incoming);
            }

            $reply = json_decode($incoming, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new OcppProtocolException('The CSMS sent invalid JSON.', previous: $exception);
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
}
