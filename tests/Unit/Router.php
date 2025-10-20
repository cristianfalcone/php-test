<?php

declare(strict_types=1);

namespace Ajo\Tests\Unit\Router;

use Ajo\Core\Router as CoreRouter;
use Ajo\Test;
use RuntimeException;
use Throwable;
use InvalidArgumentException;

final class TestHarness extends CoreRouter
{
    public static function create(
        ?callable $notFoundHandler = null,
        ?callable $exceptionHandler = null,
    ): self {
        return new self($notFoundHandler, $exceptionHandler);
    }

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

Test::suite('Router', function () {

    Test::beforeEach(function ($state) {
        $state['router'] = TestHarness::create();
    });

    Test::it('should create new instance', function ($state) {
        Test::assertInstanceOf(TestHarness::class, $state['router']);
    });

    Test::it('should throw when use called without handlers', function ($state) {
        Test::expectException(InvalidArgumentException::class, function () use ($state) {
            $state['router']->use('/path');
        });
    });

    Test::it('should run global middleware before handler', function ($state) {

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

    Test::it('should match prefixed middleware to formatted path', function ($state) {

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

    Test::it('should continue pipeline when middleware calls next', function ($state) {

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

    Test::it('should handle exception with throwable parameter in error handler', function ($state) {

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

    Test::it('should run error handler with two parameters', function ($state) {

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

    Test::it('should reject invalid error handler signature', function ($state) {
        Test::expectException(InvalidArgumentException::class, function () use ($state) {
            $state['router']->exposeAdapt(function ($error, $next) {});
        });
    });

    Test::it('should reject handlers with more than two parameters', function ($state) {
        Test::expectException(InvalidArgumentException::class, function () use ($state) {
            $state['router']->exposeAdapt(function ($a, $b, $c) {});
        });
    });

    Test::it('should receive throwable in union type error handler', function ($state) {

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

    Test::it('should skip middleware execution when error propagates', function ($state) {

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

    Test::it('should forward errors in zero parameter handler', function ($state) {
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

    Test::it('should return null when stack is empty', function ($state) {
        Test::assertNull($state['router']->runEmpty());
    });

    Test::it('should rethrow error when unhandled', function ($state) {
        Test::expectException(RuntimeException::class, function () use ($state) {
            $state['router']->runEmpty(new RuntimeException('unhandled'));
        });
    });
});
