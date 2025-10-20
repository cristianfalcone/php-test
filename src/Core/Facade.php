<?php

declare(strict_types=1);

namespace Ajo\Core;

use Ajo\Container;
use BadMethodCallException;
use InvalidArgumentException;
use ReflectionMethod;

/**
 * @template T of object
 */
trait Facade
{
  /**
   * Resolves the static method as a proxy to the underlying object.
   */
  public static function __callStatic(string $method, array $arguments)
  {
    $root = static::root();

    if (method_exists($root, $method)) {

      $reflection = new ReflectionMethod($root, $method);

      if ($reflection->isStatic()) {

        if (!$reflection->isPublic()) {
          throw new BadMethodCallException(sprintf(
            "Static method '%s' not available on facade '%s'.",
            $method,
            static::class
          ));
        }

        return $reflection->invoke(null, ...$arguments);
      }
    }

    $instance = static::instance();

    if (method_exists($instance, $method)) {

      $reflection = new ReflectionMethod($instance, $method);

      if (!$reflection->isPublic()) {
        throw new BadMethodCallException(sprintf(
          "Method '%s' not available on facade '%s'.",
          $method,
          static::class
        ));
      }

      return $reflection->invoke($instance, ...$arguments);
    }

    if (!method_exists($instance, '__call')) {
      throw new BadMethodCallException(sprintf(
        "Method '%s' not available on facade '%s'.",
        $method,
        static::class
      ));
    }

    return $instance->{$method}(...$arguments);
  }

  /**
   * Returns the instance currently associated with the facade.
   *
   * @return T
   */
  public static function instance(): object
  {
    $root = static::root();

    if (!Container::has($root)) {
      Container::singleton($root, static fn() => new $root());
    }

    /** @var T */
    return Container::get($root);
  }

  /**
   * Temporarily replaces the instance used to resolve the facade.
   *
   * @param T $instance
   */
  public static function swap(object $instance): void
  {
    $root = static::root();

    if (!$instance instanceof $root) {
      throw new InvalidArgumentException(sprintf(
        "Invalid instance for '%s'; expected '%s'.",
        static::class,
        $root
      ));
    }

    Container::set($root, $instance);
  }

  /**
   * Gets the concrete class that represents the implementation.
   *
   * @return class-string<T>
   */
  abstract protected static function root(): string;
}
