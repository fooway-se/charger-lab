<?php

declare(strict_types=1);

namespace ChargerEmulator\Http;

use InvalidArgumentException;
use JsonException;

/**
 * Carries normalized HTTP request data into application routes.
 */
final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $form
     * @param array<string, mixed>|null $json
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $form,
        public readonly ?array $json = null,
    ) {
    }

    public static function fromGlobals(): self
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        $json = null;

        if ($method !== 'GET' && str_starts_with($contentType, 'application/json')) {
            try {
                $decoded = json_decode((string) file_get_contents('php://input'), true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new InvalidArgumentException('Request body must contain valid JSON.', previous: $exception);
            }

            if (!is_array($decoded)) {
                throw new InvalidArgumentException('Request JSON must be an object.');
            }

            $json = $decoded;
        }

        return new self(
            $method,
            is_string($path) && $path !== '' ? $path : '/',
            $_GET,
            $_POST,
            $json,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return $this->json ?? $this->form;
    }
}
