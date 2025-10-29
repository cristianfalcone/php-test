<?php

declare(strict_types=1);

namespace Ajo\Tests\Unit;

use Ajo\Console;
use Ajo\Container;
use Ajo\Database;
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
        // Use PID to ensure unique table prefix across parallel processes
        $state['pid'] = getmypid();
        $state['tables'] = [];
    });

    Test::afterEach(function ($state) {

        // Clean up test tables from MySQL BEFORE clearing container
        if (Container::has('db')) {
            try {
                $pdo = Container::get('db');
                $pid = $state['pid'];

                // Get all tables for this process
                $stmt = $pdo->query(
                    "SELECT TABLE_NAME FROM information_schema.TABLES " .
                    "WHERE TABLE_SCHEMA = DATABASE() " .
                    "AND TABLE_NAME LIKE '%_{$pid}_%'"
                );
                $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

                // Also include the migrations table for this process
                $migrationsTable = "migrations_{$pid}";
                $stmt = $pdo->query(
                    "SELECT TABLE_NAME FROM information_schema.TABLES " .
                    "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$migrationsTable}'"
                );
                if ($stmt->fetchColumn()) {
                    $tables[] = $migrationsTable;
                }

                // Drop all test tables for this process
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
                foreach ($tables as $table) {
                    $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
                }
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
            } catch (\Throwable) {
                // Ignore cleanup errors
            }
        }

        Container::clear();

        foreach ($state['paths'] as $path) {
            removeDirectory($path);
        }

        $state['paths'] = [];
    });

    Test::case('adds migration commands on register', function ($state) {

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

    Test::case('executes pending migrations on migrate', function ($state) {

        [$path, $console, $pdo] = bootstrap($state);

        $sample = createMigration(
            $state,
            $path,
            'create_sample',
            "\$pdo->exec(\"CREATE TABLE test_{\$pid}_sample (id BIGINT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL) ENGINE=InnoDB\");",
            "\$pdo->exec(\"DROP TABLE test_{\$pid}_sample\");",
        );

        [$stdout, $stderr] = runOk($console, 'migrate');
        Test::assertStringContainsString('Migrations applied', $stdout);
        Test::assertStringContainsString($sample, $stdout);

        assertTableExists($pdo, testTable($state, 'sample'), true);
        Test::assertContains($sample, applied($pdo, $state['pid']));
    });

    Test::case('stops on exception and rolls back on migrate', function ($state) {

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

        $table = 'migrations_' . $state['pid'];
        $count = $pdo->query("SELECT COUNT(*) FROM `{$table}` WHERE migration = " . $pdo->quote($failing));
        Test::assertNotFalse($count);
        Test::assertSame(0, (int)($count->fetchColumn() ?: 0));
        $count->closeCursor();
    });

    Test::case('reverts latest batch on rollback', function ($state) {

        [$path, $console, $pdo] = bootstrap($state);

        $itemsMigration = createMigration(
            $state,
            $path,
            'create_items',
            "\$pdo->exec(\"CREATE TABLE test_{\$pid}_items (id BIGINT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL) ENGINE=InnoDB\");",
            "\$pdo->exec(\"DROP TABLE test_{\$pid}_items\");",
        );

        runOk($console, 'migrate');

        assertTableExists($pdo, testTable($state, 'items'), true);

        [$stdout, $stderr] = runOk($console, 'migrate:rollback');
        Test::assertStringContainsString('Migrations reverted', $stdout);
        Test::assertStringContainsString($itemsMigration, $stdout);

        $table = 'migrations_' . $state['pid'];
        $remaining = $pdo->query("SELECT COUNT(*) FROM `{$table}` WHERE migration = " . $pdo->quote($itemsMigration));
        Test::assertNotFalse($remaining);
        Test::assertSame(0, (int)$remaining->fetchColumn());
        $remaining->closeCursor();

        assertTableExists($pdo, testTable($state, 'items'), false);
    });

    Test::case('reports when no migrations defined in status', function ($state) {

        [, $console] = bootstrap($state);

        [$stdout] = runOk($console, 'migrate:status');
        Test::assertStringContainsString('Total: 0 | Applied: 0 | Pending: 0', $stdout);
        Test::assertStringContainsString('No defined migrations.', $stdout);
    });

    Test::case('shows applied and pending migrations', function ($state) {

        [$path, $console] = bootstrap($state);

        $alpha = createMigration(
            $state,
            $path,
            'create_alphas',
            "\$pdo->exec(\"CREATE TABLE test_{\$pid}_alphas (id BIGINT AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB\");",
            "\$pdo->exec(\"DROP TABLE test_{\$pid}_alphas\");",
        );

        runOk($console, 'migrate');

        $beta = createMigration(
            $state,
            $path,
            'create_betas',
            "\$pdo->exec(\"CREATE TABLE test_{\$pid}_betas (id BIGINT AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB\");",
            "\$pdo->exec(\"DROP TABLE test_{\$pid}_betas\");",
        );

        [$stdout, $stderr] = runOk($console, 'migrate:status');

        Test::assertStringContainsString('Total: 2 | Applied: 1 | Pending: 1', $stdout);
        Test::assertStringContainsString($alpha, $stdout);
        Test::assertStringContainsString($beta, $stdout);
        Test::assertStringContainsString('yes', $stdout);
        Test::assertStringContainsString('no', $stdout);
    });

    Test::case('creates bootstrap record on install', function ($state) {

        [, $console, $pdo] = bootstrap($state);

        [$stdout] = runOk($console, 'migrate:install');
        Test::assertStringContainsString('Migration registry initialized.', $stdout);

        $table = 'migrations_' . $state['pid'];
        $count = $pdo->query("SELECT COUNT(*) FROM `{$table}` WHERE migration = 'bootstrap'");
        Test::assertNotFalse($count);
        Test::assertSame(1, (int)$count->fetchColumn());
        $count->closeCursor();
    });

    Test::case('does not duplicate bootstrap', function ($state) {

        [, $console, $pdo] = bootstrap($state);

        runOk($console, 'migrate:install');
        [$stdout] = runOk($console, 'migrate:install');
        Test::assertStringContainsString('Migration table is already initialized.', $stdout);

        $table = 'migrations_' . $state['pid'];
        $count = $pdo->query("SELECT COUNT(*) FROM `{$table}` WHERE migration = 'bootstrap'");
        Test::assertNotFalse($count);
        Test::assertSame(1, (int)$count->fetchColumn());
        $count->closeCursor();
    });

    Test::case('reverts all migrations on reset', function ($state) {

        [$path, $console, $pdo] = bootstrap($state);

        createMigrations($state, $path, [
            'create_alpha' => [
                'up' => "\$pdo->exec(\"CREATE TABLE test_{\$pid}_alpha (id BIGINT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL) ENGINE=InnoDB\");",
                'down' => "\$pdo->exec(\"DROP TABLE test_{\$pid}_alpha\");",
            ],
            'create_beta' => [
                'up' => "\$pdo->exec(\"CREATE TABLE test_{\$pid}_beta (id BIGINT AUTO_INCREMENT PRIMARY KEY, label VARCHAR(255) NOT NULL) ENGINE=InnoDB\");",
                'down' => "\$pdo->exec(\"DROP TABLE test_{\$pid}_beta\");",
            ],
        ]);

        runOk($console, 'migrate');

        [$stdout] = runOk($console, 'migrate:reset');
        Test::assertStringContainsString('Migrations reset', $stdout);

        assertTableExists($pdo, testTable($state, 'alpha'), false);
        assertTableExists($pdo, testTable($state, 'beta'), false);

        $remaining = array_diff(applied($pdo, $state['pid']), ['bootstrap']);
        Test::assertSame([], $remaining);
    });

    Test::case('resets and migrates again on fresh', function ($state) {

        [$path, $console, $pdo] = bootstrap($state);

        $itemsMigration = createMigration(
            $state,
            $path,
            'create_items',
            "\$pdo->exec(\"CREATE TABLE test_{\$pid}_items (id BIGINT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL) ENGINE=InnoDB\");",
            "\$pdo->exec(\"DROP TABLE test_{\$pid}_items\");",
        );

        runOk($console, 'migrate');

        $tableName = testTable($state, 'items');
        $pdo->exec("INSERT INTO `{$tableName}` (title) VALUES ('uno')");

        [$stdout] = runOk($console, 'migrate:fresh');
        Test::assertStringContainsString('Migrations reset', $stdout);
        Test::assertStringContainsString('Migrations applied', $stdout);

        $tableName = testTable($state, 'items');
        $count = $pdo->query("SELECT COUNT(*) FROM `{$tableName}`");
        Test::assertNotFalse($count);
        Test::assertSame(0, (int)$count->fetchColumn());
        $count->closeCursor();

        Test::assertContains($itemsMigration, applied($pdo, $state['pid']));
    });

    Test::case('rolls back last batch and reapplies it on refresh', function ($state) {

        [$path, $console, $pdo] = bootstrap($state);

        $alphaName = createMigration(
            $state,
            $path,
            'create_alphas',
            "\$pdo->exec(\"CREATE TABLE test_{\$pid}_alphas (id BIGINT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL) ENGINE=InnoDB\");",
            "\$pdo->exec(\"DROP TABLE test_{\$pid}_alphas\");",
        );

        runOk($console, 'migrate'); // batch 1: alphas
        $alphasTable = testTable($state, 'alphas');
        $pdo->exec("INSERT INTO `{$alphasTable}` (name) VALUES ('persistente')");

        $betaName = createMigration(
            $state,
            $path,
            'create_betas',
            "\$pdo->exec(\"CREATE TABLE test_{\$pid}_betas (id BIGINT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(255) NOT NULL) ENGINE=InnoDB\");",
            "\$pdo->exec(\"DROP TABLE test_{\$pid}_betas\");",
        );

        runOk($console, 'migrate'); // batch 2: betas
        $betasTable = testTable($state, 'betas');
        $pdo->exec("INSERT INTO `{$betasTable}` (code) VALUES ('temporal')");

        [$stdout] = runOk($console, 'migrate:refresh');
        Test::assertStringContainsString('Migrations reverted', $stdout);
        Test::assertStringContainsString('Migrations applied', $stdout);

        $alphasTable = testTable($state, 'alphas');
        $alphas = $pdo->query("SELECT name FROM `{$alphasTable}`");
        Test::assertNotFalse($alphas);
        Test::assertSame(['persistente'], $alphas->fetchAll(PDO::FETCH_COLUMN));
        $alphas->closeCursor();

        $betasTable = testTable($state, 'betas');
        $betas = $pdo->query("SELECT code FROM `{$betasTable}`");
        Test::assertNotFalse($betas);
        Test::assertSame([], $betas->fetchAll(PDO::FETCH_COLUMN));
        $betas->closeCursor();

        Test::assertContains($alphaName, applied($pdo, $state['pid']));
        Test::assertContains($betaName, applied($pdo, $state['pid']));
    });

    Test::case('creates directory when missing', function ($state) {

        $path = sys_get_temp_dir() . '/migrations_new_' . uniqid('', true);
        $state['paths'][] = $path;

        Test::assertFalse(is_dir($path));

        $console = Console::create();
        Migrations::register($console, $path);

        Test::assertTrue(is_dir($path));
    });

    Test::case('reports when no pending migrations on migrate', function ($state) {
        [, $console] = bootstrap($state);
        [$stdout] = runOk($console, 'migrate');
        Test::assertStringContainsString('No pending migrations.', $stdout);
    });

    Test::case('reports when nothing to revert on rollback', function ($state) {
        [, $console] = bootstrap($state);
        [$stdout] = runOk($console, 'migrate:rollback');
        Test::assertStringContainsString('No migrations to revert.', $stdout);
    });

    Test::case('fails when migration file is missing', function ($state) {

        [$path, $console] = bootstrap($state);

        $name = createMigration(
            $state,
            $path,
            'create_missing',
            "\$pdo->exec(\"CREATE TABLE test_{\$pid}_missing (id BIGINT AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB\");",
            "\$pdo->exec(\"DROP TABLE test_{\$pid}_missing\");",
        );

        runOk($console, 'migrate');
        unlink($path . '/' . $name . '.php');

        [$stdout, $stderr] = runFail($console, 'migrate:rollback');
        Test::assertStringContainsString('Could not revert migrations.', $stderr);
        Test::assertStringContainsString('Failed on: ' . $name, $stdout);
    });

    Test::case('falls back to default error message on migrate', function ($state) {

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

    Test::case('falls back to default error message on rollback', function ($state) {

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

    Test::case('fails when database is missing', function ($state) {

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

    // Inject PID into migration code to make table names unique per process
    $pid = $state['pid'];
    $contents = <<<PHP
<?php

return [
    'up' => function (PDO \$pdo) {
        \$pid = {$pid};
        $upBody
    },
    'down' => function (PDO \$pdo) {
        \$pid = {$pid};
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
    $statement = $pdo->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES " .
        "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($table)
    );
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
function applied(PDO $pdo, ?int $pid = null): array
{
    $table = $pid ? "migrations_{$pid}" : 'migrations';
    $statement = $pdo->query("SELECT migration FROM `{$table}`");

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

function testTable($state, string $name): string
{
    return "test_{$state['pid']}_{$name}";
}

/**
 * @return array{0:string,1:\Ajo\Core\Console,2:PDO}
 */
function bootstrap($state): array
{
    $path = newMigrationsPath($state);
    $console = Console::create();

    // Use PID-specific table name for migrations to isolate parallel tests
    $table = 'migrations_' . $state['pid'];
    Migrations::register($console, $path, $table);

    $pdo = Database::get();

    Container::set('db', $pdo);

    return [$path, $console, $pdo];
}
