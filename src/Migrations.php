<?php

declare(strict_types=1);

namespace Ajo;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Manejo de migraciones de base de datos.
 */
final class Migrations
{
    private string $path;

    private function __construct(string $path)
    {
        $this->path = rtrim($path, '/');

        if (!is_dir($this->path)) {
            mkdir($this->path, 0o755, true);
        }
    }

    /**
     * Registra los comandos de migración en la consola.
     */
    public static function register(Console $cli, string $path)
    {
        $self = new self($path);

        $cli->command('migrate', fn() => $self->migrate())->describe('Ejecuta todas las migraciones pendientes.');
        $cli->command('migrate:status', fn() => $self->status())->describe('Muestra el estado actual de las migraciones.');
        $cli->command('migrate:rollback', fn() => $self->rollback())->describe('Revierte la última tanda de migraciones aplicada.');
        $cli->command('migrate:reset', fn() => $self->reset())->describe('Revierte todas las migraciones aplicadas.');
        $cli->command('migrate:fresh', fn() => $self->fresh())->describe('Resetea y vuelve a ejecutar todas las migraciones.');
        $cli->command('migrate:refresh', fn() => $self->refresh())->describe('Revierte la última tanda y la vuelve a aplicar.');
        $cli->command('migrate:install', fn() => $self->install())->describe('Crea la tabla de seguimiento de migraciones.');
        $cli->command('migrate:make', fn() => $self->make())->describe('Genera un archivo de migración.');

        return $self;
    }

    private function migrate()
    {
        $this->ensure();

        $pending = $this->pending();

        if ($pending === []) {
            Console::log('No hay migraciones pendientes.');
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
                    : 'No se pudieron ejecutar las migraciones.';

                Console::error($message);
                Console::log('Fallo en: ' . $migration);

                return 1;
            }
        }

        Console::success(sprintf('Migraciones aplicadas. Batch %d, %d entradas procesadas.', $batch, count($migrated)));

        foreach ($migrated as $migration) {
            Console::log('  - ' . $migration);
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

        Console::log(sprintf(
            'Total: %d | Aplicadas: %d | Pendientes: %d',
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
            Console::log('No hay migraciones para revertir.');
            return 0;
        }

        $rolled = [];

        foreach ($latest as $row) {

            $name = (string)$row['migration'];
            $file = $available[$name] ?? null;

            if (!$this->revert($name, $file, 'No se pudieron revertir las migraciones.')) {
                return 1;
            }

            $rolled[] = $name;
        }

        Console::success(sprintf('Migraciones revertidas. Procesadas: %d.', count($rolled)));

        foreach ($rolled as $name) {
            Console::log('  - ' . $name);
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

            if (!$this->revert($name, $file, 'No se pudieron resetear las migraciones.')) {
                return 1;
            }

            $rolled[] = $name;
        }

        if ($rolled === []) {
            Console::log('No hay migraciones para resetear.');
            return 0;
        }

        Console::success(sprintf('Migraciones reseteadas. Procesadas: %d.', count($rolled)));

        foreach ($rolled as $name) {
            Console::log('  - ' . $name);
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

        $sql = 'SELECT COUNT(*) FROM migrations';
        $statement = $this->pdo()->prepare($sql);

        if ($statement === false) {
            throw new RuntimeException('No se pudo preparar la consulta de conteo.');
        }

        $statement->execute();
        $count = (int)$statement->fetchColumn();

        if ($count > 0) {
            Console::log('La tabla de migraciones ya está inicializada.');
            return 0;
        }

        $this->record('bootstrap', 0);
        Console::success('Registro de migraciones inicializado.');

        return 0;
    }

    private function make()
    {
        if (!is_writable($this->path)) {
            Console::error('No se puede escribir en el directorio de migraciones.');
            return 1;
        }

        $raw = Console::arguments()[0] ?? null;
        $suffix = '';

        if ($raw !== null && ($raw = trim($raw)) !== '') {
            // Añadir espacio entre palabras
            $raw = preg_replace('/([a-z\d])([A-Z])/', '$1 $2', $raw) ?? $raw;
            // Reemplazar caracteres no alfanuméricos por espacios
            $raw = preg_replace('/[^a-zA-Z0-9]+/', ' ', $raw) ?? $raw;
            // Dividir en partes
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
        // TODO: Implementar la migración.
    },
    'down' => function (PDO $pdo) {
        // TODO: Revertir la migración.
    },
];

PHP;

        if (file_put_contents($file, $template) === false) {
            Console::error('No se pudo crear el archivo de migración.');
            return 1;
        }

        Console::success(sprintf('Migración creada: %s', $name));
        Console::log('Edita el archivo para definir los cambios de esquema.');

        return 0;
    }

    /**
     * @param array<int, array{migration:string,ran:bool,batch:?int,ran_at:?string}> $rows
     */
    private function render(array $rows)
    {
        if ($rows === []) {
            Console::log('No hay migraciones definidas.');
            return;
        }

        $columns = [
            'migration' => 'Migracion',
            'ran'       => 'Aplicada',
            'batch'     => 'Batch',
            'ran_at'    => 'Ejecutada',
        ];

        $lines = array_map(static function (array $row): array {
            return [
                'migration' => $row['migration'],
                'ran'       => $row['ran'] ? 'si' : 'no',
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
            Console::log('Fallo en: ' . $name);
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
            Console::log('Fallo en: ' . $name);

            return false;
        }
    }

    private function pdo()
    {
        $pdo = Container::get('db');
        if (!$pdo instanceof PDO) throw new RuntimeException('No hay conexión a la base de datos.');
        return $pdo;
    }

    private function ensure()
    {
        $this->pdo()->exec("
            CREATE TABLE IF NOT EXISTS migrations (
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
        $sql = 'SELECT migration, batch, ran_at FROM migrations ORDER BY batch ASC, migration ASC';
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
        $sql = 'SELECT MAX(batch) FROM migrations';
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
        $sql = 'INSERT INTO migrations (migration, batch) VALUES (:migration, :batch)';
        $statement = $this->pdo()->prepare($sql);

        if ($statement === false) {
            throw new RuntimeException('No se pudo preparar la inserción de migraciones.');
        }

        $statement->execute([
            ':migration' => $name,
            ':batch'     => $batch,
        ]);
    }

    private function last()
    {
        $sql = 'SELECT MAX(batch) FROM migrations';
        $statement = $this->pdo()->query($sql);

        if ($statement === false) {
            return [];
        }

        $batch = $statement->fetchColumn();

        if ($batch === false || $batch === null) {
            return [];
        }

        $sql = 'SELECT migration, batch, ran_at FROM migrations WHERE batch = :batch ORDER BY migration DESC';
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
        $sql = 'DELETE FROM migrations WHERE migration = :migration';
        $statement = $this->pdo()->prepare($sql);

        if ($statement === false) {
            throw new RuntimeException('No se pudo preparar la eliminación de migraciones.');
        }

        $statement->execute([':migration' => $name]);
    }
}
