<?php

declare(strict_types=1);

namespace Ajo;

/**
 * Mini contenedor en memoria para compartir instancias durante el ciclo de vida.
 */
final class Context
{
    /** @var array<string, mixed> */
    private static array $store = [];

    private function __construct()
    {
    }

    public static function set(string $key, mixed $value): void
    {
        self::$store[$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$store[$key] ?? $default;
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::$store);
    }

    public static function forget(string $key): void
    {
        unset(self::$store[$key]);
    }

    public static function clear(): void
    {
        self::$store = [];
    }
}
