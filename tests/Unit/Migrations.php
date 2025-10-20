<?php

declare(strict_types=1);

namespace Ajo\Tests\Unit;

use Ajo\Console;
use Ajo\Container;
use Ajo\Migrations;
use Ajo\Test;
use FilesystemIterator;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use function Ajo\Tests\Support\Console\dispatch;

Test::suite('Migrations', function () {

    Test::beforeEach(function ($state) {
        Container::clear();
        $state['paths'] = [];
        $state['sequence'] = 0;
    });

    Test::afterEach(function ($state) {

        Container::clear();

        foreach ($state['paths'] as $path) {
            removeDirectory($path);
        }

        $state['paths'] = [];
    });

    Test::it('should add migration commands on register', function ($state) {

        $path = newMigrationsPath($state);
        $console = Console::create();

        $migrations = Migrations::register($console, $path);
        Test::assertInstanceOf(Migrations::class, $migrations);

        $commands = $console->commands();
        $expected = [
            'migrate',
            'migrate:status',
            'migrate:rollback',
            'migrate:reset',
            'migrate:fresh',
            'migrate:refresh',
            'migrate:install',
            'migrate:make',
        ];

        foreach ($expected as $command) {
            Test::assertArrayHasKey($command, $commands, sprintf('Command %s was not registered.', $command));
        }
    });

    Test::it('should execute pending migrations on migrate', function ($state) {

        [$path, $console, $pdo] = bootstrap($state);

        $sample = createMigration(
            $state,
            $path,
            'create_sample',
            "\$pdo->exec('CREATE TABLE sample (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');",
            "\$pdo->exec('DROP TABLE sample');",
        );

        [$stdout, $stderr] = runOk($console, 'migrate');
        Test::assertStringContainsString('Migrations applied', $stdout);
        Test::assertStringContainsString($sample, $stdout);

        assertTableExists($pdo, 'sample', true);
        Test::assertContains($sample, applied($pdo));
    });

    Test::it('should stop on exception and roll back on migrate', function ($state) {

        [$path, $console, $pdo] = bootstrap($state);

        $failing = createMigration(
            $state,
            $path,
            'fail_migration',
            "throw new \\RuntimeException('Intentional failure');",
            '// noop',
        );

        [$stdout, $stderr] = runFail($console, 'migrate');
        Test::assertStringContainsString('Intentional failure', $stderr);
        Test::assertStringContainsString('Failed on: ' . $failing, $stdout);

        $count = $pdo->query('SELECT COUNT(*) FROM migrations WHERE migration = ' . $pdo->quote($failing));
        Test::assertNotFalse($count);
        Test::assertSame(0, (int)($count->fetchColumn() ?: 0));
        $count->closeCursor();
    });

    Test::it('should revert latest batch on rollback', function ($state) {

        [$path, $console, $pdo] = bootstrap($state);

        $itemsMigration = createMigration(
            $state,
            $path,
            'create_items',
            "\$pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, title TEXT NOT NULL)');",
            "\$pdo->exec('DROP TABLE items');",
        );

        runOk($console, 'migrate');

        assertTableExists($pdo, 'items', true);

        [$stdout, $stderr] = runOk($console, 'migrate:rollback');
        Test::assertStringContainsString('Migrations reverted', $stdout);
        Test::assertStringContainsString($itemsMigration, $stdout);

        $remaining = $pdo->query('SELECT COUNT(*) FROM migrations WHERE migration = ' . $pdo->quote($itemsMigration));
        Test::assertNotFalse($remaining);
        Test::assertSame(0, (int)$remaining->fetchColumn());
        $remaining->closeCursor();

        assertTableExists($pdo, 'items', false);
    });

    Test::it('should report when no migrations defined in status', function ($state) {

        [, $console] = bootstrap($state);

        [$stdout] = runOk($console, 'migrate:status');
        Test::assertStringContainsString('Total: 0 | Applied: 0 | Pending: 0', $stdout);
        Test::assertStringContainsString('No defined migrations.', $stdout);
    });

    Test::it('should show applied and pending migrations', function ($state) {

        [$path, $console] = bootstrap($state);

        $alpha = createMigration(
            $state,
            $path,
            'create_alphas',
            "\$pdo->exec('CREATE TABLE alphas (id INTEGER PRIMARY KEY)');",
            "\$pdo->exec('DROP TABLE alphas');",
        );

        runOk($console, 'migrate');

        $beta = createMigration(
            $state,
            $path,
            'create_betas',
            "\$pdo->exec('CREATE TABLE betas (id INTEGER PRIMARY KEY)');",
            "\$pdo->exec('DROP TABLE betas');",
        );

        [$stdout, $stderr] = runOk($console, 'migrate:status');

        Test::assertStringContainsString('Total: 2 | Applied: 1 | Pending: 1', $stdout);
        Test::assertStringContainsString($alpha, $stdout);
        Test::assertStringContainsString($beta, $stdout);
        Test::assertStringContainsString('yes', $stdout);
        Test::assertStringContainsString('no', $stdout);
    });

    Test::it('should create bootstrap record on install', function ($state) {

        [, $console, $pdo] = bootstrap($state);

        [$stdout] = runOk($console, 'migrate:install');
        Test::assertStringContainsString('Migration registry initialized.', $stdout);

        $count = $pdo->query("SELECT COUNT(*) FROM migrations WHERE migration = 'bootstrap'");
        Test::assertNotFalse($count);
        Test::assertSame(1, (int)$count->fetchColumn());
        $count->closeCursor();
    });

    Test::it('should not duplicate bootstrap', function ($state) {

        [, $console, $pdo] = bootstrap($state);

        runOk($console, 'migrate:install');
        [$stdout] = runOk($console, 'migrate:install');
        Test::assertStringContainsString('Migration table is already initialized.', $stdout);

        $count = $pdo->query("SELECT COUNT(*) FROM migrations WHERE migration = 'bootstrap'");
        Test::assertNotFalse($count);
        Test::assertSame(1, (int)$count->fetchColumn());
        $count->closeCursor();
    });

    Test::it('should revert all migrations on reset', function ($state) {

        [$path, $console, $pdo] = bootstrap($state);

        createMigrations($state, $path, [
            'create_alpha' => [
                'up' => "\$pdo->exec('CREATE TABLE alpha (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');",
                'down' => "\$pdo->exec('DROP TABLE alpha');",
            ],
            'create_beta' => [
                'up' => "\$pdo->exec('CREATE TABLE beta (id INTEGER PRIMARY KEY, label TEXT NOT NULL)');",
                'down' => "\$pdo->exec('DROP TABLE beta');",
            ],
        ]);

        runOk($console, 'migrate');

        [$stdout] = runOk($console, 'migrate:reset');
        Test::assertStringContainsString('Migrations reset', $stdout);

        assertTableExists($pdo, 'alpha', false);
        assertTableExists($pdo, 'beta', false);

        $remaining = array_diff(applied($pdo), ['bootstrap']);
        Test::assertSame([], $remaining);
    });

    Test::it('should reset and migrate again on fresh', function ($state) {

        [$path, $console, $pdo] = bootstrap($state);

        $itemsMigration = createMigration(
            $state,
            $path,
            'create_items',
            "\$pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, title TEXT NOT NULL)');",
            "\$pdo->exec('DROP TABLE items');",
        );

        runOk($console, 'migrate');

        $pdo->exec("INSERT INTO items (title) VALUES ('uno')");

        [$stdout] = runOk($console, 'migrate:fresh');
        Test::assertStringContainsString('Migrations reset', $stdout);
        Test::assertStringContainsString('Migrations applied', $stdout);

        $count = $pdo->query('SELECT COUNT(*) FROM items');
        Test::assertNotFalse($count);
        Test::assertSame(0, (int)$count->fetchColumn());
        $count->closeCursor();

        Test::assertContains($itemsMigration, applied($pdo));
    });

    Test::it('should roll back last batch and reapply it on refresh', function ($state) {

        [$path, $console, $pdo] = bootstrap($state);

        $alphaName = createMigration(
            $state,
            $path,
            'create_alphas',
            "\$pdo->exec('CREATE TABLE alphas (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');",
            "\$pdo->exec('DROP TABLE alphas');",
        );

        runOk($console, 'migrate'); // batch 1: alphas
        $pdo->exec("INSERT INTO alphas (name) VALUES ('persistente')");

        $betaName = createMigration(
            $state,
            $path,
            'create_betas',
            "\$pdo->exec('CREATE TABLE betas (id INTEGER PRIMARY KEY, code TEXT NOT NULL)');",
            "\$pdo->exec('DROP TABLE betas');",
        );

        runOk($console, 'migrate'); // batch 2: betas
        $pdo->exec("INSERT INTO betas (code) VALUES ('temporal')");

        [$stdout] = runOk($console, 'migrate:refresh');
        Test::assertStringContainsString('Migrations reverted', $stdout);
        Test::assertStringContainsString('Migrations applied', $stdout);

        $alphas = $pdo->query('SELECT name FROM alphas');
        Test::assertNotFalse($alphas);
        Test::assertSame(['persistente'], $alphas->fetchAll(PDO::FETCH_COLUMN));
        $alphas->closeCursor();

        $betas = $pdo->query('SELECT code FROM betas');
        Test::assertNotFalse($betas);
        Test::assertSame([], $betas->fetchAll(PDO::FETCH_COLUMN));
        $betas->closeCursor();

        Test::assertContains($alphaName, applied($pdo));
        Test::assertContains($betaName, applied($pdo));
    });

    Test::it('should create directory when missing', function ($state) {

        $path = sys_get_temp_dir() . '/migrations_new_' . uniqid('', true);
        $state['paths'][] = $path;

        Test::assertFalse(is_dir($path));

        $console = Console::create();
        Migrations::register($console, $path);

        Test::assertTrue(is_dir($path));
    });

    Test::it('should report when no pending migrations on migrate', function ($state) {
        [, $console] = bootstrap($state);
        [$stdout] = runOk($console, 'migrate');
        Test::assertStringContainsString('No pending migrations.', $stdout);
    });

    Test::it('should report when nothing to revert on rollback', function ($state) {
        [, $console] = bootstrap($state);
        [$stdout] = runOk($console, 'migrate:rollback');
        Test::assertStringContainsString('No migrations to revert.', $stdout);
    });

    Test::it('should fail when migration file is missing', function ($state) {

        [$path, $console] = bootstrap($state);

        $name = createMigration(
            $state,
            $path,
            'create_missing',
            "\$pdo->exec('CREATE TABLE missing (id INTEGER PRIMARY KEY)');",
            "\$pdo->exec('DROP TABLE missing');",
        );

        runOk($console, 'migrate');
        unlink($path . '/' . $name . '.php');

        [$stdout, $stderr] = runFail($console, 'migrate:rollback');
        Test::assertStringContainsString('Could not revert migrations.', $stderr);
        Test::assertStringContainsString('Failed on: ' . $name, $stdout);
    });

    Test::it('should fall back to default error message on migrate', function ($state) {

        [$path, $console] = bootstrap($state);

        $broken = createMigration(
            $state,
            $path,
            'create_broken',
            "throw new \\RuntimeException('');",
            '// noop',
        );

        [$stdout, $stderr] = runFail($console, 'migrate');

        Test::assertStringContainsString('Could not execute migrations.', $stderr);
        Test::assertStringContainsString('Failed on: ' . $broken, $stdout);
    });

    Test::it('should fall back to default error message on rollback', function ($state) {

        [$path, $console] = bootstrap($state);

        $brokenDown = createMigration(
            $state,
            $path,
            'create_broken_down',
            '// noop',
            "throw new \\RuntimeException('');",
        );

        runOk($console, 'migrate');

        [$stdout, $stderr] = runFail($console, 'migrate:rollback');
        Test::assertStringContainsString('Could not revert migrations.', $stderr);
        Test::assertStringContainsString('Failed on: ' . $brokenDown, $stdout);
    });

    Test::it('should fail when database is missing', function ($state) {

        Container::clear();
        $path = newMigrationsPath($state);
        $console = Console::create();
        Migrations::register($console, $path);

        [, $stderr] = runFail($console, 'migrate');
        Test::assertStringContainsString('No database connection available.', $stderr);
    });
});

