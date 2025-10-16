<?php

declare(strict_types=1);

namespace Ajo;

use BadMethodCallException;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * Router sin dependencias con middlewares, coincidencia por prefijo y helpers JSON.
 *
 * Puntos clave:
 * - `->use($prefix, ...) ` registra middlewares antes de las rutas (globales si se usa sin prefijo).
 * - Métodos dinámicos (`$http->get`, `$http->post`, etc.) para definir rutas por método.
 * - Si devolvés `['status' => 404]` el código HTTP se ajusta automáticamente; `null` produce un `204`.
 * - Los errores no manejados caen en `defaultException()` y responden JSON consistente.
 *
 * ```php
 * $http = new Http();
 * $http->use('/', fn () => Context::set('tenant', $_SERVER['HTTP_X_TENANT'] ?? 'default'));
 *
 * $http->use('/admin', function (callable $next) {
 *     if (!isset($_SERVER['HTTP_X_AUTH']) || $_SERVER['HTTP_X_AUTH'] !== getenv('ADMIN_TOKEN')) {
 *         return ['ok' => false, 'error' => 'unauthorized', 'status' => 401];
 *     }
 * });
 *
 * $http->get('/admin/health', fn () => ['ok' => true, 'uptime' => uptime()]);
 *
 * $http->post('/webhooks/facturante', function () {
 *     try {
 *         handleWebhook(file_get_contents('php://input'));
 *         return ['ok' => true];
 *     } catch (Throwable $e) {
 *         return ['ok' => false, 'error' => $e->getMessage(), 'status' => 422];
 *     }
 * });
 *
 * $http->map(['PUT', 'PATCH'], '/charges/{id}', function () {
 *     return ['ok' => true, 'status' => 202];
 * });
 *
 * $http->dispatch();
 * ```
 */
final class Http extends Router
{
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];

    /** @var array<string, array<string, array<int, callable>>> */
    private array $routes = [];

    /**
     * Registra una ruta en el enrutador.
     */
    public function map(array|string $methods, string $path, callable ...$handlers)
    {
        if ($handlers === []) {
            throw new InvalidArgumentException('Definir una ruta requiere al menos un manejador.');
        }

        $wrapped = array_map(fn($handler) => $this->adapt($handler), $handlers);

        foreach ((array)$methods as $method) {
            $method = strtoupper($method);
            $normalized = $this->normalize($path);
            $this->routes[$method][$normalized] ??= [];
            array_push($this->routes[$method][$normalized], ...$wrapped);
        }

        return $this;
    }

    public function __call(string $name, array $arguments)
    {
        $method = strtoupper($name);

        if (!in_array($method, self::METHODS, true)) {
            throw new BadMethodCallException("Método '{$name}' no soportado.");
        }

        return $this->map($method, ...$arguments);
    }

    /**
     * Detecta y ejecuta la ruta correspondiente
     */
    public function dispatch(?string $method = null, ?string $target = null)
    {
        $method = strtoupper($method ?? $_SERVER['REQUEST_METHOD'] ?? 'GET');
        $normalized = $this->normalize($target ?? $_SERVER['REQUEST_URI'] ?? '/');

        try {
            $response = $this->run($this->build($method, $normalized));
        } catch (Throwable $throwable) {
            $response = ($this->exceptionHandler)($throwable);
        }

        $this->respond($response);
    }

    private function build(string $method, string $path)
    {
        $handlers = $this->routes[$method][$path] ?? [];

        if ($method === 'HEAD' && $handlers === []) {
            $handlers = $this->routes['GET'][$path] ?? [];
        }

        if ($handlers === []) {
            $handlers[] = $this->notFoundHandler;
        }

        return [...$this->stack($path), ...$handlers];
    }

    private function respond(mixed $response)
    {
        if ($response === null) {
            http_response_code(204);
            return;
        }

        $status = 200;

        if (is_array($response) && isset($response['status'])) {
            $status = (int)$response['status'];
        }

        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        try {
            echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'error_codificacion'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
    }

    protected function defaultNotFound(): callable
    {
        return fn() => ['ok' => false, 'error' => 'no_encontrado', 'status' => 404];
    }

    protected function defaultException(): callable
    {
        return fn(Throwable $e) => ['ok' => false, 'error' => 'error_interno', 'message' => $e->getMessage(), 'status' => 500];
    }

    protected function matches(string $prefix, string $path): bool
    {
        if ($prefix === '' || $path === $prefix) {
            return true;
        }

        return str_starts_with($path, $prefix . '/');
    }

    protected function normalize(string $path): string
    {
        $parsed = parse_url($path, PHP_URL_PATH) ?: '/';
        $parsed = $parsed === '' ? '/' : $parsed;
        $parsed = preg_replace('#/+#', '/', str_starts_with($parsed, '/') ? $parsed : '/' . $parsed) ?: '/';

        return $parsed !== '/' ? rtrim($parsed, '/') : '/';
    }
}
