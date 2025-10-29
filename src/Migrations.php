<?php

declare(strict_types=1);

namespace Ajo;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Database migration management.
 */
final class Migrations
{
    private string $path;
    private string $table;

    private function __construct(string $path, string $table = 'migrations')
    {
        $this->path = rtrim($path, '/');
        $this->table = $table;

        if (!is_dir($this->path)) {
            mkdir($this->path, 0o755, true);
        }
    }

    /**
     * Registers migration commands in the console.
     */
    public static function register(ConsoleCore $cli, string $path, string $table = 'migrations')
    {
        $self = new self($path, $table);

        $cli->command('migrate',          fn() => $self->migrate())->describe('Runs all pending migrations.');
        $cli->command('migrate:status',   fn() => $self->status())->describe('Shows the current status of migrations.');
        $cli->command('migrate:rollback', fn() => $self->rollback())->describe('Reverts the last applied batch of migrations.');
        $cli->command('migrate:reset',    fn() => $self->reset())->describe('Reverts all applied migrations.');
        $cli->command('migrate:fresh',    fn() => $self->fresh())->describe('Resets and re-runs all migrations.');
        $cli->command('migrate:refresh',  fn() => $self->refresh())->describe('Reverts the last batch and re-applies it.');
        $cli->command('migrate:install',  fn() => $self->install())->describe('Creates the migration tracking table.');
        $cli->command('migrate:make',     fn() => $self->make())->describe('Generates a migration file.');

        return $self;
    }

