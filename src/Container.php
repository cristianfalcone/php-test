<?php

declare(strict_types=1);

namespace Ajo;

use Closure;
use InvalidArgumentException;
use ReflectionFunction;
use RuntimeException;

/**
 * ContainerCore — Ultra-lightweight DI container (implementation class).
 *
 * This is the concrete implementation. Use Container facade for static access.
 * Use this class directly in tests: $container = new ContainerCore();
 */
final class ContainerCore
{
    /**
     * @var array<string, array{factory:callable|null,shared:bool,resolved:bool,value:mixed}>
     */
    private array $entries = [];

    public function set(string $id, mixed $value): void
    {
        $this->entries[$id] = [
            'factory' => null,
            'shared' => true,
            'resolved' => true,
            'value' => $value,
        ];
    }

    /** @param callable(self=):mixed $factory */
    public function singleton(string $id, callable $factory): void
    {
        $this->entries[$id] = [
            'factory' => $factory,
            'shared' => true,
            'resolved' => false,
            'value' => null,
        ];
    }

    /** @param callable(self=):mixed $factory */
    public function factory(string $id, callable $factory): void
    {
        $this->entries[$id] = [
            'factory' => $factory,
            'shared' => false,
            'resolved' => false,
            'value' => null,
        ];
    }

    public function get(string $id, mixed $default = null): mixed
    {
        if (!array_key_exists($id, $this->entries)) {
            if (func_num_args() > 1) {
                return $default;
            }

            throw new RuntimeException("Service '{$id}' is not bound.");
        }

        $entry = &$this->entries[$id];

        if ($entry['factory'] === null) {
            return $entry['value'];
        }

        if ($entry['shared'] && $entry['resolved']) {
            return $entry['value'];
        }

        $value = $this->invoke($entry['factory']);

        if ($entry['shared']) {
            $entry['value'] = $value;
            $entry['resolved'] = true;
        }

        return $value;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }

    public function forget(string $id): void
    {
        unset($this->entries[$id]);
    }

    public function clear(): void
    {
        // Preserve facade instances to avoid breaking singleton state
        foreach (array_keys($this->entries) as $key) {
            if (!str_starts_with($key, '__facade:')) {
                unset($this->entries[$key]);
            }
        }
    }

    private function invoke(callable $factory): mixed
    {
        $closure = $factory instanceof Closure ? $factory : Closure::fromCallable($factory);
        $reflection = new ReflectionFunction($closure);

        return match ($reflection->getNumberOfParameters()) {
            0 => $closure(),
            1 => $closure($this),
            default => throw new InvalidArgumentException('Factories accept at most one parameter (Container).'),
        };
    }
}

/* ================================================
 *  Container Facade — Static interface
 * ================================================ */

/**
 * Container — Static facade for ContainerCore.
 *
 * Usage:
 *   Container::set('db', $pdo);
 *   Container::singleton('logger', fn() => new Logger());
 *   $db = Container::get('db');
 *
 * For testing, use ContainerCore directly:
 *   $container = new ContainerCore();
 *   $container->set('test', 'value');
 */
final class Container
{
    use Facade;

    protected static function root(): string
    {
        return ContainerCore::class;
    }
}
