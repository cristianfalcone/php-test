# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a **zero-dependency PHP micro-framework** that replicates features from large frameworks with simple, elegant, robust implementations. The codebase emphasizes simplicity, readability, performance, and cleverness over complexity. Think Laravel-like APIs but with micro implementations.

## Development Environment

**CRITICAL**: This project uses **Podman Compose** (not Docker Compose). Always use `podman compose` commands.

### Container Stack

- **app**: PHP 8.4-FPM with extensions (PDO MySQL, PCNTL, intl, sysvsem, sysvshm, pcov, xdebug)
- **web**: Nginx on port 8082
- **db**: MySQL 8.0
- **adminer**: Database admin UI on port 8083

### Starting Services

```bash
podman compose up -d
```

### Running Commands

All PHP commands must run inside the `app` container:

```bash
podman compose exec app php ajo <command>
```

## Test Runner

**CRITICAL**: This project uses a **custom test runner**, NOT PHPUnit. The test runner is implemented in [src/Test.php](src/Test.php).

### Running Tests

Run all tests:
```bash
podman compose exec app php ajo test
```

Run specific test suite:
```bash
podman compose exec app php ajo test --filter=Console
podman compose exec app php ajo test --filter=Job
```

Run tests in parallel (uses pcntl_fork):
```bash
podman compose exec app php ajo test --parallel
```

Run with code coverage (uses pcov):
```bash
podman compose exec app php ajo test --coverage
```

Generate coverage report to file:
```bash
podman compose exec app php ajo test --coverage=coverage.xml
```

Export test results to JUnit XML:
```bash
podman compose exec app php ajo test --log=junit.xml
```

List available tests:
```bash
podman compose exec app php ajo test:list
```

### Writing Tests

Tests use a custom test API with direct, descriptive naming:

```php
Test::suite('Feature Name', function () {
    Test::beforeEach(fn($state) => $state['value'] = 10);

    Test::case('increments the counter', function ($state) {
        Test::assertEquals(10, $state['value']);
    });
});
```

**Test naming conventions:**
- Use **direct present tense** (3rd person singular): `creates instance`, `parses arguments`, `throws on error`
- Avoid BDD-style "should" prefix (removed from the framework)
- Use `Test::suite()` to group tests (not `describe()`)
- Use `Test::case()` to define test cases (not `it()`)

**Available assertions:** `assertTrue`, `assertFalse`, `assertNull`, `assertNotNull`, `assertSame`, `assertEquals`, `assertCount`, `assertArrayHasKey`, `assertInstanceOf`, `assertStringContainsString`, `assertContains`, `expectException`.

**Available hooks:** `beforeEach`, `afterEach`, `before`, `after` - use for test setup/teardown with `$state` parameter for sharing data between hooks and tests.

## Architecture

### Core + Facade Pattern

The codebase uses a **two-layer architecture within single files**:

Each file in `src/` contains both layers:
1. **Core class** (e.g., `ConsoleCore`) - Concrete implementation that works as an independent instance without shared state
2. **Facade class** (e.g., `Console`) - Static facade that delegates to Core instance via the Facade trait

Example in [src/Console.php](src/Console.php):
- `ConsoleCore` - Instance-based console implementation
- `Console` - Static facade for ConsoleCore

### Facades

Facades use singleton instances by default (auto-created on first use):

```php
// Static usage (uses singleton)
Console::command('greet', fn() => Console::success('Hi!'));
Console::dispatch();

// Create custom instance
$cli = Console::create(
    notFoundHandler: fn() => 1,
    exceptionHandler: fn($e) => 1
);

// Swap facade instance
Console::swap($cli);

// Or use instance directly (preferred for tests)
$cli->command('test', fn() => 0);
```

### Container

The [Container](src/Container.php) is a simple dependency injection container supporting:
- `set(id, value)` - Store a value
- `singleton(id, factory)` - Register lazy singleton
- `factory(id, factory)` - Register factory (new instance each time)
- `get(id)` - Resolve service
- `has(id)` - Check if service exists

Facades automatically use the Container to manage their singleton instances.

## Common Commands

### Migrations

Install migrations table:
```bash
podman compose exec app php ajo migrate:install
```

Run pending migrations:
```bash
podman compose exec app php ajo migrate
```

Check migration status:
```bash
podman compose exec app php ajo migrate:status
```

Rollback last batch:
```bash
podman compose exec app php ajo migrate:rollback
```

Create new migration:
```bash
podman compose exec app php ajo migrate:make create_users_table
```

### Job Scheduler

Install jobs table:
```bash
podman compose exec app php ajo jobs:install
```

Check jobs status:
```bash
podman compose exec app php ajo jobs:status
```

Execute due jobs once:
```bash
podman compose exec app php ajo jobs:collect
```

Run job worker (continuous):
```bash
podman compose exec app php ajo jobs:work
```

Prune stale jobs:
```bash
podman compose exec app php ajo jobs:prune
```

### Other Commands

List all commands:
```bash
podman compose exec app php ajo help
```

Access container shell:
```bash
podman compose exec app sh
```

Check logs:
```bash
podman compose logs app
podman compose logs web
podman compose logs db
```

## Code Philosophy

**Zero dependencies** - Not even dev dependencies like test runners. Everything is implemented from scratch.

Code should be:
- **DRY** - Eliminate duplication aggressively
- **Simple** - Direct solutions over complex abstractions
- **Readable** - Self-explanatory, like prose
- **Robust** - Handle edge cases without complexity
- **Clever** - Use PHP 8.4 features intelligently (property hooks, asymmetric visibility, readonly classes, enums, match, first-class callables)
- **Performant** - Fast without sacrificing readability

