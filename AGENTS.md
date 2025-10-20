# AGENTS.md

Guide for AI agents working on this project.

## What Are We Building?

A **super performant and simple** alternative to heavy and extensive PHP frameworks.

The goal is to have lightweight, fast tools without unnecessary dependencies that do exactly what's needed without excessive abstractions or complex configuration.

## Code Philosophy

This project values **simplicity, elegance and clarity** above all else. Code should be:

- **DRY** (Don't Repeat Yourself) - Eliminate duplication aggressively
- **Simple** - Prefer direct solutions over complex abstractions
- **Readable** - Code should read like prose, be self-explanatory
- **Robust** - Handle edge cases without unnecessary complexity
- **Clever** - Use language features intelligently and elegantly
- **Performant** - Optimize for speed without sacrificing readability

## PHP Code Style

### Type Inference

Trust PHP's type inference. **Remove redundant types**:

```php
// ❌ Avoid - redundant types
private function simple(): void { }
private function count(): int { return 5; }
private function name(): string { return 'foo'; }
private function items(): array { return []; }

// ✅ Prefer - let PHP infer
private function simple() { }
private function count() { return 5; }
private function name() { return 'foo'; }
private function items() { return []; }
```

**Keep types when**:
- They're part of the public API
- The type is `:never`
- The type is complex or ambiguous (unions, intersections)
- They add significant clarity

### Simplification

Constantly look for simplification opportunities:

```php
// ❌ Avoid - verbose
$results = [];
foreach ($items as $item) {
    $results[] = transform($item);
}

// ✅ Prefer - concise
$results = array_map(fn($item) => transform($item), $items);
```

```php
// ❌ Avoid - multiple repeated conditions
if (!$isParallel) {
    $this->render('pass', $name, $seconds);
}
if ($isParallel) {
    $this->incrementShared($stateFile, $data);
}

// ✅ Prefer - unified closure
$record = fn($type, $name, $seconds = null) => $isParallel
    ? $this->incrementShared($stateFile, compact('type', 'name', 'seconds'))
    : $this->render($type, $name, $seconds);

$record('pass', $name, $seconds);
```

### Long Methods

**Don't create new methods unnecessarily**. Prefer:

1. **Local closures** to extract repetitive logic
2. **Inline variables** to simplify expressions
3. **Guard clauses** to reduce indentation
4. **Array functions** (`array_map`, `array_filter`, `array_walk`) over loops

```php
// ✅ Use local closures
private function run() {
    $runHooks = fn($hooks, $label) =>
        $this->guard(fn() => array_walk($hooks, fn($h) => $h($state)), $label);

    // Use $runHooks multiple times without creating separate method
    if (!$runHooks($suite['before'], '[before all]')) return 1;
}
```

## Project Architecture

### Core vs Facades

The project uses a **Core + Facade** pattern:

- **`src/Core/`** - Concrete implementations, instances without shared state
- **`src/`** - Static facades that delegate to Core instances

```php
// Core implementation
namespace Ajo\Core;

class Console {
    public function log(string $message) { /* ... */ }
}

// Facade
namespace Ajo;

use Ajo\Core\Console as Root;

class Console {

    use Facade;

    protected static function root(): string
    {
        return Root::class;
    }
}

// Usage - Static (uses singleton automatically)
Console::log('hello'); // Delegates to Core\Console instance
Console::command('greet', fn() => Console::success('Hi!'));
Console::dispatch();
```

#### Creating and Swapping Instances

Facades use singleton instances by default, created automatically on first use. To configure custom behavior:

```php
// create() returns a new instance
$cli = Console::create(
    notFoundHandler: fn() => 1,
    exceptionHandler: fn($e) => 1
);

// To use this instance with the facade, swap it
Console::swap($cli);

// Now static calls use the custom instance
Console::command('test', fn() => 0);
```

**Testing pattern**: Use explicit instances to avoid singleton interference:

```php
Test::it('should...', function () {
    $cli = Console::create(); // Local instance
    $cli->command('demo', fn() => 0);
    // Test in isolation
});
```

**Principle**: Core classes should work as independent instances without shared state between them.

## Development Environment

The project uses Docker/Podman for development with PHP-FPM, Nginx, and MySQL.

### Docker Compose Setup

The stack includes:
- **app** (PHP 8.2-FPM with extensions: PDO, MySQL, PCOV, Xdebug, PCNTL, Sysvsem, Sysvshm)
- **nginx** (Web server on port 8080)
- **db** (MySQL 8.0)

### Starting the Environment

With Docker:
```bash
docker compose up -d
```

With Podman:
```bash
podman compose up -d
```

### Running Commands

All PHP commands should be run inside the `app` container:

**With Docker:**
```bash
docker compose exec app php console <command>
```

**With Podman:**
```bash
podman compose exec app php console <command>
```

### Common Commands

#### Running Tests

Run all tests:
```bash
podman compose exec app php console test
```

Run specific test suite:
```bash
podman compose exec app php console test --filter=Console
podman compose exec app php console test --filter=Job
podman compose exec app php console test --filter=Migrations
```

Run tests in parallel:
```bash
podman compose exec app php console test --parallel
```

Generate code coverage:
```bash
podman compose exec app php console coverage
```

#### Running Migrations

Install migration tracking table:
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

#### Managing Jobs

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

#### Other Useful Commands

List all available commands:
```bash
podman compose exec app php console help
```

Access PHP CLI directly:
```bash
podman compose exec app php -a
```

Access container shell:
```bash
podman compose exec app sh
```

Check logs:
```bash
podman compose logs app
podman compose logs nginx
podman compose logs db
```

### File Permissions

The container uses an entrypoint that aligns the container user with the host user to avoid permission issues. Files created inside the container will have the same ownership as your host user.

### Xdebug

Xdebug is available in the development environment. Configure your IDE to connect to:
- Host: `localhost`
- Port: `9003`

### Database Connection

MySQL is accessible from the host at:
- Host: `localhost`
- Port: `3306`
- Database: `appdb`
- User: `appuser`
- Password: `apppass`

Or from within containers using the service name `db:3306`.

## Naming Conventions

- **Variables**: `$camelCase`
- **Methods/Functions**: `camelCase()`
- **Classes**: `PascalCase`
- **Constants**: `UPPER_SNAKE_CASE`

Descriptive but concise names. Prefer clarity over extreme brevity.

## Performance

When optimization is necessary:

- **Use native PHP extensions** when available (pcntl, sysvsem, sysvshm, etc.)
- **Shared memory** over files for inter-process communication
- **Atomic operations** with semaphores instead of manual locks
- **Avoid unnecessary I/O** - cache, batch operations
- **Profile before optimizing** - don't guess where the bottleneck is

**Principle**: Speed without complexity. If optimization makes code significantly more complex, reconsider.

## Refactoring Priorities

When refactoring, follow this order:

1. **Eliminate duplication** - DRY is priority #1
2. **Simplify expressions** - Use language features intelligently
3. **Reduce lines** - Compact but readable code
4. **Improve readability** - Clear names, obvious structure
5. **Reduce complexity** - Less indentation, fewer branches

## Preferred Tools

- **Closures** over private helper methods
- **Match expressions** over switch
- **Array functions** over loops
- **Null coalescing** (`??`) and elvis (`?:`) appropriately
- **Named arguments** when they improve clarity
- **Arrow functions** for simple transformations

## Anti-Patterns to Avoid

❌ Creating private methods for single use
❌ Over-engineering with unnecessary classes/interfaces
❌ Premature abstractions
❌ Obvious documentation (code should be self-documenting)
❌ Over-specific tests that break with implementation changes
❌ Excessive configuration

## Simplification Iterations

After implementing features, **always** do a simplification iteration:

1. Look for duplication
2. Identify opportunities to use closures
3. Compact verbose expressions
4. Reduce nesting with guard clauses
5. Unify similar logic

**Goal**: Each iteration should reduce lines of code while maintaining or improving clarity.

## Code Examples

### Excellent - Simple and Direct

```php
private function summary(float $startedAt)
{
    $total = $this->summary['passed'] + $this->summary['failed'] + $this->summary['skipped'];
    Console::blank();
    Console::bold()->log('Summary:');
    Console::log('  Total:   ' . ($this->summary['total'] ?: $total));
    Console::log('  Passed:  ' . $this->summary['passed']);
    Console::log('  Skipped: ' . $this->summary['skipped']);
    Console::log('  Failed:  ' . $this->summary['failed']);
    Console::log('  Time:    ' . $this->duration(microtime(true) - $startedAt));
}
```

**Why it's excellent**:
- Straight to the point, no unnecessary abstractions
- Inline calculation when it makes sense
- Self-documented with clear names

### Excellent - Local Closure

```php
private function run()
{
    $record = fn($type, $name, $seconds = null) => $isParallel
        ? $this->incrementShared($shm, compact('type', 'name', 'seconds'))
        : $this->render($type, $name, $seconds);

    // Use $record multiple times
    $record('pass', $test['name'], $elapsed);
}
```

**Why it's excellent**:
- Avoids duplication without creating private method
- Clear local context
- Simple and readable ternary

### Avoid - Over-Engineering

```php
// ❌ Too much abstraction
interface ResultRenderer {
    public function render(TestResult $result): void;
}

class ParallelResultRenderer implements ResultRenderer {
    public function render(TestResult $result): void {
        // ...
    }
}

// ✅ Better - direct
$record = fn($type, $name) => $isParallel
    ? $this->incrementShared($shm, ...)
    : $this->render(...);
```

---

**Remember**: If code can be simpler, it should be. Simplicity is sophistication.
