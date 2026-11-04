<?php

declare(strict_types=1);

namespace ChargerEmulator\Protocol;

interface Connection
{
    public function send(string $message): void;

    public function receive(): string;
}
