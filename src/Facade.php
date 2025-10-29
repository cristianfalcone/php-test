<?php

declare(strict_types=1);

namespace Ajo;

use BadMethodCallException;
use InvalidArgumentException;
use ReflectionMethod;

/**
 * Facade — Laravel-style facade pattern with separated implementation.
 *
 * Usage (two-class pattern):
 *
 *   // Implementation class (instanciable, for testing)
 *   final class ConsoleCore {
 *       public function command(string $name, callable $handler) { ... }
 *   }
 *
 *   // Facade class (static proxy)
 *   final class Console {
 *       use Facade;
 *       protected static function root(): string {
 *           return ConsoleCore::class;
 *       }
 *   }
 *
 *   // Usage: static calls proxy to singleton instance stored in Container
 *   Console::command('test', fn() => 0);
 *   Console::dispatch();
 *
 *   // Create custom instance of Core
 *   $cli = Console::create();
 *   $cli->command('foo', fn() => 0);
 *
 *   // Swap facade singleton in Container
 *   Console::swap($cli);
 *
 * Note: All facade instances are stored in Container with key "__facade:{ClassName}"
 *
 * @template T of object
 */
trait Facade
{
  /**
   * Resolves static method calls as proxies to the underlying instance.
   */
  public static function __callStatic(string $method, array $arguments)
  {
    // Special handling for create() - always instantiate new Core class
    if ($method === 'create') {
      $root = static::root();
      return new $root(...$arguments);
    }

    // Delegate to root class singleton instance
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

    // Try __call on instance if available
    if (method_exists($instance, '__call')) {
      return $instance->{$method}(...$arguments);
    }

    throw new BadMethodCallException(sprintf(
      "Method '%s' not available on facade '%s'.",
      $method,
      static::class
    ));
  }

  /**
   * Returns the singleton instance of the root class.
   * Instances are stored in Container with key "__facade:{ClassName}".
   *
   * @return T
   */
  public static function instance(): object
  {
    $root = static::root();
    $key = '__facade:' . $root;

    // Special case: Container itself uses static var to avoid recursion
    if ($root === ContainerCore::class) {
      static $containerInstance = null;
      return $containerInstance ??= new ContainerCore();
    }

    // Get or create instance via Container facade
    if (!Container::has($key)) {
      Container::set($key, new $root());
    }

    /** @var T */
    return Container::get($key);
  }

  /**
   * Replaces the facade singleton with a custom instance.
   *
   * @param T $instance
   */
  public static function swap(object $instance): void
  {
    $root = static::root();

    if (!$instance instanceof $root) {
      throw new InvalidArgumentException(sprintf(
        "Invalid instance for facade '%s'; expected instance of '%s'.",
        static::class,
        $root
      ));
    }

    $key = '__facade:' . $root;
    Container::set($key, $instance);
  }

  /**
   * Get the root class that this facade proxies to.
   * Must be implemented by the facade class.
   *
   * @return class-string<T>
   */
  abstract protected static function root(): string;
}