### Pre-Market Development Mindset

**CRITICAL**: This project is **pre-release**. There are **no backward compatibility constraints**. The goal is to ship with the **best possible shape**.

When adding features or refactoring:

1. **No backward compatibility concerns** - Break anything if it improves the design
2. **Holistic coherence** - View each class as a unified whole, not a collection of features
3. **Framework-wide consistency** - Ensure:
   - Classes use the latest features when interacting with each other
   - Consistent code style, naming conventions, and patterns across all components
   - Documentation stays synchronized with implementation
4. **Always update tests** - Keep test suites comprehensive and up-to-date
5. **Micro, clever, elegant** - Prefer ingenious simplicity over verbose clarity

**Example**: When Console2 added `__call`/`__callStatic`, we didn't preserve the old methods - we eliminated them entirely, reduced 239 lines to 112, and achieved better DX through @method tags.

### Type Inference

Trust PHP's type inference. Remove redundant return types:

```php
// Avoid
private function count(): int { return 5; }

// Prefer
private function count() { return 5; }
```

Keep types when:
- Part of public API
- Type is `:never`
- Complex types (unions, intersections)
- They add significant clarity

### Simplification Techniques

- **Local closures** over private methods
- **Match expressions** over switch
- **Array functions** over loops
- **Guard clauses** to reduce nesting
- **Null coalescing** (`??`) and elvis (`?:`)

### The Simplest Solution Wins

**CRITICAL**: Always seek the simplest, most direct solution first. Don't over-engineer.

Ask yourself:
1. **"Is there a built-in that does this?"** - Use `getcwd()`, `realpath()`, `dirname()`, etc.
2. **"Can I assume standard conventions?"** - Tests run from project root, paths are absolute, etc.
3. **"Am I inferring what I can just know?"** - Don't calculate what you can directly query

**Example: Finding project root**

```php
// ❌ AVOID - Over-engineered (23 lines)
private function commonBasePath() {
    $paths = array_map(fn($p) => realpath(...), $this->sourcePaths);
    $parts = array_map(fn($p) => explode('/', ...), $paths);
    // ... complex logic to find common prefix
    // ... handle edge cases
    // ... return parent directory
}

// ✅ PREFER - Simple and direct (1 line)
private function commonBasePath() {
    return getcwd() . '/';  // Tests always run from project root
}
```

**Why this works:**
- Tests run from project root (universal convention)
- No need to infer - just use what we know
- Matches behavior of PHPUnit, pytest, Jest

**Red flags you're over-complicating:**
- Complex loops and array operations for simple tasks
- Inferring information you could directly obtain
- Handling edge cases that violate standard conventions
- More than 5-10 lines for a single responsibility

**When complexity is justified:**
- Parsing complex formats (cron expressions, XML)
- Performance-critical code (job scheduling, parallel execution)
- Edge cases that actually occur in practice

Example:
```php
// Avoid creating private methods for single use
$record = fn($type, $name) => $isParallel
    ? $this->incrementShared($shm, compact('type', 'name'))
    : $this->render($type, $name);

$record('pass', $test['name']);
```

### Anti-Patterns

- Creating private methods for single use
- Over-engineering with unnecessary classes/interfaces
- Premature abstractions
- Obvious documentation (code should be self-documenting)
- Over-specific tests that break with implementation changes

## Performance

When optimization is necessary:
- Use native PHP extensions (pcntl, sysvsem, sysvshm)
- Shared memory over files for IPC
- Atomic operations with semaphores instead of manual locks
- Profile before optimizing

## Debugging with Xdebug

**IMPORTANT**: This project has **Xdebug 3.4.6** installed and configured in the `app` container.

### When Tests Fail or Bugs Are Hard to Trace

Use the **xdebug-inspect skill** to generate instrumented debugging scripts that reveal:
- Variable states at specific execution points
- Memory usage and leaks
- Call stacks and execution flow
- Timing and performance bottlenecks

### Usage

```bash
# Invoke the skill when you need deep inspection
/skill xdebug-inspect

# The skill will generate a debug script like:
# debug_<issue>.php

# Run it in the container
podman compose exec app php debug_<issue>.php
```

### Xdebug Capabilities Available

- `xdebug_var_dump($var)` - Enhanced variable dump with structure
- `xdebug_get_function_stack()` - Complete call stack with files/lines
- `xdebug_time_index()` - Precise timing since script start
- `xdebug_memory_usage()` - Current memory usage
- `xdebug_peak_memory_usage()` - Peak memory consumption

### When to Use

- Tests failing with unclear reasons
- Investigating complex bugs or race conditions
- Analyzing memory or performance issues
- Understanding execution flow through complex code
- Debugging closures and their captured variables
- Tracking state mutations across multiple calls

See [.claude/skills/xdebug-inspect/SKILL.md](.claude/skills/xdebug-inspect/SKILL.md) for complete documentation and examples.

## Key Files

- [ajo](ajo) - CLI entry point that registers commands
- [src/Test.php](src/Test.php) - Custom test runner with parallel execution support
- [src/Facade.php](src/Facade.php) - Base trait for all facades
- [src/Container.php](src/Container.php) - Dependency injection container
- [src/Console.php](src/Console.php) - CLI framework (ConsoleCore + Console facade)
- [src/Job.php](src/Job.php) - Job scheduler (JobCore + Job facade)
- [src/Http.php](src/Http.php) - HTTP response builder (HttpCore + Http facade)
- [src/Router.php](src/Router.php) - Base routing class for Console and Http
