<?php

declare(strict_types=1);

namespace ChargerEmulator\Protocol;

/**
 * Defines the text-message transport used by the OCPP client.
 */
interface Connection
{
    public function send(string $message): void;

    public function receive(): string;
}
