<?php

declare(strict_types=1);

namespace ChargerEmulator\Http;

use Closure;

/**
 * Dispatches exact method and path pairs to small route handlers.
 */
final class Router
{
    /**
     * @var array<string, array<string, Closure(Request): Response>>
     */
    private array $routes = [];

    public function add(string $method, string $path, callable $handler): void
    {
        $this->routes[strtoupper($method)][$path] = Closure::fromCallable($handler);
    }

    public function dispatch(Request $request): Response
    {
        $handler = $this->routes[$request->method][$request->path] ?? null;
        if ($handler === null) {
            return Response::json(404, ['error' => 'Route not found.']);
        }

        return $handler($request);
    }
}
