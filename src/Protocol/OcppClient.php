<?php

declare(strict_types=1);

namespace ChargerEmulator\Protocol;

use JsonException;

final class OcppClient
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @param array<string, mixed> $payload
     *  @return array<string, mixed>
     */
    public function request(string $action, array $payload): array
    {
        $messageId = bin2hex(random_bytes(16));
        $call = [2, $messageId, $action, $payload];

        try {
            $this->connection->send(json_encode($call, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $reply = json_decode($this->connection->receive(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new OcppProtocolException('The CSMS sent invalid JSON.', previous: $exception);
        }

        if (!is_array($reply) || count($reply) !== 3 || $reply[0] !== 3) {
            throw new OcppProtocolException('Expected an OCPP-J CALLRESULT response.');
        }
        if (!is_string($reply[1]) || $reply[1] !== $messageId) {
            throw new OcppProtocolException('The CALLRESULT uniqueId does not match the request.');
        }
        if (!is_array($reply[2])) {
            throw new OcppProtocolException('The CALLRESULT payload must be an object.');
        }

        return $reply[2];
    }
}
