<?php

declare(strict_types=1);

namespace Ajo\Core;

use Ajo\Tests\Unit\Http\TestHarness;

function http_response_code(?int $code = null)
{
    if ($code !== null) {
        TestHarness::$status = $code;
    }

    return TestHarness::$status ?? 200;
}

function header(string $header, bool $replace = true, ?int $response_code = null)
{
    TestHarness::$headers[] = [$header, $replace, $response_code];

    if ($response_code !== null) {
        TestHarness::$status = $response_code;
    }
}

namespace Ajo\Tests\Unit\Http;

use Ajo\Core\Http as Root;
use Ajo\Http;
use Ajo\Test;
use BadMethodCallException;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

final class TestHarness
{
    /** @var array<int, array{0:string,1:bool,2:?int}> */
    public static array $headers = [];
    public static ?int $status = null;

    public static function reset()
    {
        self::$headers = [];
        self::$status = null;
    }
}

Test::suite('Http', function () {

    Test::beforeEach(function () {
        TestHarness::reset();
    });

    Test::it('should return http instance on create', function () {
        Test::assertInstanceOf(Root::class, Http::create());
    });

    Test::it('should run global middleware before route handler', function () {

        $http = Http::create();
        $events = [];

        $http->use(function () use (&$events) {
            $events[] = 'middleware';
            return null;
        });

        $http->get('/foo', function () use (&$events) {
            $events[] = 'handler';
            return ['ok' => true];
        });

        [$output, $status, $headers] = dispatch($http, 'GET', '/foo');
        $payload = decode($output);

        Test::assertSame(['middleware', 'handler'], $events);
        Test::assertSame(200, $status);
        Test::assertTrue(in_array('Content-Type: application/json; charset=utf-8', array_column($headers, 0), true));
        Test::assertSame(['ok' => true], $payload);
    });

    Test::it('should run path specific middleware only when prefix matches', function () {

        $http = Http::create();
        $events = [];

        $http->use('/api', function () use (&$events) {
            $events[] = 'mw';
            return null;
        });

        $http->get('/api/users', fn() => ['ok' => true]);
        $http->get('/status', fn() => ['ok' => true]);

        [$firstOutput] = dispatch($http, 'GET', '/api/users');
        Test::assertSame(['mw'], $events);
        Test::assertSame(['ok' => true], decode($firstOutput));

        $events = [];
        [$secondOutput] = dispatch($http, 'GET', '/status');
        Test::assertSame([], $events);
        Test::assertSame(['ok' => true], decode($secondOutput));
    });

    Test::it('should register handlers for multiple methods', function () {

        $http = Http::create();

        $http->map(['GET', 'POST'], '/resource', fn() => ['ok' => true]);

        [$getOutput, $getStatus] = dispatch($http, 'GET', '/resource');
        [$postOutput, $postStatus] = dispatch($http, 'POST', '/resource');

        Test::assertSame(200, $getStatus);
        Test::assertSame(200, $postStatus);
        Test::assertSame(decode($getOutput), decode($postOutput));
    });

    Test::it('should fail when map called without handlers', function () {

        $http = Http::create();

        Test::expectException(InvalidArgumentException::class, function () use ($http) {
            $http->map('GET', '/oops');
        });
    });

    Test::it('should register route via dynamic method', function () {

        $http = Http::create();

        $http->post('/submit', fn() => ['posted' => true]);

        [$output, $status] = dispatch($http, 'POST', '/submit');

        Test::assertSame(200, $status);
        Test::assertSame(['posted' => true], decode($output));
    });

    Test::it('should throw when dynamic method uses unsupported verb', function () {

        $http = Http::create();

        Test::expectException(BadMethodCallException::class, function () use ($http) {
            $http->foo('/invalid', fn() => null);
        });
    });

    Test::it('should use default not found handler', function () {

        $http = Http::create();

        [$output, $status] = dispatch($http, 'GET', '/missing');
        $payload = decode($output);

        Test::assertSame(404, $status);
        Test::assertFalse($payload['ok']);
        Test::assertSame('not_found', $payload['error']);
    });

    Test::it('should return 204 when handler yields null', function () {

        $http = Http::create();

        $http->get('/empty', fn() => null);

        [$output, $status] = dispatch($http, 'GET', '/empty');

        Test::assertSame('', $output);
        Test::assertSame(204, $status);
    });

    Test::it('should use custom exception handler', function () {

        $caught = null;
        $http = new Root(
            null,
            function (Throwable $throwable) use (&$caught): array {
                $caught = $throwable;

                return ['ok' => false, 'status' => 503, 'message' => $throwable->getMessage()];
            },
        );

        $http->get('/boom', function () {
            throw new RuntimeException('boom');
        });

        [$output, $status] = dispatch($http, 'GET', '/boom');
        $payload = decode($output);

        Test::assertSame(503, $status);
        Test::assertSame('boom', $payload['message']);
        Test::assertInstanceOf(RuntimeException::class, $caught);
    });

    Test::it('should fall back to get handler', function () {

        $http = Http::create();

        $http->get('/resource', fn() => ['ok' => true]);

        [$output, $status] = dispatch($http, 'HEAD', '/resource');

        Test::assertSame(['ok' => true], decode($output));
        Test::assertSame(200, $status);
    });

    Test::it('should infer method and target from server globals', function () {

        $http = Http::create();
        $http->get('/auto', fn() => ['auto' => true]);

        $backup = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = 'get';
        $_SERVER['REQUEST_URI'] = '/auto';

        try {
            TestHarness::reset();
            ob_start();
            $http->dispatch();
            $output = ob_get_clean() ?: '';
        } finally {
            $_SERVER = $backup;
        }

        $payload = decode($output);

        Test::assertSame(['auto' => true], $payload);
        Test::assertSame(200, TestHarness::$status);
    });

    Test::it('should handle JSON encoding errors', function () {

        $http = Http::create();

        $http->get('/invalid', fn() => ['value' => \NAN]);

        [$output, $status] = dispatch($http, 'GET', '/invalid');
        $payload = decode($output);

        Test::assertSame(500, $status);
        Test::assertSame(['ok' => false, 'error' => 'encoding_error'], $payload);
    });

    Test::it('should evaluate nested paths in matches', function () {

        $http = Http::create();
        $reflection = new \ReflectionClass($http);
        $method = $reflection->getMethod('matches');
        $method->setAccessible(true);

        Test::assertTrue($method->invoke($http, '/api', '/api/users'));
        Test::assertFalse($method->invoke($http, '/api', '/status'));
    });

    Test::it('should run for nested path prefixes', function () {

        $http = Http::create();
        $events = [];

        $http->use('/api', function () use (&$events) {
            $events[] = 'mw';
            return null;
        });

        $http->get('/api/nested/item', function () use (&$events) {
            $events[] = 'handler';
            return ['ok' => true];
        });

        [$output] = dispatch($http, 'GET', '/api/nested/item');
        $payload = decode($output);

        Test::assertSame(['mw', 'handler'], $events);
        Test::assertSame(['ok' => true], $payload);
    });

    Test::it('should match exact route for path middleware', function () {

        $http = Http::create();
        $events = [];

        $http->use('/status', function () use (&$events) {
            $events[] = 'mw';
            return null;
        });

        $http->get('/status', function () use (&$events) {
            $events[] = 'handler';
            return ['ok' => true];
        });

        [$output] = dispatch($http, 'GET', '/status');
        $payload = decode($output);

        Test::assertSame(['mw', 'handler'], $events);
        Test::assertSame(['ok' => true], $payload);
    });
});

function dispatch(Root $http, string $method, string $target)
{
    TestHarness::reset();

    ob_start();
    $http->dispatch($method, $target);
    $output = ob_get_clean() ?: '';

    return [$output, TestHarness::$status ?? 200, TestHarness::$headers];
}

function decode(string $payload)
{
    try {
        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        Test::fail('Invalid JSON payload: ' . $exception->getMessage());
        return [];
    }

    return $decoded;
}
