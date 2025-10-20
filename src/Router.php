<?php

declare(strict_types=1);

namespace Ajo;

use Ajo\Core\Facade;
use Ajo\Container;

/**
 * Base facade for Routers.
 */
abstract class Router
{
    use Facade;

    /**
     * Creates a new instance of the concrete router.
     *
     * Note: This method does NOT bind the instance to the facade automatically.
     * To use the created instance as the facade singleton, call:
     *   Console::swap(Console::create(...))
     */
    public static function create(
        ?callable $notFoundHandler = null,
        ?callable $exceptionHandler = null,
    ) {
        $root = static::root();

        /** @var object $instance */
        $instance = new $root($notFoundHandler, $exceptionHandler);

        return $instance;
    }

    /** @return class-string */
    abstract protected static function root(): string;
}
