<?php

declare(strict_types=1);

namespace Ajo;

use Ajo\Core\Http as Root;

/**
 * HTTP facade for web applications.
 *
 * @mixin Root
 *
 * @method static Root create(?callable $notFoundHandler = null, ?callable $exceptionHandler = null)
 * @method static Root use(string|callable $path, callable ...$handlers)
 * @method static Root map(array|string $methods, string $path, callable ...$handlers)
 * @method static Root get(string $path, callable ...$handlers)
 * @method static Root post(string $path, callable ...$handlers)
 * @method static Root put(string $path, callable ...$handlers)
 * @method static Root patch(string $path, callable ...$handlers)
 * @method static Root delete(string $path, callable ...$handlers)
 * @method static Root options(string $path, callable ...$handlers)
 * @method static Root head(string $path, callable ...$handlers)
 * @method static void dispatch(?string $method = null, ?string $target = null)
 *
 * Usage patterns:
 *
 * 1. Static usage (simple):
 *    Http::get('/api/users', fn() => ['users' => []]);
 *    Http::post('/api/users', fn() => ['created' => true]);
 *    Http::dispatch();
 *
 * 2. Custom handlers:
 *    Http::swap(Http::create(
 *        notFoundHandler: fn() => ['error' => 'not_found', 'status' => 404],
 *        exceptionHandler: fn($e) => ['error' => $e->getMessage(), 'status' => 500]
 *    ));
 *    Http::get('/api/users', fn() => ['users' => []]);
 *
 * 3. Explicit instance (for testing):
 *    $http = Http::create();
 *    $http->get('/api/users', fn() => ['users' => []]);
 *    $http->dispatch();
 */
final class Http extends Router
{
    protected static function root(): string
    {
        return Root::class;
    }
}
