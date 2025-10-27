<?php

declare(strict_types=1);

namespace Ajo;

use Ajo\Core\Console2 as Root;

/**
 * Console2 facade for PSR-3 CLI applications.
 *
 * @mixin Root
 *
 * @method static Root create(?callable $notFoundHandler = null, ?callable $exceptionHandler = null)
 * @method static Root command(string $name, callable $handler) Register command
 * @method static Root describe(string $text) Set command description
 * @method static Root usage(string $pattern) Add usage pattern
 * @method static Root option(string $flags, string $description = '', mixed $default = null) Add option
 * @method static Root example(string $usage, ?string $desc = null) Add example
 * @method static Root use(?string $prefix, callable $handler) Register middleware
 * @method static int dispatch(?string $command = null, array $arguments = [], $stdout = null, $stderr = null) Dispatch command
 * @method static string bin() Get binary name
 * @method static array arguments() Get raw arguments
 * @method static array options() Get parsed options
 * @method static Root timestamps(bool $enable = true) Enable/disable timestamps
 * @method static Root colors(bool $enable = true) Enable/disable colors
 * @method static bool isInteractive() Check if interactive TTY
 * @method static void line(string $message = '') Write neutral line
 * @method static void success(string $message, array $context = []) Success message
 * @method static void blank(int $lines = 1) Write blank lines
 * @method static void write(string $message, $stream = null) Raw write
 * @method static void debug(string|\Stringable $message, array $context = []) PSR-3 debug
 * @method static void info(string|\Stringable $message, array $context = []) PSR-3 info
 * @method static void notice(string|\Stringable $message, array $context = []) PSR-3 notice
 * @method static void warning(string|\Stringable $message, array $context = []) PSR-3 warning
 * @method static void error(string|\Stringable $message, array $context = []) PSR-3 error
 * @method static void critical(string|\Stringable $message, array $context = []) PSR-3 critical
 * @method static void alert(string|\Stringable $message, array $context = []) PSR-3 alert
 * @method static void emergency(string|\Stringable $message, array $context = []) PSR-3 emergency
 * @method static void log($level, string|\Stringable $message, array $context = []) PSR-3 log
 *
 * Usage patterns:
 *
 * 1. Static usage (simple):
 *    Console2::command('greet', fn() => Console2::success('Hello!'));
 *    Console2::dispatch();
 *
 * 2. Custom handlers:
 *    Console2::swap(Console2::create(
 *        notFoundHandler: fn() => (Console2::error('Command not found') || 1),
 *        exceptionHandler: fn($e) => (Console2::error($e->getMessage()) || 1)
 *    ));
 *    Console2::command('test', fn() => 0);
 *
 * 3. Explicit instance (for testing):
 *    $cli = Console2::create();
 *    $cli->command('demo', fn() => 0);
 *    $cli->dispatch();
 */
final class Console2 extends Router
{
    protected static function root(): string
    {
        return Root::class;
    }
}
