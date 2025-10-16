<?php

declare(strict_types=1);

namespace Ajo\Tests\Unit\Router;

use ArrayObject;
use InvalidArgumentException;
use Ajo\Router;
use Ajo\Test;
use RuntimeException;
use Throwable;

final class TestHarness extends Router
{
    public function exposeAdapt(callable $handler): callable
    {
        return $this->adapt($handler);
    }

    public function runPipeline(array $handlers): mixed
    {
        return $this->run($handlers);
    }

    public function stackFor(string $target): array
    {
        return $this->stack($target);
    }

    public function runEmpty(?Throwable $error = null): mixed
    {
        return $this->run([], 0, $error);
    }

    protected function matches(string $prefix, string $target): bool
    {
        return $prefix === '*' || $target === $prefix || str_starts_with($target, $prefix . '/');
    }

    protected function normalize(string $path): string
    {
        $trimmed = trim($path, '/');
        return $trimmed === '' ? '' : $trimmed;
    }

    protected function defaultNotFound(): callable
    {
        return fn() => 'not-found';
    }

    protected function defaultException(): callable
    {
        return fn(Throwable $exception) => 'exception:' . $exception->getMessage();
    }
}

Test::suite('Router', function (Test $t) {

    $t->beforeEach(function (ArrayObject $state) {
        $state['router'] = TestHarness::create();
    });

    $t->test('create returns new instance', function (ArrayObject $state) {
        Test::assertInstanceOf(TestHarness::class, $state['router']);
    });

    $t->test('use throws when no handlers provided', function (ArrayObject $state) {
        Test::expectException(InvalidArgumentException::class, function () use ($state) {
            $state['router']->use('/path');
        });
    });

    $t->test('global middleware runs before handler', function (ArrayObject $state) {

        $sequence = [];

        $state['router']->use(function () use (&$sequence) {
            $sequence[] = 'middleware';
            return null;
        });

        $handler = $state['router']->exposeAdapt(function () use (&$sequence) {
            $sequence[] = 'handler';
            return 'done';
        });

        $result = $state['router']->runPipeline([...$state['router']->stackFor('any'), $handler]);

        Test::assertSame(['middleware', 'handler'], $sequence);
        Test::assertSame('done', $result);
    });

    $t->test('prefixed middleware matches formatted path', function (ArrayObject $state) {

        $sequence = [];

        $state['router']->use('api', function () use (&$sequence) {
            $sequence[] = 'api';
            return null;
        });

        $handler = $state['router']->exposeAdapt(function () use (&$sequence) {
            $sequence[] = 'handler';
            return 'ok';
        });

        $result = $state['router']->runPipeline([...$state['router']->stackFor('api/resource'), $handler]);

        Test::assertSame(['api', 'handler'], $sequence);
        Test::assertSame('ok', $result);

        $sequence = [];
        $result = $state['router']->runPipeline([...$state['router']->stackFor('status'), $handler]);

        Test::assertSame(['handler'], $sequence);
        Test::assertSame('ok', $result);
    });

    $t->test('middleware with next continues pipeline', function (ArrayObject $state) {

        $sequence = [];

        $state['router']->use(function (callable $next) use (&$sequence) {
            $sequence[] = 'mw';
            return $next();
        });

        $handler = $state['router']->exposeAdapt(function () use (&$sequence) {
            $sequence[] = 'handler';
            return 'ok';
        });

        $result = $state['router']->runPipeline([...$state['router']->stackFor('anything'), $handler]);

        Test::assertSame(['mw', 'handler'], $sequence);
        Test::assertSame('ok', $result);
    });

    $t->test('error handler with throwable parameter handles exception', function (ArrayObject $state) {

        $sequence = [];

        $thrower = $state['router']->exposeAdapt(function () {
            throw new RuntimeException('failed');
        });

        $errorHandler = $state['router']->exposeAdapt(function (Throwable $error) use (&$sequence) {
            $sequence[] = $error->getMessage();
            return 'recovered';
        });

        $result = $state['router']->runPipeline([$thrower, $errorHandler]);

        Test::assertSame(['failed'], $sequence);
        Test::assertSame('recovered', $result);
    });

    $t->test('error handler with two parameters runs', function (ArrayObject $state) {

        $sequence = [];

        $thrower = $state['router']->exposeAdapt(function () {
            throw new RuntimeException('boom');
        });

        $errorHandler = $state['router']->exposeAdapt(function (Throwable $error, callable $next) use (&$sequence) {
            $sequence[] = 'two:' . $error->getMessage();
            return 'handled';
        });

        $result = $state['router']->runPipeline([$thrower, $errorHandler]);

        Test::assertSame(['two:boom'], $sequence);
        Test::assertSame('handled', $result);
    });

    $t->test('adapt rejects invalid error handler signature', function (ArrayObject $state) {
        Test::expectException(InvalidArgumentException::class, function () use ($state) {
            $state['router']->exposeAdapt(function ($error, $next) {});
        });
    });

    $t->test('adapt rejects handlers with more than two parameters', function (ArrayObject $state) {
        Test::expectException(InvalidArgumentException::class, function () use ($state) {
            $state['router']->exposeAdapt(function ($a, $b, $c) {});
        });
    });

    $t->test('union type error handler receives throwable', function (ArrayObject $state) {

        $sequence = [];

        $thrower = $state['router']->exposeAdapt(function () {
            throw new RuntimeException('union');
        });

        $errorHandler = $state['router']->exposeAdapt(function (RuntimeException|Throwable $error, callable $next) use (&$sequence) {
            $sequence[] = $error->getMessage();
            return 'union-handled';
        });

        $result = $state['router']->runPipeline([$thrower, $errorHandler]);

        Test::assertSame(['union'], $sequence);
        Test::assertSame('union-handled', $result);
    });

    $t->test('middleware skips execution when error propagates', function (ArrayObject $state) {

        $sequence = [];

        $thrower = $state['router']->exposeAdapt(function () {
            throw new RuntimeException('skip');
        });

        $middleware = $state['router']->exposeAdapt(function (callable $next) use (&$sequence) {
            $sequence[] = 'middleware';
            return $next();
        });

        $errorHandler = $state['router']->exposeAdapt(function (Throwable $error) {
            return 'handled';
        });

        $result = $state['router']->runPipeline([$thrower, $middleware, $errorHandler]);

        Test::assertSame([], $sequence);
        Test::assertSame('handled', $result);
    });

    $t->test('zero parameter handler forwards errors', function (ArrayObject $state) {
        $sequence = [];

        $thrower = $state['router']->exposeAdapt(function () {
            throw new RuntimeException('zero');
        });

        $zero = $state['router']->exposeAdapt(function () use (&$sequence) {
            $sequence[] = 'zero';
            return 'ok';
        });

        $errorHandler = $state['router']->exposeAdapt(function (Throwable $error) {
            return 'handled';
        });

        $result = $state['router']->runPipeline([$thrower, $zero, $errorHandler]);

        Test::assertSame([], $sequence);
        Test::assertSame('handled', $result);
    });

    $t->test('run returns null when stack empty', function (ArrayObject $state) {
        Test::assertNull($state['router']->runEmpty());
    });

    $t->test('run rethrows error when unhandled', function (ArrayObject $state) {
        Test::expectException(RuntimeException::class, function () use ($state) {
            $state['router']->runEmpty(new RuntimeException('unhandled'));
        });
    });
});
