<?php

declare(strict_types=1);

namespace ChargerEmulator\Domain;

interface LogUploader
{
    public function upload(string $remoteLocation, string $filename, string $contents): bool;
}
