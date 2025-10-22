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
podman compose exec app php console <command>
```

## Test Runner

**CRITICAL**: This project uses a **custom test runner**, NOT PHPUnit. The test runner is implemented in [src/Test.php](src/Test.php).

### Running Tests

Run all tests:
```bash
podman compose exec app php console test
```

Run specific test suite:
```bash
podman compose exec app php console test --filter=Console
podman compose exec app php console test --filter=Job
```

Run tests in parallel (uses pcntl_fork):
```bash
podman compose exec app php console test --parallel
```

Run with code coverage (uses pcov):
```bash
podman compose exec app php console test --coverage
```

Generate coverage report to file:
```bash
podman compose exec app php console test --coverage=coverage.xml
```

Export test results to JUnit XML:
```bash
podman compose exec app php console test --log=junit.xml
```

List available tests:
```bash
podman compose exec app php console test:list
```

### Writing Tests

Tests use a custom BDD-style API:

```php
Test::describe('Feature Name', function () {
    Test::beforeEach(fn($state) => $state['value'] = 10);

    Test::it('should do something', function ($state) {
        Test::assertEquals(10, $state['value']);
    });
});
```

Available assertions: `assertTrue`, `assertFalse`, `assertNull`, `assertNotNull`, `assertSame`, `assertEquals`, `assertCount`, `assertArrayHasKey`, `assertInstanceOf`, `assertStringContainsString`, `assertContains`, `expectException`.

## Architecture

### Core + Facade Pattern

The codebase uses a **two-layer architecture**:

1. **`src/Core/`** - Concrete implementations that work as independent instances without shared state
2. **`src/`** - Static facades that delegate to Core instances via the Facade trait

Example:
- [src/Core/Console.php](src/Core/Console.php) - Instance-based console implementation
- [src/Console.php](src/Console.php) - Static facade for Console

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

The [Container](src/Core/Container.php) is a simple dependency injection container supporting:
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
podman compose exec app php console migrate:install
```

Run pending migrations:
```bash
podman compose exec app php console migrate
```

Check migration status:
```bash
podman compose exec app php console migrate:status
```

Rollback last batch:
```bash
podman compose exec app php console migrate:rollback
```

Create new migration:
```bash
podman compose exec app php console migrate:make create_users_table
```

### Job Scheduler

Install jobs table:
```bash
podman compose exec app php console jobs:install
```

Check jobs status:
```bash
podman compose exec app php console jobs:status
```

Execute due jobs once:
```bash
podman compose exec app php console jobs:collect
```

Run job worker (continuous):
```bash
podman compose exec app php console jobs:work
```

Prune stale jobs:
```bash
podman compose exec app php console jobs:prune
```

### Other Commands

List all commands:
```bash
podman compose exec app php console help
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

## Key Files

- [console](console) - CLI entry point that registers commands
- [src/Test.php](src/Test.php) - Custom test runner with parallel execution support
- [src/Core/Facade.php](src/Core/Facade.php) - Base trait for all facades
- [src/Core/Container.php](src/Core/Container.php) - Dependency injection container
- [src/Core/Console.php](src/Core/Console.php) - CLI framework implementation
- [src/Core/Job.php](src/Core/Job.php) - Job scheduler implementation
- [AGENTS.md](AGENTS.md) - Detailed coding philosophy and examples
