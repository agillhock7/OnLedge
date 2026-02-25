<?php

declare(strict_types=1);

namespace App\Router;

use App\Helpers\HttpException;

final class Router
{
    /** @var array<string, array<int, array{regex: string, handler: callable, params: array<int, string>}>> */
    private array $routes = [];

    public function __construct(private readonly string $basePath = '')
    {
    }

    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function put(string $path, callable $handler): void
    {
        $this->add('PUT', $path, $handler);
    }

    public function delete(string $path, callable $handler): void
    {
        $this->add('DELETE', $path, $handler);
    }

    public function dispatch(string $method, string $path): void
    {
        $cleanPath = $this->normalizePath($path);
        $collection = $this->routes[$method] ?? [];

        foreach ($collection as $route) {
            if (preg_match($route['regex'], $cleanPath, $matches) !== 1) {
                continue;
            }

            $params = [];
            foreach ($route['params'] as $index => $name) {
                $params[$name] = $matches[$index + 1] ?? null;
            }

            ($route['handler'])($params);
            return;
        }

        throw new HttpException('Route not found', 404);
    }

    private function add(string $method, string $path, callable $handler): void
    {
        $params = [];
        $regex = preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', static function (array $matches) use (&$params): string {
            $params[] = $matches[1];
            return '([^/]+)';
        }, $path);

        $this->routes[$method][] = [
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
            'params' => $params,
        ];
    }

    private function normalizePath(string $path): string
    {
        $normalized = '/' . trim($path, '/');
        if ($normalized === '/index.php') {
            $normalized = '/';
        }

        if ($this->basePath !== '' && str_starts_with($normalized, $this->basePath)) {
            $normalized = substr($normalized, strlen($this->basePath));
            $normalized = '/' . ltrim($normalized ?: '/', '/');
        }

        return $normalized === '' ? '/' : $normalized;
    }
}
