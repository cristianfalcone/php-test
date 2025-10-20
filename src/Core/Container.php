<?php

declare(strict_types=1);

namespace Ajo\Core;

use Closure;
use InvalidArgumentException;
use ReflectionFunction;
use RuntimeException;

/**
 * Ultra-lightweight container used to share services or simple values.
 */
final class Container
{
    /**
     * @var array<string, array{factory:callable|null,shared:bool,resolved:bool,value:mixed}>
     */
    private array $entries = [];

    public function __construct()
    {
    }

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

        $entry =& $this->entries[$id];

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
        $this->entries = [];
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
