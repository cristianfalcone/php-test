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
   * Resuelve el método estático como proxy al objeto subyacente.
   */
  public static function __callStatic(string $method, array $arguments)
  {
    $root = static::root();

    if (method_exists($root, $method)) {

      $reflection = new ReflectionMethod($root, $method);

      if ($reflection->isStatic()) {

        if (!$reflection->isPublic()) {
          throw new BadMethodCallException(sprintf(
            "Método estático '%s' no disponible en fachada '%s'.",
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
          "Método '%s' no disponible en fachada '%s'.",
          $method,
          static::class
        ));
      }

      return $reflection->invoke($instance, ...$arguments);
    }

    if (!method_exists($instance, '__call')) {
      throw new BadMethodCallException(sprintf(
        "Método '%s' no disponible en fachada '%s'.",
        $method,
        static::class
      ));
    }

    return $instance->{$method}(...$arguments);
  }

  /**
   * Devuelve la instancia actualmente asociada a la fachada.
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
   * Reemplaza temporalmente la instancia utilizada para resolver la fachada.
   *
   * @param T $instance
   */
  public static function swap(object $instance): void
  {
    $root = static::root();

    if (!$instance instanceof $root) {
      throw new InvalidArgumentException(sprintf(
        "Instancia inválida para '%s'; se esperaba '%s'.",
        static::class,
        $root
      ));
    }

    Container::set($root, $instance);
  }

  /**
   * Obtiene la clase concreta que representa la implementación.
   *
   * @return class-string<T>
   */
  abstract protected static function root(): string;
}
