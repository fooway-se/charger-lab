<?php

declare(strict_types=1);

namespace ChargerEmulator\Http;

/**
 * Holds an HTTP status, headers, and body until the entry point sends it.
 */
final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function json(int $status, array $data): self
    {
        return new self(
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'],
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    public static function text(int $status, string $body): self
    {
        return new self($status, ['Content-Type' => 'text/plain; charset=utf-8'], $body);
    }

    public function send(): never
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        echo $this->body;
        exit;
    }
}
