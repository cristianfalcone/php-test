<?php

declare(strict_types=1);

namespace Ajo;

use Ajo\Core\Console as Root;
use Ajo\Core\Facade;

/**
 * @mixin Root
 *
 * @method static Root create(?callable $notFoundHandler = null, ?callable $exceptionHandler = null)
 * @method static Root command(string $name, callable $handler)
 * @method static Root describe(string $description)
 * @method static array<string, array<string, mixed>> commands()
 * @method static int dispatch(?string $command = null, array $arguments = [], $stdout = null, $stderr = null)
 * @method static array<int, string> arguments()
 * @method static string bin()
 * @method static void blank(int $lines = 1)
 * @method static void log(string $message = '')
 * @method static void table(array $columns, array $rows)
 * @method static void success(string $message)
 * @method static void info(string $message)
 * @method static void warn(string $message)
 * @method static void error(string $message)
 * @method static void write(string $message, $stream = null)
 */
final class Console
{
    use Facade;

    protected static function root(): string
    {
        return Root::class;
    }
}
