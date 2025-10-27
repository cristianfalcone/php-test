# Console2 Developer Guide

- **Requires:** PHP 8.4+
- **Scope:** single API for commands **and** structured output (PSR‑3 compatible)
- **Streams:** stdout/stderr separation, TTY‑aware colors & timestamps
- **Zero dependencies:** Complete PSR-3 logger + CLI framework in <1000 LOC

> The API consciously avoids separating "CLI" vs "Logger" concerns. You write commands and emit structured output with the same fluent surface. PSR‑3 compatibility lets you drop Console2 anywhere a standard logger is expected. Key PSR‑3 behaviors (levels, placeholders, `context['exception']`) follow the specification. ([php-fig.org][1])

## Table of Contents

1. [Quick Start (5 minutes)](#quick-start-5-minutes)
2. [Core Concepts](#core-concepts)
3. [Command Registration](#command-registration)
4. [Arguments & Options](#arguments--options)
5. [Output Model (Levels, Streams, TTY)](#output-model-levels-streams-tty)
6. [Middleware](#middleware)
7. [Styling (fluent ANSI)](#styling-fluent-ansi)
8. [Programmatic Use & Testing](#programmatic-use--testing)
9. [Recipes](#recipes)
10. [Interoperability (PSR‑3)](#interoperability-psr3)
11. [Architecture](#architecture)
12. [Full API Reference](#full-api-reference)
13. [Comparison with Similar Libraries](#comparison-with-similar-libraries)

---

## Quick Start (5 minutes)

### 1) Hello, world

```php
use Ajo\Console2;

Console2::command('hello', function () {
    Console2::success('Hello, world!');
    return 0;
});

exit(Console2::dispatch());
```

Run:

```bash
php console hello
```

### 2) With options and positional arguments

```php
use Ajo\Console2;

Console2::command('build', function () {
    $o = Console2::options();

    $verbose = $o['verbose'] ?? false;
    $output  = $o['output']  ?? 'dist/bundle.js';
    $files   = $o['_']; // positional args

    if ($verbose) Console2::info('Verbose mode enabled');
    Console2::line('Files: ' . count($files));
    Console2::success('Output: ' . $output);
    return 0;
})
->describe('Build project bundle')
->usage('<src> [<src>...] [options]')
->option('-v, --verbose', 'Enable verbose output')
->option('-o, --output',  'Output file', 'dist/bundle.js')
->example('src/ --verbose --output=bundle.js', 'Verbose build to custom file');
```

**What you get out of the box**

* `help` command: `php console help build` prints description, usage lines, options, and examples.
* Neutral output via `line()`. Semantic levels via `success()`, `info()`, `warning()`, `error()`, etc.
* TTY‑aware output: colors in an interactive terminal, timestamps when piped/redirected. Uses `stream_isatty()` to detect TTY. ([php.net][2])

## Core Concepts

* **Single surface:** one API for registering commands and emitting structured output.
* **TTY detection:** When stdout is a terminal: **colors ON**, **timestamps OFF**. When redirected: **colors OFF**, **timestamps ON**. Uses `stream_isatty()` (portable across platforms). ([php.net][2])
* **Streams:** stdout for regular/info/success; stderr for warnings and errors.
* **PSR‑3:** standard levels (`debug` → `emergency`) and `log($level, $message, array $context = [])` with placeholder interpolation and `context['exception']`. ([php-fig.org][1])

## Command Registration

Define commands with a fluent builder:

```php
Console2::command('deploy', function () {
    $o = Console2::options();
    $env = $o['env'] ?? 'production';
    Console2::info('Deploying {env}…', ['env' => $env]);
    return 0;
})
->describe('Deploy the application')
->usage('[options]')
->usage('<env> [options]')
->option('-e, --env', 'Environment', 'production')
->option('--dry-run', 'Simulate the deployment')
->example('--env=staging', 'Deploy to staging')
->example('production --dry-run', 'Test production deploy');
```

> `help` is built‑in: `php console help deploy` renders description, usages, options (with defaults), and examples automatically.

## Arguments & Options

The parser turns `argv` into a clean array:

* **Booleans:** `-v`, `--verbose` → `['verbose' => true]`
* **Values:** `--output=app.js`, `-o app.js`
* **Negation:** `--no-color` → `['color' => false]`
* **Arrays:** repeating flags accumulate: `--ignore=a --ignore=b` → `['ignore' => ['a','b']]`
* **Stop parsing:** `--` stops flag parsing; the rest goes to positionals
* **Aliases:** `-v, --verbose` unify under the long name
* **Positional args:** always available in `['_']`

Access them inside your handler with `Console2::options()`.

## Output Model (Levels, Streams, TTY)

```php
// neutral line
Console2::line('Starting…');

// semantic levels
Console2::info('Connecting');
Console2::success('Connected');
Console2::warning('Cache miss');   // → stderr
Console2::error('Failed to save'); // → stderr
```

**TTY behavior**

* **Interactive:** colored labels, no timestamps.
* **Redirected:** timestamps prefixed, no colors. Determined via `stream_isatty()` (portable TTY check). ([php.net][2])

Manual overrides:

```php
Console2::timestamps(true)->colors(false);  // force “logger mode”
Console2::timestamps(false)->colors(true);  // force “interactive mode”
```

## Middleware

Wrap command execution with global or prefixed middleware:

```php
// Global
Console2::use(function (?Throwable $error, callable $next) {
    $start = microtime(true);
    $code  = $next($error);
    Console2::line(sprintf('T=%.2fs', microtime(true) - $start));
    return $code;
});

// Prefix-only (applies to "job:*")
Console2::use('job:*', function ($error, $next) {
    // setup for job commands
    return $next($error);
});
```

* Middleware run in **registration order** (onion model).
* `$error` lets you react to upstream exceptions.

## Styling (fluent ANSI)

Use a fluent builder for emphasis and color:

```php
Console2::line(Console2::bold('Important'));
Console2::bold()->red()->line('Critical!');
Console2::dim()->italic()->line('(optional note)');
```

Available methods:
- **Text styles:** `bold()`, `dim()`, `italic()`, `underline()`, `inverse()`, `hidden()`, `strikethrough()`
- **Colors:** `black()`, `red()`, `green()`, `yellow()`, `blue()`, `magenta()`, `cyan()`, `white()`, `gray()`
- **Backgrounds:** `bgBlack()`, `bgRed()`, `bgGreen()`, `bgYellow()`, `bgBlue()`, `bgMagenta()`, `bgCyan()`, `bgWhite()`

> Styling auto‑disables when colors are off (non‑TTY or forced).

## Programmatic Use & Testing

Use the instance directly for fine‑grained control:

```php
use Ajo\Core\Console2 as CoreConsole2;

$cli = new CoreConsole2();

$cli->command('test', function () use ($cli) {
    $cli->success('OK');
    return 0;
});

$stdout = fopen('php://memory', 'r+');
$stderr = fopen('php://memory', 'r+');
$code   = $cli->dispatch('test', [], $stdout, $stderr);

rewind($stdout);
$output = stream_get_contents($stdout); // capture just stdout

rewind($stderr);
$errors = stream_get_contents($stderr); // capture just stderr
```

You can also swap the facade instance for testing:

```php
use Ajo\Console2;

$cli = new CoreConsole2();
Console2::setInstance($cli);

// Now all static calls use your custom instance
Console2::command('test', fn() => 0);
```

## Recipes

**Dual syntax (flag or positional):**

```php
Console2::command('test', function () {
    $o = Console2::options();
    $filter = $o['filter'] ?? $o['_'][0] ?? null;
})
->usage('[filter] [options]')
->option('--filter', 'Filter to apply');
```

**Required positionals:**

```php
Console2::command('copy', function () {
    $o = Console2::options();
    if (count($o['_']) < 2) {
        Console2::error('Usage: console copy <src> <dest>');
        return 1;
    }
    [$src, $dest] = $o['_'];
    // …
    return 0;
})
->usage('<src> <dest>');
```

**Boolean defaults + negation:**

```php
Console2::command('build', function () {
    $o = Console2::options();
    $minify    = $o['minify']    ?? true;
    $sourcemap = $o['sourcemap'] ?? true;

    if ($minify) Console2::line('Minifying…');
    if ($sourcemap) Console2::line('Sourcemaps…');
    return 0;
})
->option('--no-minify', 'Skip minification')
->option('--no-sourcemap', 'Skip sourcemaps');
```

**Repeated flags (arrays):**

```php
Console2::command('lint', fn() => 0)
    ->option('--ignore', 'Globs to ignore')
    ->example('--ignore=node_modules --ignore=dist');
```

**Progress output:**

```php
foreach ($files as $i => $f) {
    Console2::dim()->line(sprintf('[%d/%d] %s', $i+1, count($files), $f));
}
Console2::success('Done');
```

**PSR‑3 placeholders & exceptions:**

```php
try {
    // …
} catch (Throwable $e) {
    Console2::error('Failed saving order {id}', ['id' => $id, 'exception' => $e]);
}
```

> Placeholders like `{id}` are replaced with values from `context`; passing `context['exception']` attaches the exception message and stack trace per PSR‑3 guidance. ([php-fig.org][1])

## Interoperability (PSR‑3)

Console2 implements the PSR‑3 logger interface (`Psr\Log\LoggerInterface`). You can:

* Call standard level methods: `debug()`, `info()`, `notice()`, `warning()`, `error()`, `critical()`, `alert()`, `emergency()`.
* Use the generic entry point: `log($level, $message, array $context = [])`.
* Include placeholders in messages (e.g., `User {id}`) and supply values in `$context`.
* Pass `context['exception']` with a `Throwable` to print exception details.

See the official PSR‑3 pages for the contract and semantics (levels, placeholders, context). ([php-fig.org][1])

## Architecture

```
Ajo\Console2 (Facade) ─┬─> static DX via __callStatic magic method
                       ├─> full IDE autocomplete via @method tags
                       └─> instance swapping for tests

Ajo\Core\Console2     ──> PSR-3 logger + CLI implementation
       │                  ├─> __call for dynamic style methods
       │                  ├─> 32 ANSI styles (bold, colors, backgrounds)
       │                  └─> TTY-aware colors & timestamps
       ▲
       └── Router        ──> middleware pipeline & dispatch scaffolding
```

**Key design decisions:**
* **Magic methods:** `__call` and `__callStatic` eliminate 150+ lines of repetitive code
* **@method tags:** Full IDE autocomplete without code duplication
* **Nullable bools:** `?bool` + `??=` for elegant auto-detection of TTY state
* **Single responsibility:** Facade is <120 LOC, just delegates to core instance

* Prefer the **facade** in application code (`Ajo\Console2`).
* Use the **core instance** in tests or when you need custom streams (`Ajo\Core\Console2`).

## Full API Reference

> The list below reflects the refactored surface (English naming, `line()` neutral output, PSR‑3 levels).

### Facade (static), `Ajo\Console2`

> **Note:** All methods below are available via `__callStatic` magic method with full IDE autocomplete through `@method` PHPDoc tags. The facade contains only 112 lines of code - no method duplication!

#### Registration

* `Console2::command(string $name, callable $handler): CoreConsole`
* `Console2::describe(string $text): CoreConsole`
* `Console2::usage(string $pattern): CoreConsole`
* `Console2::option(string $flags, string $description = '', mixed $default = null): CoreConsole`
* `Console2::example(string $usage, ?string $desc = null): CoreConsole`
* `Console2::use(callable|string $prefixOrHandler, ?callable $handler = null): CoreConsole` — register middleware globally or for a prefix.

#### Execution & environment

* `Console2::dispatch(?string $command = null, array $arguments = [], $stdout = null, $stderr = null): int`
* `Console2::timestamps(bool $enable = true): CoreConsole`
* `Console2::colors(bool $enable = true): CoreConsole`
* `Console2::isInteractive(): bool`

#### Accessors

* `Console2::bin(): string`
* `Console2::arguments(): array`   — raw arguments (post‑command)
* `Console2::options(): array`     — parsed options; positionals in `['_']`

#### Output

* `Console2::line(string $message = ''): void` — neutral line → stdout
* `Console2::success(string $message, array $context = []): void` — semantic success → stdout
* `Console2::blank(int $lines = 1): void`
* `Console2::write(string $message, $stream = null): void` — low‑level write (no labels)

#### PSR‑3 levels

* `Console2::debug/info/notice/warning/error/critical/alert/emergency(string|\Stringable $message, array $context = []): void`
* `Console2::log(string $level, string|\Stringable $message, array $context = []): void`

#### Styling (fluent)

All style methods are handled dynamically via `__call` magic method:

* **Text styles:** `bold()`, `dim()`, `italic()`, `underline()`, `inverse()`, `hidden()`, `strikethrough()`
* **Colors:** `black()`, `red()`, `green()`, `yellow()`, `blue()`, `magenta()`, `cyan()`, `white()`, `gray()`
* **Backgrounds:** `bgBlack()`, `bgRed()`, `bgGreen()`, `bgYellow()`, `bgBlue()`, `bgMagenta()`, `bgCyan()`, `bgWhite()`

Each returns a **StyleBuilder** (chainable) or a styled string when text is provided.

### Core instance, `Ajo\Core\Console2`

Same surface as exposed via the facade, plus instance‑level equivalents of all above methods. Use this when you need to:

* Pass custom streams to `dispatch()`
* Assert output in tests (capture `php://memory`)
* Build separate consoles in the same process

### StyleBuilder

* `text(string $text): string` — returns the styled text
* `line(string $text = ''): void` — prints a styled line (sugar)
* Chain any style/color methods listed above before calling `text()`/`line()`.

## Comparison with Similar Libraries

> This section is intentionally placed at the end to keep the guide focused on Console first.

| Aspect / Tool          | Console2 (this project)                                                       | Symfony Console                                        | Laravel Artisan                                                  | Monolog                                                         |
| ---------------------- | ----------------------------------------------------------------------------- | ------------------------------------------------------ | ---------------------------------------------------------------- | --------------------------------------------------------------- |
| Primary focus          | Commands **and** structured output (PSR‑3) in <1000 LOC                       | Command‑line tooling for apps                          | Framework‑integrated console for Laravel                         | General‑purpose logging library                                 |
| Out‑of‑the‑box         | Registry, parser, help, middleware, TTY‑aware output, PSR-3 logger            | Rich command UX (styles, helpers, progress, questions) | Closely integrated with Laravel apps; scaffolding & testing APIs | Many handlers/formatters; routes logs to files/sockets/services |
| PSR‑3                  | Full `LoggerInterface` implementation                                         | (Use external logger for PSR‑3)                        | (Use external logger for PSR‑3)                                  | Full PSR‑3 with handlers                                        |
| Code size              | 993 LOC (112 facade + 881 core)                                               | ~50,000+ LOC                                           | ~20,000+ LOC                                                     | ~15,000+ LOC                                                    |
| Dependencies           | **Zero** (even PSR-3 interfaces are bundled)                                  | Many Symfony components                                | Laravel framework                                                | PSR-3 + handlers                                                |
| Typical when to choose | Micro CLIs, workers, zero‑dependency needs, PSR‑3 logging in one place        | When you need the full suite of console UX features    | When building inside Laravel ecosystem                           | When you need multi‑destination logging & advanced handlers     |

* **Symfony Console**: a mature component to build commands with rich styling, helpers (progress bars, prompts) and more. ([symfony.com][3])
* **Laravel Artisan**: Laravel’s own console with first‑class integration and testing APIs. ([Laravel][4])
* **Monolog**: a PSR‑3 logger that can send logs to files, sockets, inboxes, databases, and numerous web services via a large set of handlers. ([GitHub][5])
* **PSR‑3 (`psr/log`)**: a standard interface; the `psr/log` package holds interfaces and traits only (no concrete logger). ([GitHub][6])

## Appendix: Notes on TTY & Portability

Console uses `stream_isatty()` to detect interactive terminals. This check is portable across platforms (works on Windows as well), unlike POSIX‑specific functions. If stdout is a TTY, colors are enabled and timestamps disabled; otherwise the inverse. ([php.net][2])

[1]: https://www.php-fig.org/psr/psr-3/?utm_source=chatgpt.com "PSR-3: Logger Interface"
[2]: https://www.php.net/manual/en/function.stream-isatty.php?utm_source=chatgpt.com "stream_isatty - Manual"
[3]: https://symfony.com/doc/current/components/console.html?utm_source=chatgpt.com "The Console Component (Symfony Docs)"
[4]: https://laravel.com/docs/12.x/artisan?utm_source=chatgpt.com "Artisan Console - Laravel 12.x - The PHP Framework For ..."
[5]: https://github.com/Seldaek/monolog?utm_source=chatgpt.com "Seldaek/monolog: Sends your logs to files, sockets, ..."
[6]: https://github.com/php-fig/log?utm_source=chatgpt.com "php-fig/log"
