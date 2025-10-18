<?php

declare(strict_types=1);

namespace Ajo;

use Ajo\Core\Container as Root;
use Ajo\Core\Facade;

/**
 * @mixin Root
 *
 * @method static void set(string $id, mixed $value)
 * @method static void singleton(string $id, callable $factory)
 * @method static void factory(string $id, callable $factory)
 * @method static mixed get(string $id, mixed $default = null)
 * @method static bool has(string $id)
 * @method static void forget(string $id)
 * @method static void clear()
 */
final class Container
{
    use Facade;

    private static ?Root $instance = null;

    public static function instance(): Root
    {
        return self::$instance ??= new Root();
    }

    protected static function root(): string
    {
        return Root::class;
    }
}