// Helper functions

function newMigrationsPath($state): string
{
    $path = sys_get_temp_dir() . '/migrations_' . uniqid('', true);

    if (!mkdir($path, 0o755) && !is_dir($path)) {
        throw new RuntimeException('Could not create temporary directory for migrations.');
    }

    $state['paths'][] = $path;

    return $path;
}

function removeDirectory(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }

    rmdir($path);
}

function createMigration(
    $state,
    string $directory,
    string $suffix,
    string $upBody,
    string $downBody = '// noop'
): string {
    $name = sprintf('%d_%s_%d', (int)(microtime(true) * 1000), $suffix, ++$state['sequence']);
    $file = $directory . '/' . $name . '.php';

    $contents = <<<PHP
<?php

return [
    'up' => function (PDO \$pdo) {
        $upBody
    },
    'down' => function (PDO \$pdo) {
        $downBody
    },
];
PHP;

    if (file_put_contents($file, $contents) === false) {
        Test::fail('Could not create temporary migration.');
    }

    return $name;
}

/**
 * @param array<string, array{up:string,down?:string}> $definitions
 * @return array<string, string>
 */
function createMigrations($state, string $directory, array $definitions): array
{
    $names = [];

    foreach ($definitions as $suffix => $body) {
        $names[$suffix] = createMigration(
            $state,
            $directory,
            $suffix,
            $body['up'],
            $body['down'] ?? '// noop',
        );
    }

    return $names;
}

