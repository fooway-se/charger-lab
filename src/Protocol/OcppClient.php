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
    public function __construct(
        private readonly Connection $connection,
        private readonly ?Closure $exchangeObserver = null,
        private readonly ?Closure $incomingCallHandler = null,
        private readonly ?Closure $bootStatusProvider = null,
        private readonly ?Closure $incomingCallAfterResponse = null,
    ) {
    }

    public function request(string $action, array|\stdClass $requestPayload, ?string $messageId = null): array
    {
        $messageId ??= bin2hex(random_bytes(16));
        if ($messageId === '') {
            throw new OcppProtocolException('The OCPP-J message uniqueId cannot be empty.');
        }

        $callFrame = [2, $messageId, $action, $requestPayload];

        $callJson = json_encode($callFrame, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->connection->send($callJson);
        $this->observe('outgoing', $action, $callJson);
        $deferredIncomingCalls = [];

        while (true) {
            $responseJson = $this->connection->receive();
            $responseFrame = $this->decode($responseJson);
            if (is_array($responseFrame) && ($responseFrame[0] ?? null) === 2) {
                $this->answerIncomingCall($responseFrame, $responseJson, deferredIncomingCalls: $deferredIncomingCalls);
                continue;
            }

            $this->observe('incoming', $action, $responseJson);
            break;
        }

        if (!is_array($responseFrame) || !isset($responseFrame[0]) || !in_array($responseFrame[0], [3, 4], true)) {
            throw new OcppProtocolException('Expected an OCPP-J CALLRESULT or CALLERROR response.');
        }

        if (!is_string($responseFrame[1]) || $responseFrame[1] !== $messageId) {
            throw new OcppProtocolException('The OCPP-J response uniqueId does not match the request.');
        }

        if ($responseFrame[0] === 4) {
            if (count($responseFrame) !== 5 || !is_string($responseFrame[2] ?? null) || !is_string($responseFrame[3] ?? null)) {
                throw new OcppProtocolException('The OCPP-J CALLERROR response is malformed.');
            }

            $this->runDeferredIncomingCalls($deferredIncomingCalls);

            throw new OcppProtocolException("CSMS returned OCPP-J CALLERROR {$responseFrame[2]}: {$responseFrame[3]}");
        }

        if (count($responseFrame) !== 3) {
            throw new OcppProtocolException('The OCPP-J CALLRESULT frame is malformed.');
        }

        if (!is_array($responseFrame[2])) {
            throw new OcppProtocolException('The CALLRESULT payload must be an object.');
        }

        $this->runDeferredIncomingCalls($deferredIncomingCalls);

        return $responseFrame[2];
    }

    public function answerIncomingCallMessage(string $callJson, ?string $bootStatus = null): void
    {
        $this->answerIncomingCall($this->decode($callJson), $callJson, $bootStatus);
    }

    private function answerIncomingCall(
        mixed $callFrame,
        string $callJson,
        ?string $bootStatus = null,
        ?array &$deferredIncomingCalls = null,
    ): void
    {
        if (!is_array($callFrame) || count($callFrame) !== 4 || ($callFrame[0] ?? null) !== 2
            || !is_string($callFrame[1] ?? null) || !is_string($callFrame[2] ?? null) || !is_array($callFrame[3] ?? null)) {
            throw new OcppProtocolException('The CSMS sent a malformed OCPP-J CALL.');
        }

        $action = $callFrame[2];
        $requestPayload = $callFrame[3];
        $this->observe('incoming', $action, $callJson);
        $bootStatus ??= $this->bootStatusProvider === null ? null : ($this->bootStatusProvider)();
        $rejected = $bootStatus === 'Rejected';
        $callResult = $rejected
            ? null
            : ($this->incomingCallHandler === null ? null : ($this->incomingCallHandler)($action, $requestPayload));
        if (is_array($callResult)) {
            $responseJson = json_encode([3, $callFrame[1], $callResult], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } else {
            $responseJson = json_encode([
                4,
                $callFrame[1],
                $rejected ? 'SecurityError' : 'NotImplemented',
                $rejected
                    ? 'The charging station has not been accepted by the CSMS.'
                    : 'Action not supported by this emulator: ' . $action,
                new \stdClass(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        $this->connection->send($responseJson);
        $this->observe('outgoing', $action, $responseJson);
        if (is_array($callResult) && $this->incomingCallAfterResponse !== null) {
            if ($deferredIncomingCalls === null) {
                ($this->incomingCallAfterResponse)($action, $requestPayload, $callResult);
            } else {
                $deferredIncomingCalls[] = [$action, $requestPayload, $callResult];
            }
        }
    }

    private function decode(string $ocppJson): mixed
    {
        try {
            return json_decode($ocppJson, true, flags: JSON_THROW_ON_ERROR);
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

    private function runDeferredIncomingCalls(array $deferredIncomingCalls): void
    {
        if ($this->incomingCallAfterResponse === null) {
            return;
        }

        foreach ($deferredIncomingCalls as [$action, $requestPayload, $callResult]) {
            ($this->incomingCallAfterResponse)($action, $requestPayload, $callResult);
        }
    }
}
