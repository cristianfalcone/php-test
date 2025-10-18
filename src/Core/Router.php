<?php

declare(strict_types=1);

namespace Ajo\Core;

use Closure;
use InvalidArgumentException;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionUnionType;
use Throwable;

abstract class Router
{
    /** @var array<int, array{prefix:string, handler:callable}> */
    private array $middlewares = [];

    protected $notFoundHandler;
    protected $exceptionHandler;

    public function __construct(
        ?callable $notFoundHandler = null,
        ?callable $exceptionHandler = null,
    ) {
        $this->notFoundHandler = $notFoundHandler ?? $this->defaultNotFound();
        $this->exceptionHandler = $exceptionHandler ?? $this->defaultException();
    }

    public static function create(
        ?callable $notFoundHandler = null,
        ?callable $exceptionHandler = null,
    ): static {
        return new static($notFoundHandler, $exceptionHandler);
    }

    public function use(string|callable $path, callable ...$handlers): static
    {
        if (is_callable($path)) {
            $handlers = [$path, ...$handlers];
            $path = '*';
        }

        if ($handlers === []) {
            throw new InvalidArgumentException('Se requiere al menos un middleware.');
        }

        $prefix = $path === '*'
            ? '*'
            : $this->normalize($path);

        foreach ($handlers as $handler) {
            $this->middlewares[] = ['prefix' => $prefix, 'handler' => $this->adapt($handler)];
        }

        return $this;
    }

    protected function stack(string $target): array
    {
        return array_map(
            fn(array $mw) => $mw['handler'],
            array_filter(
                $this->middlewares,
                fn(array $mw) => $mw['prefix'] === '*' || $this->matches($mw['prefix'], $target),
            ),
        );
    }

    protected function run(array $stack, int $index = 0, ?Throwable $error = null): mixed
    {
        if (!isset($stack[$index])) {

            if ($error !== null) {
                throw $error;
            }

            return null;
        }

        $next = fn(?Throwable $err = null) => $this->run($stack, $index + 1, $err);

        try {
            return $stack[$index]($error, $next);
        } catch (Throwable $caught) {
            return $this->run($stack, $index + 1, $caught);
        }
    }

    protected function adapt(callable $handler): callable
    {
        $callable = $handler instanceof Closure ? $handler : Closure::fromCallable($handler);
        $reflection = new ReflectionFunction($callable);
        $required = $reflection->getNumberOfParameters();

        if ($required > 2) {
            throw new InvalidArgumentException("Handler '{$reflection->getName()}' admite como máximo dos parámetros.");
        }

        $acceptsThrowable = false;

        if ($required > 0) {
            $type = $reflection->getParameters()[0]->getType();

            if ($type instanceof ReflectionNamedType) {
                $acceptsThrowable = !$type->isBuiltin() && is_a($type->getName(), Throwable::class, true);
            } elseif ($type instanceof ReflectionUnionType) {
                foreach ($type->getTypes() as $named) {
                    if ($named instanceof ReflectionNamedType && !$named->isBuiltin() && is_a($named->getName(), Throwable::class, true)) {
                        $acceptsThrowable = true;
                        break;
                    }
                }
            }
        }

        return match ($required) {
            0 => function (?Throwable $error, callable $next) use ($callable) {
                if ($error !== null) {
                    return $next($error);
                }

                $result = $callable();

                return $result ?? $next(null);
            },
            1 => $acceptsThrowable
                ? function (?Throwable $error, callable $next) use ($callable) {
                    return $error === null ? $next(null) : ($callable($error) ?? $next(null));
                }
                : function (?Throwable $error, callable $next) use ($callable) {

                    if ($error !== null) {
                        return $next($error);
                    }

                    $called = false;
                    $value = null;

                    $proxy = function (?Throwable $err = null) use (&$called, &$value, $next) {
                        $called = true;
                        $value = $next($err);
                        return $value;
                    };

                    $result = $callable($proxy);

                    if ($called) {
                        return $result ?? $value;
                    }

                    return $result ?? $next(null);
                },
            2 => $acceptsThrowable
                ? function (?Throwable $error, callable $next) use ($callable) {
                    return $error === null ? $next(null) : $callable($error, $next);
                }
                : throw new InvalidArgumentException("Error handler '{$reflection->getName()}' debe aceptar Throwable como primer parámetro."),
        };
    }

    abstract protected function matches(string $prefix, string $target): bool;

    abstract protected function normalize(string $path): string;

    abstract protected function defaultNotFound(): callable;

    abstract protected function defaultException(): callable;
}