function assertTableExists(PDO $pdo, string $table, bool $expected): void
{
    $statement = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($table));
    Test::assertNotFalse($statement);
    $value = $statement->fetchColumn();
    $statement->closeCursor();

    Test::assertSame(
        $expected,
        $value !== false && $value !== null,
        sprintf('Table %s %s exist.', $table, $expected ? 'should' : 'should not'),
    );
}

/**
 * @return list<string>
 */
function applied(PDO $pdo): array
{
    $statement = $pdo->query('SELECT migration FROM migrations');

    if ($statement === false) {
        return [];
    }

    $rows = $statement->fetchAll(PDO::FETCH_COLUMN);
    $statement->closeCursor();

    return $rows ?: [];
}

/**
 * @param array<int, string> $arguments
 * @return array{0:string,1:string}
 */
function runOk($cli, string $command, array $arguments = []): array
{
    [$exitCode, $stdout, $stderr] = dispatch($cli, $command, $arguments);

    $message = "stdout:\n$stdout\nstderr:\n$stderr";

    Test::assertSame(0, $exitCode, $message);
    Test::assertSame('', $stderr, $message);

    return [$stdout, $stderr];
}

/**
 * @param array<int, string> $arguments
 * @return array{0:string,1:string}
 */
function runFail($cli, string $command, array $arguments = []): array
{
    [$exitCode, $stdout, $stderr] = dispatch($cli, $command, $arguments);

    $message = "stdout:\n$stdout\nstderr:\n$stderr";

    Test::assertSame(1, $exitCode, $message);

    return [$stdout, $stderr];
}

/**
 * @return array{0:string,1:\Ajo\Core\Console,2:PDO}
 */
function bootstrap($state): array
{
    $path = newMigrationsPath($state);
    $console = Console::create();

    Migrations::register($console, $path);

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    Container::set('db', $pdo);

    return [$path, $console, $pdo];
}
