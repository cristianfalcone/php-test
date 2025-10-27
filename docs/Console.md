# Console - Complete Documentation

**Version**: 2.0 (Production-ready with Sade-style Options)
**Last updated**: 2025-10-26
**Status**: ✅ Fully integrated and production-ready

---

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Architecture](#architecture)
3. [Quick Start](#quick-start)
4. [Core Features](#core-features)
5. [API Reference](#api-reference)
6. [Argument Parsing](#argument-parsing)
7. [Command Registration](#command-registration)
8. [Options & Examples](#options--examples)
9. [Middleware System](#middleware-system)
10. [Output & Styling](#output--styling)
11. [Logger Mode](#logger-mode)
12. [Common Recipes](#common-recipes)
13. [Comparison with Other CLIs](#comparison-with-other-clis)
14. [Design Philosophy](#design-philosophy)

---

## Executive Summary

### What is Ajo\Core\Console?

A **dual-purpose zero-dependency PHP 8.4+ tool** that works as both:

1. **CLI Framework** - Command registration, argument parsing, middleware (inspired by [sade](https://github.com/lukeed/sade)/[mri](https://github.com/lukeed/mri))
2. **Structured Logger** - Timestamped, colored output with TTY detection

Unlike traditional loggers (Monolog) or CLI frameworks (Symfony Console), Console combines both concerns in a single, elegant API.

### Current Status

**As CLI Framework:**
- ✅ **Production-ready** - fully tested with 42 passing tests
- ✅ **Sade-style argument parsing** - flags, aliases, defaults, negation
- ✅ **Middleware system** - global and path-prefixed middleware
- ✅ **Help generation** - automatic help with options and examples
- ✅ **Facade pattern** - static API with instance swapping

**As Logger:**
- ✅ **TTY detection** - auto colors/timestamps based on context
- ✅ **Structured output** - `[timestamp] [level] message` format
- ✅ **Stream separation** - proper stdout/stderr routing
- ✅ **Zero config** - works out of the box for CLI and logging

### Key Features

| Feature | Status | Description |
|---------|--------|-------------|
| **Command Registration** | ✅ | Fluent API with `command()->describe()->option()` |
| **Argument Parsing** | ✅ | Sade/mri-style with flags, aliases, defaults |
| **Options System** | ✅ | `-v, --verbose` with short/long aliases |
| **Usage Lines** | ✅ | Custom usage patterns with `usage()` |
| **Examples** | ✅ | Documented examples in help with `example()` |
| **Middleware** | ✅ | Global and path-prefixed middleware chain |
| **ANSI Styling** | ✅ | Colors, bold, dim, backgrounds |
| **Auto Help** | ✅ | Generated help with options and examples |
| **TTY Detection** | ✅ | Auto colors/timestamps based on TTY |
| **Logger Mode** | ✅ | Timestamps, no colors, structured output |
| **Dual Output** | ✅ | Separate stdout/stderr streams |

---

## Architecture

### Core + Facade Pattern

Console uses a two-layer architecture:

```
┌─────────────────────────────────────────┐
│  Ajo\Console (Static Facade)            │
│  └─> Static calls delegate to instance  │
└─────────────────────────────────────────┘
                  ↓
┌─────────────────────────────────────────┐
│  Ajo\Core\Console (Implementation)      │
│  ├─> Command registration                │
│  ├─> Argument parsing                    │
│  ├─> Middleware pipeline                 │
│  └─> Output rendering                    │
└─────────────────────────────────────────┘
                  ↓
┌─────────────────────────────────────────┐
│  Ajo\Core\Router (Base)                 │
│  └─> Middleware chain execution          │
└─────────────────────────────────────────┘
```

### Inheritance

```php
final class Console extends Router
```

Console extends Router to leverage the middleware pipeline system.

---

## Quick Start

### As CLI Framework

#### Basic Command

```php
use Ajo\Console;

Console::command('greet', function () {
    Console::success('Hello, World!');
    return 0;
});

exit(Console::dispatch());
```

#### Command with Options

```php
Console::command('build', function () {
    $opts = Console::options();

    $verbose = $opts['verbose'] ?? false;
    $output = $opts['output'] ?? 'dist/bundle.js';
    $files = $opts['_']; // positional arguments

    Console::log("Building " . count($files) . " files...");
    if ($verbose) Console::log("Output: $output");

    return 0;
})
->describe('Build the project')
->usage('<src> [<src>...] [options]')
->option('-v, --verbose', 'Enable verbose output')
->option('-o, --output', 'Output file', 'dist/bundle.js')
->example('src/ dist/ -v', 'Build with verbose output')
->example('--output=custom.js', 'Custom output file');
```

### As Logger

#### Interactive Usage (TTY)

```php
use Ajo\Console;

Console::info('Starting application...');
Console::success('Database connected');
Console::warn('Cache miss for key: users');
Console::error('Failed to send email');
```

**Output (colored, no timestamps):**
```
[info] Starting application...
[ok] Database connected
[warn] Cache miss for key: users
[error] Failed to send email
```

#### Piped/Logged Usage (non-TTY)

```bash
$ php worker.php > app.log 2>&1
```

**app.log (no colors, with timestamps):**
```
[2025-10-26 15:30:45] [info] Starting application...
[2025-10-26 15:30:46] [ok] Database connected
[2025-10-26 15:30:47] [warn] Cache miss for key: users
[2025-10-26 15:30:48] [error] Failed to send email
```

### Instance-based Usage (for tests)

```php
use Ajo\Core\Console as CoreConsole;

$cli = CoreConsole::create();
$cli->command('test', fn() => 0);
$code = $cli->dispatch('test', []);
```

---

## Core Features

### 1. Sade-style Argument Parsing

Inspired by [mri](https://github.com/lukeed/mri), Console parses arguments into a clean structure:

```php
// Input:  php console build src/ --verbose --output=bundle.js
// Output: ['_' => ['src/'], 'verbose' => true, 'output' => 'bundle.js']

$opts = Console::options();
$opts['_'];       // Positional arguments
$opts['verbose']; // Boolean flags
$opts['output'];  // String values
```

**Supported patterns:**
- Boolean flags: `-v`, `--verbose`
- Short aliases: `-v` → `verbose`
- Values: `--output=file.js`, `--output file.js`
- Negation: `--no-color` sets `color: false`
- Arrays: repeated flags accumulate values
- Separator: `--` stops flag parsing
- Defaults: from `option()` declarations

### 2. Fluent API

```php
Console::command('deploy', fn() => 0)
    ->describe('Deploy the application')
    ->usage('[options]')
    ->usage('<env> [options]')
    ->option('-e, --env', 'Environment', 'production')
    ->option('--dry-run', 'Dry run mode')
    ->example('--env=staging', 'Deploy to staging')
    ->example('production --dry-run', 'Test production deploy');
```

### 3. Automatic Help Generation

```bash
$ php console help deploy

Command: deploy

Description:
  Deploy the application

Usage:
  console deploy [options]
  console deploy <env> [options]

Options:
  -e, --env  Environment (default: production)
      --dry-run  Dry run mode

Examples:
  console deploy --env=staging
    Deploy to staging
  console deploy production --dry-run
    Test production deploy
```

### 4. Middleware System

```php
// Global middleware (runs for all commands)
Console::use(function ($error, $next) {
    Console::log('Starting command...');
    $result = $next($error);
    Console::log('Command finished.');
    return $result;
});

// Path-prefixed middleware (runs for specific commands)
Console::use('job:', function ($error, $next) {
    // Setup database connection
    Container::set('db', Database::get());
    return $next($error);
});
```

---

## API Reference

### Command Registration

#### `command(string $name, callable $handler): self`

Registers a command handler.

```php
Console::command('greet', function () {
    Console::log('Hello!');
    return 0;
});
```

**Handler signature:**
```php
function(): int|bool|string|null
```

**Return values:**
- `0` or `null`: success (exit code 0)
- `1` or `false`: failure (exit code 1)
- `int`: specific exit code
- `string`: printed to stdout, exit code 0

#### `describe(string $description): self`

Sets the command description shown in help.

```php
->describe('Deploy the application to production')
```

#### `usage(string $usage): self`

Adds a usage pattern. Can be called multiple times.

```php
->usage('[options]')
->usage('<file> [<file>...] [options]')
```

**Conventions:**
- `<arg>`: required argument
- `[arg]`: optional argument
- `[arg...]`: multiple values
- `[options]`: indicates flags accepted

#### `option(string $flags, string $description = '', mixed $default = null): self`

Registers an option with optional alias and default value.

```php
->option('-v, --verbose', 'Enable verbose output')
->option('-o, --output', 'Output file', 'bundle.js')
->option('--no-color', 'Disable colors')
```

**Flags format:**
- `-v, --verbose`: short and long
- `--verbose`: long only
- Order doesn't matter: `-v, --verbose` or `--verbose, -v`

**Negation:**
- `--no-*` flags automatically set value to `false`
- Example: `--no-color` makes `$opts['color'] = false`

#### `example(string $usage, ?string $description = null): self`

Adds a usage example with optional description.

```php
->example('src/ dist/', 'Build from src to dist')
->example('--watch', 'Watch mode')
```

### Argument Access

#### `options(): array`

Returns parsed options with positional args in `_`.

```php
$opts = Console::options();
$files = $opts['_'];          // Positional arguments
$verbose = $opts['verbose'];  // Flag value
$output = $opts['output'] ?? 'default.js'; // With fallback
```

**Structure:**
```php
[
    '_' => ['arg1', 'arg2'],  // Positional arguments
    'verbose' => true,         // Boolean flag
    'output' => 'file.js',     // String value
    'count' => [1, 2, 3],      // Repeated flag (array)
]
```

#### `arguments(): array`

Returns raw command arguments (before parsing).

```php
$args = Console::arguments();
// ['src/', '--verbose', '--output=bundle.js']
```

### Execution

#### `dispatch(?string $command = null, array $arguments = [], $stdout = null, $stderr = null): int`

Executes the command and returns exit code.

```php
exit(Console::dispatch());
```

**Parameters:**
- `$command`: Command name (null = from argv)
- `$arguments`: Arguments array (empty = from argv)
- `$stdout`: Custom stdout stream (null = STDOUT)
- `$stderr`: Custom stderr stream (null = STDERR)

### Middleware

#### `use(string|callable $prefix, ?callable $handler = null): self`

Registers middleware in the pipeline.

```php
// Global middleware
Console::use(function ($error, $next) {
    // Run before command
    $result = $next($error);
    // Run after command
    return $result;
});

// Prefixed middleware
Console::use('test:', function ($error, $next) {
    // Only runs for commands starting with "test:"
    return $next($error);
});
```

**Middleware signature:**
```php
function(?Throwable $error, callable $next): mixed
```

### Output Methods

#### `log(string $message): void`

Writes a message to stdout.

```php
Console::log('Processing files...');
```

#### `success(string $message): void`
#### `info(string $message): void`
#### `warn(string $message): void`
#### `error(string $message): void`

Colored output methods.

```php
Console::success('Build completed!');  // Green [ok]
Console::info('Starting build...');    // Blue [info]
Console::warn('Deprecated option');    // Yellow [warn]
Console::error('Build failed!');       // Red [error]
```

#### `blank(int $lines = 1): void`

Writes blank lines.

```php
Console::blank();    // 1 blank line
Console::blank(3);   // 3 blank lines
```

### Styling

#### `bold(string $text = ''): StyleBuilder|string`
#### `dim(string $text = ''): StyleBuilder|string`
#### `italic(string $text = ''): StyleBuilder|string`
#### `underline(string $text = ''): StyleBuilder|string`

Text styles.

```php
Console::log(Console::bold('Important!'));
Console::log(Console::dim('(optional)'));
```

#### Color methods

```php
Console::red(string $text = ''): StyleBuilder|string
Console::green(string $text = ''): StyleBuilder|string
Console::yellow(string $text = ''): StyleBuilder|string
Console::blue(string $text = ''): StyleBuilder|string
Console::magenta(string $text = ''): StyleBuilder|string
Console::cyan(string $text = ''): StyleBuilder|string
Console::white(string $text = ''): StyleBuilder|string
Console::gray(string $text = ''): StyleBuilder|string
```

#### Background colors

```php
Console::bgRed(string $text = ''): StyleBuilder|string
Console::bgGreen(string $text = ''): StyleBuilder|string
Console::bgYellow(string $text = ''): StyleBuilder|string
Console::bgBlue(string $text = ''): StyleBuilder|string
```

#### Fluent styling

```php
// Chaining
Console::bold()->red()->log('Error!');
Console::dim()->italic('Note: ...');

// Inline
$text = Console::bold()->green('Success!');
```

### Configuration

#### `timestamps(bool $enable = true): self`

Enable/disable timestamps in output.

```php
Console::timestamps(true);
Console::log('Message'); // [2025-10-26 15:30:45] Message
```

#### `colors(bool $enable = true): self`

Enable/disable ANSI colors.

```php
Console::colors(false); // Disable colors
```

**Note:** Colors and timestamps auto-enable based on TTY detection.

---

## Argument Parsing

### Parser Behavior

Console uses a sade/mri-inspired parser that converts argv into a clean options object:

```bash
php console build src/ dist/ --verbose --output=bundle.js --ignore=node_modules --ignore=dist
```

**Parsed to:**
```php
[
    '_' => ['src/', 'dist/'],
    'verbose' => true,
    'output' => 'bundle.js',
    'ignore' => ['node_modules', 'dist']
]
```

### Flag Patterns

#### Boolean Flags

```bash
-v              # short flag
--verbose       # long flag
-v --verbose    # both (both set to true)
```

#### Flags with Values

```bash
--output=file.js       # equals syntax
--output file.js       # space syntax
-o file.js             # short with value
```

#### Negation

```bash
--no-color             # sets color: false
--no-timestamps        # sets timestamps: false
```

**Implementation:**
```php
->option('--no-color', 'Disable colors')

$opts = Console::options();
$opts['color']; // false when --no-color is passed
```

#### Repeated Flags (Arrays)

```bash
--ignore=node_modules --ignore=dist
```

```php
$opts['ignore']; // ['node_modules', 'dist']
```

#### Stop Parsing (`--`)

Everything after `--` is treated as positional:

```bash
php console run -v -- --not-a-flag
```

```php
[
    '_' => ['--not-a-flag'],
    'verbose' => true
]
```

### Aliases

Short flags automatically map to long flags:

```php
->option('-v, --verbose', 'Verbose mode')

// Both work:
// -v         → verbose: true
// --verbose  → verbose: true
```

### Defaults

```php
->option('-o, --output', 'Output file', 'bundle.js')

$opts = Console::options();
$opts['output']; // 'bundle.js' if not provided
```

### Mixed Positional and Flags

```bash
php console test Console --bail --parallel=4
```

```php
[
    '_' => ['Console'],
    'bail' => true,
    'parallel' => '4'
]
```

**Usage pattern:**
```php
$filter = $opts['filter'] ?? $opts['_'][0] ?? null;
// Supports both --filter=X and positional argument
```

---

## Command Registration

### Basic Registration

```php
Console::command('greet', function () {
    Console::success('Hello!');
    return 0;
});
```

### With Description

```php
Console::command('deploy', fn() => 0)
    ->describe('Deploy the application to production');
```

### With Options

```php
Console::command('build', fn() => 0)
    ->option('-v, --verbose', 'Verbose output')
    ->option('-o, --output', 'Output file', 'dist/bundle.js')
    ->option('--no-sourcemap', 'Disable sourcemaps');
```

### With Usage Patterns

```php
Console::command('test', fn() => 0)
    ->usage('[filter] [options]')
    ->usage('[options]');
```

### With Examples

```php
Console::command('migrate', fn() => 0)
    ->example('', 'Run all pending migrations')
    ->example('--rollback', 'Rollback last batch')
    ->example('--fresh', 'Drop and recreate all tables');
```

### Complete Example

```php
Console::command('test', function () {
    $opts = Console::options();

    $filter = $opts['filter'] ?? $opts['_'][0] ?? null;
    $bail = $opts['bail'] ?? false;
    $parallel = $opts['parallel'] ?? 1;

    // Run tests...

    return 0;
})
->describe('Run test suite')
->usage('[filter] [options]')
->usage('[options]')
->option('-b, --bail', 'Stop on first failure')
->option('-p, --parallel', 'Run in parallel')
->option('--filter', 'Filter by suite/test/file')
->example('Console', 'Run Console tests')
->example('--filter=Job', 'Run Job tests')
->example('--parallel=4 --bail', 'Parallel with bail');
```

---

## Options & Examples

### Option Declaration

Options are declared with `option()` and automatically appear in help:

```php
->option('-v, --verbose', 'Enable verbose output')
->option('-o, --output', 'Output file', 'bundle.js')
->option('--no-color', 'Disable colored output')
```

**Components:**
1. **Flags**: `-v, --verbose` (short and long aliases)
2. **Description**: Shown in help
3. **Default**: Optional default value

### Usage Patterns

Define how the command should be invoked:

```php
->usage('<src> <dest> [options]')      // Required args
->usage('[file] [options]')             // Optional arg
->usage('[filter] [options]')           // Named optional
->usage('[file...] [options]')          // Multiple files
```

### Examples

Show concrete usage examples:

```php
->example('src/ dist/', 'Build from src to dist')
->example('--watch', 'Watch for changes')
->example('-e production', 'Production build')
```

**With description:**
```php
->example('Console', 'Run tests matching "Console" (suite, test, or file)')
```

**Without description:**
```php
->example('--help')
```

---

## Middleware System

### Global Middleware

Runs for all commands:

```php
Console::use(function ($error, $next) {
    $start = microtime(true);

    $result = $next($error);

    $elapsed = microtime(true) - $start;
    Console::dim()->log(sprintf('Completed in %.2fs', $elapsed));

    return $result;
});
```

### Prefixed Middleware

Runs only for commands matching a prefix:

```php
Console::use('job:', function ($error, $next) {
    // Setup database for job commands
    Container::set('db', Database::get());
    return $next($error);
});

Console::use('test:', function ($error, $next) {
    // Test-specific setup
    return $next($error);
});
```

### Error Handling

```php
Console::use(function ($error, $next) {
    if ($error !== null) {
        Console::error('Uncaught error: ' . $error->getMessage());
        return 1;
    }

    try {
        return $next($error);
    } catch (Throwable $e) {
        Console::error('Command failed: ' . $e->getMessage());
        return 1;
    }
});
```

### Execution Order

Middleware executes in registration order:

```php
Console::use(fn($e, $n) => (Console::log('1') || true) && $n($e));
Console::use(fn($e, $n) => (Console::log('2') || true) && $n($e));
Console::use(fn($e, $n) => (Console::log('3') || true) && $n($e));

// Output: 1, 2, 3, [command runs], 3, 2, 1
```

---

## Output & Styling

### Basic Output

```php
Console::log('Regular message');
Console::success('Operation successful!');
Console::info('Processing...');
Console::warn('Deprecated feature');
Console::error('Operation failed!');
```

### Styled Output

```php
Console::log(Console::bold('Important'));
Console::log(Console::dim('(optional)'));
Console::log(Console::underline('Link'));
```

### Colored Output

```php
Console::log(Console::red('Error'));
Console::log(Console::green('Success'));
Console::log(Console::yellow('Warning'));
Console::log(Console::blue('Info'));
```

### Background Colors

```php
Console::log(Console::bgRed(' FAILED '));
Console::log(Console::bgGreen(' PASSED '));
```

### Fluent Styling

```php
Console::bold()->red()->log('Critical Error!');
Console::dim()->italic()->log('Note: optional');

$styled = Console::bold()->green('Success!');
Console::log($styled);
```

### Timestamps

```php
Console::timestamps(true);
Console::log('Message');
// [2025-10-26 15:30:45] Message
```

### Color Control

```php
Console::colors(false); // Disable colors
Console::colors(true);  // Enable colors
```

**Auto-detection:**
- Colors enabled if stdout is a TTY
- Timestamps enabled if stdout is NOT a TTY

---

## Logger Mode

Console doubles as a structured logger, automatically adapting its output based on the execution context.

### TTY Detection

Console automatically detects if it's running in an interactive terminal or being piped/logged:

```php
// Interactive terminal (TTY):
Console::log('Message');
// Output: Message (with colors)

// Piped or redirected (non-TTY):
Console::log('Message');
// Output: [2025-10-26 15:30:45] Message (no colors, with timestamp)
```

**Detection logic:**
```php
$isTTY = posix_isatty(STDOUT);

// TTY:        colors=ON,  timestamps=OFF
// non-TTY:    colors=OFF, timestamps=ON
```

### Use Cases

#### 1. Direct CLI Usage (Interactive)

```bash
$ php console build
Building project...      # Colored, no timestamps
✓ Build successful!      # Green checkmark
```

#### 2. Piped to File (Logging)

```bash
$ php console build > build.log 2>&1
```

**build.log:**
```
[2025-10-26 15:30:45] Building project...
[2025-10-26 15:30:47] Build successful!
```

#### 3. Background Process (Daemon)

```bash
$ nohup php console jobs:work >> worker.log 2>&1 &
```

**worker.log:**
```
[2025-10-26 15:30:45] [info] Starting worker...
[2025-10-26 15:30:46] [ok] Job processed: emails.send
[2025-10-26 15:30:47] [ok] Job processed: report.daily
```

#### 4. Systemd Service

```ini
[Service]
ExecStart=/usr/bin/php /app/console jobs:work
StandardOutput=journal
StandardError=journal
```

**journalctl output:**
```
Oct 26 15:30:45 server app[1234]: [2025-10-26 15:30:45] [info] Starting worker...
Oct 26 15:30:46 server app[1234]: [2025-10-26 15:30:46] [ok] Job processed
```

### Manual Control

Override auto-detection when needed:

```php
// Force logger mode (timestamps, no colors)
Console::timestamps(true)->colors(false);

// Force interactive mode (colors, no timestamps)
Console::timestamps(false)->colors(true);
```

### Structured Output

Console's output is structured for parsing:

```php
Console::info('Starting job: emails.send');
Console::success('Job completed: emails.send');
Console::error('Job failed: report.daily');
```

**Interactive (TTY):**
```
[info] Starting job: emails.send
[ok] Job completed: emails.send
[error] Job failed: report.daily
```

**Logged (non-TTY):**
```
[2025-10-26 15:30:45] [info] Starting job: emails.send
[2025-10-26 15:30:46] [ok] Job completed: emails.send
[2025-10-26 15:30:47] [error] Job failed: report.daily
```

### Stream Separation

Console properly separates stdout and stderr:

```php
Console::log('Regular output');      // → stdout
Console::success('Success message'); // → stdout
Console::error('Error message');     // → stderr
Console::warn('Warning message');    // → stderr
```

**Capture separately:**
```bash
# Only stdout
$ php console test > output.log

# Only stderr
$ php console test 2> errors.log

# Both to same file
$ php console test > combined.log 2>&1

# Separate files
$ php console test > output.log 2> errors.log
```

### Log Levels

Console supports standard log levels through method naming:

```php
Console::log('Debug info');           // Plain output
Console::info('Informational');       // [info] prefix
Console::success('Operation OK');     // [ok] prefix
Console::warn('Warning message');     // [warn] prefix
Console::error('Error occurred');     // [error] prefix
```

**Grep-friendly:**
```bash
# Filter by level
$ tail -f app.log | grep '\[error\]'
$ tail -f app.log | grep '\[warn\]'
$ tail -f app.log | grep '\[ok\]'
```

### Example: Long-Running Worker

```php
Console::command('jobs:work', function () {
    Console::timestamps(true); // Force timestamps for logging

    Console::info('Worker started');

    while (true) {
        try {
            $job = Job::claim();

            if ($job) {
                Console::log("Processing: {$job['name']}");
                Job::execute($job);
                Console::success("Completed: {$job['name']}");
            } else {
                sleep(1);
            }
        } catch (Throwable $e) {
            Console::error("Failed: {$e->getMessage()}");
        }
    }
});
```

**Output (piped to file):**
```
[2025-10-26 15:30:45] [info] Worker started
[2025-10-26 15:30:46] Processing: emails.send
[2025-10-26 15:30:47] [ok] Completed: emails.send
[2025-10-26 15:30:48] Processing: report.daily
[2025-10-26 15:30:50] [ok] Completed: report.daily
```

### vs Traditional Loggers

| Feature | Console | Monolog | PSR-3 |
|---------|---------|---------|-------|
| **Setup** | Zero config | Handlers + formatters | Interface only |
| **TTY Adapt** | Automatic | Manual | N/A |
| **CLI + Log** | Both | Log only | Interface |
| **Colors** | Built-in | Via handlers | N/A |
| **Timestamps** | Auto-detect | Via formatters | N/A |
| **Dependencies** | Zero | ~15 packages | Interface |

**When to use Console as logger:**
- ✅ CLI applications
- ✅ Background workers
- ✅ Simple logging needs
- ✅ Single output destination
- ✅ Zero-dependency requirement

**When to use Monolog:**
- Multiple handlers (file + syslog + email)
- Complex filtering/processing
- PSR-3 interface required
- Rotating files, bubble control

### Best Practices

**1. Always use structured prefixes:**
```php
// Good
Console::info('Starting job: emails.send');

// Avoid
Console::log('INFO: Starting job: emails.send');
```

**2. Let TTY detection work:**
```php
// Good - auto-detects
Console::log('Message');

// Avoid - manual override unless needed
Console::timestamps(true)->log('Message');
```

**3. Use appropriate methods:**
```php
// Good
Console::error('Failed');    // → stderr
Console::warn('Warning');    // → stderr
Console::success('Done');    // → stdout

// Avoid
Console::log('[ERROR] Failed');  // All to stdout
```

**4. Test both modes:**
```bash
# Test interactive
$ php console test

# Test logged
$ php console test 2>&1 | cat
```

---

## Common Recipes

### 1. Dual Syntax (Flag or Positional)

Support both `--filter=X` and positional argument:

```php
Console::command('test', function () {
    $opts = Console::options();
    $filter = $opts['filter'] ?? $opts['_'][0] ?? null;

    // Both work:
    // php console test Console
    // php console test --filter=Console
})
->usage('[filter] [options]')
->usage('[options]')
->option('--filter', 'Filter tests');
```

### 2. Required Arguments

```php
Console::command('copy', function () {
    $opts = Console::options();

    if (count($opts['_']) < 2) {
        Console::error('Usage: console copy <src> <dest>');
        return 1;
    }

    [$src, $dest] = $opts['_'];
    // Copy logic...

    return 0;
})
->usage('<src> <dest>');
```

### 3. Boolean Flags with Defaults

```php
Console::command('build', function () {
    $opts = Console::options();

    $minify = $opts['minify'] ?? true;  // Default true
    $sourcemap = $opts['sourcemap'] ?? true;

    if ($minify) Console::log('Minifying...');
    if ($sourcemap) Console::log('Generating sourcemaps...');

    return 0;
})
->option('--no-minify', 'Skip minification')
->option('--no-sourcemap', 'Skip sourcemaps');
```

### 4. Repeated Flags

```php
Console::command('lint', function () {
    $opts = Console::options();

    $ignore = (array)($opts['ignore'] ?? []);

    foreach ($ignore as $pattern) {
        Console::log("Ignoring: $pattern");
    }

    return 0;
})
->option('--ignore', 'Patterns to ignore')
->example('--ignore=node_modules --ignore=dist');
```

### 5. Progress Output

```php
Console::command('download', function () {
    $files = ['file1.zip', 'file2.zip', 'file3.zip'];

    foreach ($files as $i => $file) {
        $progress = sprintf('[%d/%d]', $i + 1, count($files));
        Console::dim()->log("$progress Downloading $file...");
    }

    Console::success('All files downloaded!');
    return 0;
});
```

### 6. Confirmation Prompt

```php
Console::command('drop', function () {
    $opts = Console::options();

    if (!($opts['force'] ?? false)) {
        Console::warn('This will delete all data!');
        Console::log('Use --force to confirm.');
        return 1;
    }

    // Drop database...
    Console::success('Database dropped.');
    return 0;
})
->option('--force', 'Confirm destructive action');
```

### 7. CPU-based Parallelism

```php
Console::command('test', function () {
    $opts = Console::options();

    $workers = match (true) {
        !isset($opts['parallel']) => 1,
        $opts['parallel'] === true => detectCpuCount(),
        is_numeric($opts['parallel']) => max(1, (int)$opts['parallel']),
        default => 1,
    };

    Console::log("Running with $workers workers...");
    return 0;
})
->option('-p, --parallel', 'Parallel workers (auto-detect CPU count)');
```

---

## Comparison with Other CLIs

### vs Symfony Console

| Feature | Console | Symfony Console |
|---------|---------|-----------------|
| **Dependencies** | Zero | ~20 packages |
| **Lines of Code** | ~500 | ~5,000+ |
| **Argument Parser** | Built-in (mri-style) | `InputDefinition` |
| **Styling** | Fluent API | `OutputStyle` |
| **Middleware** | Yes | No (Events) |
| **Learning Curve** | Minutes | Hours |

**When to use Console:**
- Zero-dependency projects
- Simple CLIs (< 50 commands)
- Micro-frameworks
- When you want sade-style UX

**When to use Symfony:**
- Large applications (100+ commands)
- Need progress bars, tables, questions
- Already using Symfony ecosystem

### vs Laravel Artisan

| Feature | Console | Laravel Artisan |
|---------|---------|-----------------|
| **Framework** | Standalone | Laravel-only |
| **Argument Parser** | mri-style | Symfony-based |
| **Signatures** | Options API | String signatures |
| **Testing** | Direct instance | Mock console |

**Console advantages:**
- Works outside Laravel
- Simpler argument parsing
- Fluent option declaration

**Artisan advantages:**
- String-based signatures
- Built-in Laravel helpers
- Integrated with framework

### vs Node.js Sade

Console is directly inspired by sade:

| Feature | Console (PHP) | Sade (Node) |
|---------|---------------|-------------|
| **API Style** | Nearly identical | Original |
| **Argument Parser** | mri-inspired | Uses mri |
| **Examples** | `example()` | `example()` |
| **Options** | `option()` | `option()` |
| **Help** | Auto-generated | Auto-generated |

**Console improvements:**
- Middleware system (not in sade)
- Fluent styling API
- TTY auto-detection
- Type safety (PHP 8.4)

---

## Design Philosophy

### 1. Zero Dependencies

Console uses only PHP standard library:
- No composer dependencies
- No external parsers
- No templating engines

### 2. Inspired by Best CLIs

**From sade/mri (Node.js):**
- Clean argument parsing
- Fluent API
- Auto-generated help

**From click (Python):**
- Decorator-style registration
- Context passing

**From cobra (Go):**
- Subcommand organization
- Usage patterns

### 3. Micro-implementation

~500 lines for complete CLI framework:
- Command registration
- Argument parsing
- Middleware system
- Output styling
- Help generation

### 4. Type Safety

Leverages PHP 8.4 features:
- Readonly properties
- Property hooks
- Match expressions
- First-class callables
- Union types

### 5. Simplicity First

**Avoid:**
- Complex class hierarchies
- Over-abstraction
- Magic methods (except necessary)
- Configuration files

**Prefer:**
- Direct code
- Fluent APIs
- Inline configuration
- Convention over configuration

### 6. DX (Developer Experience)

**Fast to learn:**
```php
Console::command('hello', fn() => 0);  // Works!
```

**Grows with needs:**
```php
Console::command('hello', fn() => 0)
    ->describe('Say hello')
    ->option('-n, --name', 'Name', 'World')
    ->example('-n Alice', 'Greet Alice');
```

**Easy to test:**
```php
$cli = Console::create();
$cli->command('test', fn() => 0);
$code = $cli->dispatch('test', []);
```

### 7. Performance

**Fast startup:**
- No autoloading overhead
- Minimal object creation
- Lazy initialization

**Memory efficient:**
- Single parse pass
- Reuse instances
- No AST building

---

## FAQ

### Q: Can I use Console without the Facade?

Yes! Use `Ajo\Core\Console` directly:

```php
use Ajo\Core\Console as CoreConsole;

$cli = CoreConsole::create();
$cli->command('test', fn() => 0);
exit($cli->dispatch());
```

### Q: How do I test commands?

Create an instance and capture output:

```php
$cli = Console::create();
$cli->command('test', fn() => 0);

$stdout = fopen('php://memory', 'r+');
$code = $cli->dispatch('test', [], $stdout);

rewind($stdout);
$output = stream_get_contents($stdout);
```

### Q: Can I have global options?

Use middleware to handle global flags:

```php
Console::use(function ($error, $next) {
    $opts = Console::options();

    if ($opts['version'] ?? false) {
        Console::log('Version 1.0.0');
        return 0;
    }

    return $next($error);
});
```

### Q: How do I handle errors?

Return error codes or throw exceptions:

```php
Console::command('test', function () {
    if ($error) {
        Console::error('Test failed!');
        return 1;  // Exit code 1
    }
    return 0;
});

// Or throw:
Console::command('test', function () {
    throw new RuntimeException('Failed!');
    // Caught by exception handler
});
```

### Q: Can I customize help output?

Yes, override the help command:

```php
Console::command('help', function () {
    // Custom help implementation
    return 0;
});
```

### Q: How do I add colors conditionally?

Check if TTY or use `colors()`:

```php
if (Console::isInteractive()) {
    Console::log(Console::green('Success!'));
} else {
    Console::log('Success!');
}
```

### Q: Can I have nested subcommands?

Yes, use colons in command names:

```php
Console::command('job:work', fn() => 0);
Console::command('job:status', fn() => 0);
Console::command('job:prune', fn() => 0);
```

Then use prefixed middleware:

```php
Console::use('job:', fn($e, $n) => /* setup */ $n($e));
```

---

**End of Documentation**

For more examples, see the test suite in `tests/Unit/Console.php`.

For implementation details, see `src/Core/Console.php`.
