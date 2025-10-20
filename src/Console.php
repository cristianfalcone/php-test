<?php

declare(strict_types=1);

namespace Ajo;

use Ajo\Core\Console as Root;

/**
 * Console facade for CLI applications.
 *
 * @mixin Root
 *
 * @method static Root create(?callable $notFoundHandler = null, ?callable $exceptionHandler = null)
 * @method static Root command(string $name, callable $handler)
 * @method static Root use(string|callable $path, callable ...$handlers)
 * @method static Root describe(string $description)
 * @method static array<string, array<string, mixed>> commands()
 * @method static int dispatch(?string $command = null, array $arguments = [], $stdout = null, $stderr = null)
 * @method static array<int, string> arguments()
 * @method static string bin()
 * @method static void blank(int $lines = 1)
 * @method static void log(string $message = '')
 * @method static void success(string $message)
 * @method static void info(string $message)
 * @method static void warn(string $message)
 * @method static void error(string $message)
 * @method static void write(string $message, $stream = null)
 * @method static void table(array $columns, array $rows)
 * @method static void progress(int $current, int $total, string $label = '', array $breakdown = [])
 *
 * Usage patterns:
 *
 * 1. Static usage (simple):
 *    Console::command('greet', fn() => Console::success('Hello!'));
 *    Console::dispatch();
 *
 * 2. Custom handlers:
 *    Console::swap(Console::create(
 *        notFoundHandler: fn() => (Console::error('Command not found') || 1),
 *        exceptionHandler: fn($e) => (Console::error($e->getMessage()) || 1)
 *    ));
 *    Console::command('test', fn() => 0);
 *
 * 3. Explicit instance (for testing):
 *    $cli = Console::create();
 *    $cli->command('demo', fn() => 0);
 *    $cli->dispatch();
 */
final class Console extends Router
{
    protected static function root(): string
    {
        return Root::class;
    }
}