    private function migrate()
    {
        $this->ensure();

        $pending = $this->pending();

        if ($pending === []) {
            Console::line('No pending migrations.');
            return 0;
        }

        $batch = $this->next();
        $pdo = $this->pdo();
        $migrated = [];

        foreach ($pending as $name => $file) {

            $migration = (string)$name;

            try {
                $definition = $this->load($file);
                $pdo->beginTransaction();
                $definition['up']($pdo);

                $this->record($migration, $batch);

                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }

                $migrated[] = $migration;
            } catch (Throwable $exception) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $message = $exception->getMessage() !== ''
                    ? $exception->getMessage()
                    : 'Could not execute migrations.';

                Console::error($message);
                Console::line('Failed on: ' . $migration);

                return 1;
            }
        }

        Console::success(sprintf('Migrations applied. Batch %d, %d entries processed.', $batch, count($migrated)));

        foreach ($migrated as $migration) {
            Console::line('  - ' . $migration);
        }

        return 0;
    }

    private function status()
    {
        $this->ensure();

        $applied = $this->applied();
        $map = [];

        foreach ($applied as $row) {
            $map[(string)$row['migration']] = $row;
        }

        $rows = [];

        foreach ($this->available() as $name => $file) {
            $id = (string)$name;
            $rows[] = [
                'migration' => $id,
                'ran'       => isset($map[$id]),
                'batch'     => $map[$id]['batch'] ?? null,
                'ran_at'    => $map[$id]['ran_at'] ?? null,
            ];
        }

        Console::line(sprintf(
            'Total: %d | Applied: %d | Pending: %d',
            count($rows),
            count($applied),
            max(0, count($rows) - count($applied)),
        ));

        Console::blank();
        $this->render($rows);

        return 0;
    }

    private function rollback()
    {
        $this->ensure();

        $available = $this->available();
        $latest = $this->last();

        if ($latest === []) {
            Console::line('No migrations to revert.');
            return 0;
        }

        $rolled = [];

        foreach ($latest as $row) {

            $name = (string)$row['migration'];
            $file = $available[$name] ?? null;

            if (!$this->revert($name, $file, 'Could not revert migrations.')) {
                return 1;
            }

            $rolled[] = $name;
        }

        Console::success(sprintf('Migrations reverted. Processed: %d.', count($rolled)));

        foreach ($rolled as $name) {
            Console::line('  - ' . $name);
        }

        return 0;
    }

    private function reset()
    {
        $this->ensure();

        $applied = array_reverse($this->applied());
        $available = $this->available();

        $rolled = [];

        foreach ($applied as $row) {

            $name = (string)$row['migration'];

            if ($name === 'bootstrap') {
                continue;
            }

            $file = $available[$name] ?? null;

            if (!$this->revert($name, $file, 'Could not reset migrations.')) {
                return 1;
            }

            $rolled[] = $name;
        }

        if ($rolled === []) {
            Console::line('No migrations to reset.');
            return 0;
        }

        Console::success(sprintf('Migrations reset. Processed: %d.', count($rolled)));

        foreach ($rolled as $name) {
            Console::line('  - ' . $name);
        }

        return 0;
    }

    private function fresh()
    {
        $reset = $this->reset();
        return $reset === 0 ? $this->migrate() : $reset;
    }

    private function refresh()
    {
        $rollback = $this->rollback();
        return $rollback === 0 ? $this->migrate() : $rollback;
    }

    private function install()
    {
        $this->ensure();

        $table = $this->table;
        $sql = "SELECT COUNT(*) FROM `{$table}`";
        $statement = $this->pdo()->prepare($sql);

        if ($statement === false) {
            throw new RuntimeException('Could not prepare count query.');
        }

        $statement->execute();
        $count = (int)$statement->fetchColumn();

        if ($count > 0) {
            Console::line('Migration table is already initialized.');
            return 0;
        }

        $this->record('bootstrap', 0);
        Console::success('Migration registry initialized.');

        return 0;
    }

    private function make()
    {
        if (!is_writable($this->path)) {
            Console::error('Cannot write to migrations directory.');
            return 1;
        }

        $raw = Console::arguments()[0] ?? null;
        $suffix = '';

        if ($raw !== null && ($raw = trim($raw)) !== '') {
            // Add space between words
            $raw = preg_replace('/([a-z\d])([A-Z])/', '$1 $2', $raw) ?? $raw;
            // Replace non-alphanumeric characters with spaces
            $raw = preg_replace('/[^a-zA-Z0-9]+/', ' ', $raw) ?? $raw;
            // Split into parts
            $parts = preg_split('/\s+/', strtolower($raw), -1, PREG_SPLIT_NO_EMPTY);
            if (is_array($parts) && $parts !== []) {
                $suffix = implode('_', $parts);
            }
        }

        $timestamp = time();
        $name = $suffix === '' ? (string)$timestamp : $timestamp . '_' . $suffix;
        $file = $this->path . '/' . $name . '.php';

        while (file_exists($file)) {
            $timestamp++;
            $name = $suffix === '' ? (string)$timestamp : $timestamp . '_' . $suffix;
            $file = $this->path . '/' . $name . '.php';
        }

        $template = <<<'PHP'
<?php

return [
    'up' => function (PDO $pdo) {
        // TODO: Implement the migration.
    },
    'down' => function (PDO $pdo) {
        // TODO: Revert the migration.
    },
];

PHP;

        if (file_put_contents($file, $template) === false) {
            Console::error('Could not create migration file.');
            return 1;
        }

        Console::success(sprintf('Migration created: %s', $name));
        Console::line('Edit the file to define schema changes.');

        return 0;
    }

    /**
     * @param array<int, array{migration:string,ran:bool,batch:?int,ran_at:?string}> $rows
     */
    private function render(array $rows)
    {
        if ($rows === []) {
            Console::line('No defined migrations.');
            return;
        }

        $columns = [
            'migration' => 'Migration',
            'ran'       => 'Applied',
            'batch'     => 'Batch',
            'ran_at'    => 'Ran At',
        ];

        $lines = array_map(static function (array $row): array {
            return [
                'migration' => $row['migration'],
                'ran'       => $row['ran'] ? 'yes' : 'no',
                'batch'     => $row['batch'] !== null ? (string)$row['batch'] : '-',
                'ran_at'    => $row['ran_at'] ?? '-',
            ];
        }, $rows);

        Console::table($columns, $lines);
    }

    private function pending()
    {
        $applied = array_flip(
            array_map(
                fn(array $row): string => (string)$row['migration'],
                $this->applied()
            ),
        );

        $available = $this->available();

        return array_diff_key($available, $applied);
    }

    private function revert(string $name, ?string $file, string $failure)
    {
        if ($file === null) {
            Console::error($failure);
            Console::line('Failed on: ' . $name);
            return false;
        }

        $pdo = $this->pdo();

        try {
            $definition = $this->load($file);
            $pdo->beginTransaction();
            $definition['down']($pdo);
            $this->remove($name);
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            return true;
        } catch (Throwable $exception) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $message = $exception->getMessage();

            if ($message === '') {
                $message = $failure;
            }

            Console::error($message);
            Console::line('Failed on: ' . $name);

            return false;
        }
    }

    private function pdo()
    {
        $pdo = Container::get('db', null);
        if (!$pdo instanceof PDO) throw new RuntimeException('No database connection available.');
        return $pdo;
    }

    private function ensure()
    {
        $table = $this->table;
        $this->pdo()->exec("
            CREATE TABLE IF NOT EXISTS `{$table}` (
                migration VARCHAR(255) PRIMARY KEY,
                batch INTEGER NOT NULL,
                ran_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");
    }

    /**
     * @return array<string, string>
     */
    private function available(): array
    {
        $available = [];

        foreach (glob($this->path . '/*.php') as $file) {
            $available[(string)basename($file, '.php')] = $file;
        }

        ksort($available, SORT_STRING);

        return $available;
    }

    private function applied()
    {
        $table = $this->table;
        $sql = "SELECT migration, batch, ran_at FROM `{$table}` ORDER BY batch ASC, migration ASC";
        $statement = $this->pdo()->query($sql);

        if ($statement === false) {
            return [];
        }

        /** @var array<int, array{migration:string,batch:int,ran_at:string}> $rows */
        $rows = $statement->fetchAll();

        return $rows ?: [];
    }

    private function next()
    {
        $table = $this->table;
        $sql = "SELECT MAX(batch) FROM `{$table}`";
        $statement = $this->pdo()->query($sql);

        if ($statement === false) {
            return 1;
        }

        $batch = $statement->fetchColumn();

        return $batch === false ? 1 : ((int)$batch) + 1;
    }

    /**
     * @return array{up:callable,down:callable}
     */
    private function load(string $file): array
    {
        return require $file;
    }

    private function record(string $name, int $batch)
    {
        $table = $this->table;
        $sql = "INSERT INTO `{$table}` (migration, batch) VALUES (:migration, :batch)";
        $statement = $this->pdo()->prepare($sql);

        if ($statement === false) {
            throw new RuntimeException('Could not prepare migration insertion.');
        }

        $statement->execute([
            ':migration' => $name,
            ':batch'     => $batch,
        ]);
    }

    private function last()
    {
        $table = $this->table;
        $sql = "SELECT MAX(batch) FROM `{$table}`";
        $statement = $this->pdo()->query($sql);

        if ($statement === false) {
            return [];
        }

        $batch = $statement->fetchColumn();

        if ($batch === false || $batch === null) {
            return [];
        }

        $sql = "SELECT migration, batch, ran_at FROM `{$table}` WHERE batch = :batch ORDER BY migration DESC";
        $statement = $this->pdo()->prepare($sql);

        if ($statement === false) {
            return [];
        }

        $statement->execute([':batch' => $batch]);

        /** @var array<int, array{migration:string,batch:int,ran_at:string}> $rows */
        $rows = $statement->fetchAll();

        return $rows ?: [];
    }

    private function remove(string $name)
    {
        $table = $this->table;
        $sql = "DELETE FROM `{$table}` WHERE migration = :migration";
        $statement = $this->pdo()->prepare($sql);

        if ($statement === false) {
            throw new RuntimeException('Could not prepare migration deletion.');
        }

        $statement->execute([':migration' => $name]);
    }
}
