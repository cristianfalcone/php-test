<?php

declare(strict_types=1);

namespace Ajo\Tests\Unit;

use FilesystemIterator;
use Ajo\Console;
use Ajo\Context;
use Ajo\Migrations;
use PDO;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class MigrationsTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];
    private int $sequence = 0;

    protected function tearDown(): void
    {
        Context::clear();

        foreach ($this->paths as $path) {
            $this->removeDirectory($path);
        }

        $this->paths = [];
    }

    public function testRegisterAddsMigrationCommands(): void
    {
        $path = $this->newMigrationsPath();
        $console = Console::create();

        $migrations = Migrations::register($console, $path);
        $this->assertInstanceOf(Migrations::class, $migrations);

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
            $this->assertArrayHasKey($command, $commands, sprintf('Command %s was not registered.', $command));
        }
    }

    public function testMigrateExecutesPendingMigrations(): void
    {
        [$path, $console, $pdo] = $this->bootstrap();

        $sample = $this->createMigration(
            $path,
            'create_sample',
            "\$pdo->exec('CREATE TABLE sample (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');",
            "\$pdo->exec('DROP TABLE sample');",
        );

        [$stdout, $stderr] = $this->runOk($console, 'migrate');
        $this->assertStringContainsString('Migraciones aplicadas', $stdout);
        $this->assertStringContainsString($sample, $stdout);

        $this->assertTableExists($pdo, 'sample', true);
        $this->assertContains($sample, $this->applied($pdo));
    }

    public function testMigrateStopsOnExceptionAndRollsBack(): void
    {
        [$path, $console, $pdo] = $this->bootstrap();

        $failing = $this->createMigration(
            $path,
            'fail_migration',
            "throw new \\RuntimeException('Fallo intencional');",
            '// noop',
        );

        [$stdout, $stderr] = $this->runFail($console, 'migrate');
        $this->assertStringContainsString('Fallo intencional', $stderr);
        $this->assertStringContainsString('Fallo en: ' . $failing, $stdout);

        $count = $pdo->query('SELECT COUNT(*) FROM migrations WHERE migration = ' . $pdo->quote($failing));
        $this->assertNotFalse($count);
        $this->assertSame(0, (int)($count->fetchColumn() ?: 0));
        $count->closeCursor();
    }

    public function testRollbackRevertsLatestBatch(): void
    {
        [$path, $console, $pdo] = $this->bootstrap();

        $itemsMigration = $this->createMigration(
            $path,
            'create_items',
            "\$pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, title TEXT NOT NULL)');",
            "\$pdo->exec('DROP TABLE items');",
        );

        $this->runOk($console, 'migrate');

        $this->assertTableExists($pdo, 'items', true);

        [$stdout, $stderr] = $this->runOk($console, 'migrate:rollback');
        $this->assertStringContainsString('Migraciones revertidas', $stdout);
        $this->assertStringContainsString($itemsMigration, $stdout);

        $remaining = $pdo->query('SELECT COUNT(*) FROM migrations WHERE migration = ' . $pdo->quote($itemsMigration));
        $this->assertNotFalse($remaining);
        $this->assertSame(0, (int)$remaining->fetchColumn());
        $remaining->closeCursor();

        $this->assertTableExists($pdo, 'items', false);
    }

    public function testStatusReportsWhenNoMigrationsDefined(): void
    {
        [, $console] = $this->bootstrap();

        [$stdout] = $this->runOk($console, 'migrate:status');
        $this->assertStringContainsString('Total: 0 | Aplicadas: 0 | Pendientes: 0', $stdout);
        $this->assertStringContainsString('No hay migraciones definidas.', $stdout);
    }

    public function testStatusShowsAppliedAndPendingMigrations(): void
    {
        [$path, $console] = $this->bootstrap();

        $alpha = $this->createMigration(
            $path,
            'create_alphas',
            "\$pdo->exec('CREATE TABLE alphas (id INTEGER PRIMARY KEY)');",
            "\$pdo->exec('DROP TABLE alphas');",
        );

        $this->runOk($console, 'migrate');

        $beta = $this->createMigration(
            $path,
            'create_betas',
            "\$pdo->exec('CREATE TABLE betas (id INTEGER PRIMARY KEY)');",
            "\$pdo->exec('DROP TABLE betas');",
        );

        [$stdout, $stderr] = $this->runOk($console, 'migrate:status');
        $this->assertStringContainsString('Total: 2 | Aplicadas: 1 | Pendientes: 1', $stdout);
        $this->assertStringContainsString($alpha, $stdout);
        $this->assertStringContainsString($beta, $stdout);
        $this->assertStringContainsString('si', $stdout);
        $this->assertStringContainsString('no', $stdout);
    }

    public function testInstallCreatesBootstrapRecord(): void
    {
        [, $console, $pdo] = $this->bootstrap();

        [$stdout] = $this->runOk($console, 'migrate:install');
        $this->assertStringContainsString('Registro de migraciones inicializado.', $stdout);

        $count = $pdo->query("SELECT COUNT(*) FROM migrations WHERE migration = 'bootstrap'");
        $this->assertNotFalse($count);
        $this->assertSame(1, (int)$count->fetchColumn());
        $count->closeCursor();
    }

    public function testInstallDoesNotDuplicateBootstrap(): void
    {
        [, $console, $pdo] = $this->bootstrap();

        $this->runOk($console, 'migrate:install');
        [$stdout] = $this->runOk($console, 'migrate:install');
        $this->assertStringContainsString('La tabla de migraciones ya está inicializada.', $stdout);

        $count = $pdo->query("SELECT COUNT(*) FROM migrations WHERE migration = 'bootstrap'");
        $this->assertNotFalse($count);
        $this->assertSame(1, (int)$count->fetchColumn());
        $count->closeCursor();
    }

    public function testResetRevertsAllMigrations(): void
    {
        [$path, $console, $pdo] = $this->bootstrap();

        $this->createMigrations($path, [
            'create_alpha' => [
                'up' => "\$pdo->exec('CREATE TABLE alpha (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');",
                'down' => "\$pdo->exec('DROP TABLE alpha');",
            ],
            'create_beta' => [
                'up' => "\$pdo->exec('CREATE TABLE beta (id INTEGER PRIMARY KEY, label TEXT NOT NULL)');",
                'down' => "\$pdo->exec('DROP TABLE beta');",
            ],
        ]);

        $this->runOk($console, 'migrate');

        [$stdout] = $this->runOk($console, 'migrate:reset');
        $this->assertStringContainsString('Migraciones reseteadas', $stdout);

        $this->assertTableExists($pdo, 'alpha', false);
        $this->assertTableExists($pdo, 'beta', false);

        $remaining = array_diff($this->applied($pdo), ['bootstrap']);
        $this->assertSame([], $remaining);
    }

    public function testFreshResetsAndMigratesAgain(): void
    {
        [$path, $console, $pdo] = $this->bootstrap();

        $itemsMigration = $this->createMigration(
            $path,
            'create_items',
            "\$pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, title TEXT NOT NULL)');",
            "\$pdo->exec('DROP TABLE items');",
        );

        $this->runOk($console, 'migrate');

        $pdo->exec("INSERT INTO items (title) VALUES ('uno')");

        [$stdout] = $this->runOk($console, 'migrate:fresh');
        $this->assertStringContainsString('Migraciones reseteadas', $stdout);
        $this->assertStringContainsString('Migraciones aplicadas', $stdout);

        $count = $pdo->query('SELECT COUNT(*) FROM items');
        $this->assertNotFalse($count);
        $this->assertSame(0, (int)$count->fetchColumn());
        $count->closeCursor();

        $this->assertContains($itemsMigration, $this->applied($pdo));
    }

    public function testRefreshRollsBackLastBatchAndReappliesIt(): void
    {
        [$path, $console, $pdo] = $this->bootstrap();

        $alphaName = $this->createMigration(
            $path,
            'create_alphas',
            "\$pdo->exec('CREATE TABLE alphas (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');",
            "\$pdo->exec('DROP TABLE alphas');",
        );

        $this->runOk($console, 'migrate'); // batch 1: alphas
        $pdo->exec("INSERT INTO alphas (name) VALUES ('persistente')");

        $betaName = $this->createMigration(
            $path,
            'create_betas',
            "\$pdo->exec('CREATE TABLE betas (id INTEGER PRIMARY KEY, code TEXT NOT NULL)');",
            "\$pdo->exec('DROP TABLE betas');",
        );

        $this->runOk($console, 'migrate'); // batch 2: betas
        $pdo->exec("INSERT INTO betas (code) VALUES ('temporal')");

        [$stdout] = $this->runOk($console, 'migrate:refresh');
        $this->assertStringContainsString('Migraciones revertidas', $stdout);
        $this->assertStringContainsString('Migraciones aplicadas', $stdout);

        $alphas = $pdo->query('SELECT name FROM alphas');
        $this->assertNotFalse($alphas);
        $this->assertSame(['persistente'], $alphas->fetchAll(PDO::FETCH_COLUMN));
        $alphas->closeCursor();

        $betas = $pdo->query('SELECT code FROM betas');
        $this->assertNotFalse($betas);
        $this->assertSame([], $betas->fetchAll(PDO::FETCH_COLUMN));
        $betas->closeCursor();

        $this->assertContains($alphaName, $this->applied($pdo));
        $this->assertContains($betaName, $this->applied($pdo));
    }

    public function testRegisterCreatesDirectoryWhenMissing(): void
    {
        $path = sys_get_temp_dir() . '/migrations_new_' . uniqid('', true);
        $this->paths[] = $path;

        $this->assertFalse(is_dir($path));

        $console = Console::create();
        Migrations::register($console, $path);

        $this->assertTrue(is_dir($path));
    }

    public function testMigrateReportsWhenNoPendingMigrations(): void
    {
        [, $console] = $this->bootstrap();

        [$stdout] = $this->runOk($console, 'migrate');
        $this->assertStringContainsString('No hay migraciones pendientes.', $stdout);
    }

    public function testRollbackReportsWhenNothingToRevert(): void
    {
        [, $console] = $this->bootstrap();

        [$stdout] = $this->runOk($console, 'migrate:rollback');
        $this->assertStringContainsString('No hay migraciones para revertir.', $stdout);
    }

    public function testRollbackFailsWhenMigrationFileIsMissing(): void
    {
        [$path, $console] = $this->bootstrap();

        $name = $this->createMigration(
            $path,
            'create_missing',
            "\$pdo->exec('CREATE TABLE missing (id INTEGER PRIMARY KEY)');",
            "\$pdo->exec('DROP TABLE missing');",
        );

        $this->runOk($console, 'migrate');
        unlink($path . '/' . $name . '.php');

        [$stdout, $stderr] = $this->runFail($console, 'migrate:rollback');
        $this->assertStringContainsString('No se pudieron revertir las migraciones.', $stderr);
        $this->assertStringContainsString('Fallo en: ' . $name, $stdout);
    }

    public function testMigrateFallsBackToDefaultErrorMessage(): void
    {
        [$path, $console] = $this->bootstrap();

        $broken = $this->createMigration(
            $path,
            'create_broken',
            "throw new \\RuntimeException('');",
            '// noop',
        );

        [$stdout, $stderr] = $this->runFail($console, 'migrate');

        $this->assertStringContainsString('No se pudieron ejecutar las migraciones.', $stderr);
        $this->assertStringContainsString('Fallo en: ' . $broken, $stdout);
    }

    public function testRollbackFallsBackToDefaultErrorMessage(): void
    {
        [$path, $console] = $this->bootstrap();

        $brokenDown = $this->createMigration(
            $path,
            'create_broken_down',
            '// noop',
            "throw new \\RuntimeException('');",
        );

        $this->runOk($console, 'migrate');

        [$stdout, $stderr] = $this->runFail($console, 'migrate:rollback');
        $this->assertStringContainsString('No se pudieron revertir las migraciones.', $stderr);
        $this->assertStringContainsString('Fallo en: ' . $brokenDown, $stdout);
    }

    public function testConnectionFailsWhenDatabaseIsMissing(): void
    {
        Context::clear();
        $path = $this->newMigrationsPath();
        $console = Console::create();
        Migrations::register($console, $path);

        [, $stderr] = $this->runFail($console, 'migrate');
        $this->assertStringContainsString('No hay conexión a la base de datos.', $stderr);
    }

    /**
     * @param array<int, string> $arguments
     * @return array{0:int,1:string,2:string}
     */
    private function dispatch(Console $cli, string $command, array $arguments = []): array
    {
        $stdout = fopen('php://temp', 'w+');
        $stderr = fopen('php://temp', 'w+');

        $hadArgv = array_key_exists('argv', $GLOBALS);
        $previousArgv = $hadArgv ? $GLOBALS['argv'] : null;
        $GLOBALS['argv'] = array_merge(['console', $command], $arguments);

        try {
            $exitCode = $cli->dispatch($command, $arguments, $stdout, $stderr);
        } finally {
            if ($hadArgv) {
                $GLOBALS['argv'] = $previousArgv;
            } else {
                unset($GLOBALS['argv']);
            }
        }

        foreach ([$stdout, $stderr] as $stream) {
            rewind($stream);
        }

        $out = stream_get_contents($stdout) ?: '';
        $err = stream_get_contents($stderr) ?: '';

        fclose($stdout);
        fclose($stderr);

        return [$exitCode, $out, $err];
    }

    private function newMigrationsPath(): string
    {
        $path = sys_get_temp_dir() . '/migrations_' . uniqid('', true);

        if (!mkdir($path, 0o755) && !is_dir($path)) {
            throw new RuntimeException('No se pudo crear el directorio temporal para migraciones.');
        }

        $this->paths[] = $path;

        return $path;
    }

    private function removeDirectory(string $path): void
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

    private function createMigration(
        string $directory,
        string $suffix,
        string $upBody,
        string $downBody = '// noop'
    ): string {
        $name = sprintf('%d_%s_%d', (int)(microtime(true) * 1000), $suffix, ++$this->sequence);
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
            self::fail('No se pudo crear la migración temporal.');
        }

        return $name;
    }

    /**
     * @param array<string, array{up:string,down?:string}> $definitions
     * @return array<string, string>
     */
    private function createMigrations(string $directory, array $definitions): array
    {
        $names = [];

        foreach ($definitions as $suffix => $body) {
            $names[$suffix] = $this->createMigration(
                $directory,
                $suffix,
                $body['up'],
                $body['down'] ?? '// noop',
            );
        }

        return $names;
    }

    private function assertTableExists(PDO $pdo, string $table, bool $expected): void
    {
        $statement = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($table));
        $this->assertNotFalse($statement);
        $value = $statement->fetchColumn();
        $statement->closeCursor();

        $this->assertSame(
            $expected,
            $value !== false && $value !== null,
            sprintf('La tabla %s %s existir.', $table, $expected ? 'debe' : 'no debe'),
        );
    }

    /**
     * @return list<string>
     */
    private function applied(PDO $pdo): array
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
    private function runOk(Console $cli, string $command, array $arguments = []): array
    {
        [$exitCode, $stdout, $stderr] = $this->dispatch($cli, $command, $arguments);

        $message = "stdout:\n$stdout\nstderr:\n$stderr";

        $this->assertSame(0, $exitCode, $message);
        $this->assertSame('', $stderr, $message);

        return [$stdout, $stderr];
    }

    /**
     * @param array<int, string> $arguments
     * @return array{0:string,1:string}
     */
    private function runFail(Console $cli, string $command, array $arguments = []): array
    {
        [$exitCode, $stdout, $stderr] = $this->dispatch($cli, $command, $arguments);

        $message = "stdout:\n$stdout\nstderr:\n$stderr";

        $this->assertSame(1, $exitCode, $message);

        return [$stdout, $stderr];
    }

    /**
     * @return array{0:string,1:Console,2:PDO}
     */
    private function bootstrap(): array
    {
        $path = $this->newMigrationsPath();
        $console = Console::create();

        Migrations::register($console, $path);

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        Context::set('db', $pdo);

        return [$path, $console, $pdo];
    }
}
