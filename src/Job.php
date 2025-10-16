<?php

namespace Ajo;

use PDO;
use DateTimeImmutable;
use Throwable;
use RuntimeException;
use InvalidArgumentException;

/**
 * Clase para manejar trabajos (jobs) en segundo plano.
 * 
 * Scheduler estilo cron
 *
 * Soporta: "*", listas "1,2", rangos "1-5", pasos "* /5", "1-30/2".
 * Reglas: AND para minuto/hora/mes. DOM y DOW se combinan al estilo cron:
 * - ambos "*"  => ignora ambos
 * - uno "*"    => aplica el otro
 * - ambos set  => OR (dom || dow)
 */
final class Job
{
    private static ?self $instance = null;

    /** @var array<int, array{name:string,cron:string,queue:string,conc:int,prio:int,lease:int,fn:callable}> */
    private array $jobs = [];
    private ?int $active = null;
    private bool $running = false;

    private function __construct() {}

    /**
     * Registra comandos CLI de jobs.
     */
    public static function register($cli)
    {
        $self = self::$instance ??= new self();

        $cli->command('jobs:install', fn() => $self->install())->describe('Inicializa la tabla de estado de jobs.');
        $cli->command('jobs:status', fn() => $self->status())->describe('Muestra el estado actual de los jobs.');
        $cli->command('jobs:collect', function () use ($self) {
            $n = $self->run();
            if ($n > 0) Console::success($n > 0 ? "Ejecutados {$n} job(s)." : 'No había jobs vencidos.');
        })->describe('Ejecuta los jobs vencidos.');
        $cli->command('jobs:work', fn() => $self->forever())->describe('Ejecuta jobs en segundo plano.');
        $cli->command('jobs:prune', fn() => $self->prune())->describe('Elimina estados de jobs no vistos recientemente.');

        return $self;
    }

    /**
     * Define un job con cron y un callable.
     */
    public static function schedule(string $cron, callable $handler)
    {
        $self = self::$instance ??= new self();

        $self->jobs[] = [
            'name'        => substr(sha1(__FILE__ . ':' . __LINE__ . ':' . $cron), 0, 12),
            'cron'        => trim($cron),
            'queue'       => 'default',
            'concurrency' => 1,
            'priority'    => 100,
            'lease'       => 3600, // 1 hour
            'handler'     => $handler,
        ];

        $self->active = array_key_last($self->jobs);

        return $self;
    }

    /**
     * Asigna nombre estable.
     */
    public function name(string $name)
    {
        return $this->tap(fn(&$job) => $job['name'] = $name);
    }

    /**
     * Coloca el job en una cola.
     */
    public function queue(?string $name)
    {
        return $this->tap(fn(&$job) => $job['queue'] = $name ?: 'default');
    }

    /**
     * Límite de concurrencia por cola.
     */
    public function concurrency(int $n)
    {
        return $this->tap(fn(&$job) => $job['concurrency'] = max(1, $n));
    }

    /**
     * Prioridad (menor corre antes).
     */
    public function priority(int $n)
    {
        return $this->tap(fn(&$job) => $job['priority'] = $n);
    }

    /**
     * Duración del lease (segundos).
     */
    public function lease(int $seconds)
    {
        return $this->tap(fn(&$job) => $job['lease'] = max(60, $seconds));
    }

    /**
     * Ejecuta jobs.
     */
    public function run()
    {
        $this->ensure();
        $this->sync();
    
        $pdo = $this->pdo();

        if (!$this->lock($pdo, 'jobs')) {
            return 0; // otro colector activo
        }

        try {
            $now        = new DateTimeImmutable('now');
            $tick       = $this->floor($now);
            $tickString = $tick->format('Y-m-d H:i:s');
            $jobs       = array_column($this->jobs, null, 'name');
            $rows       = $this->fetch(array_keys($jobs));
            $run        = [];
            $limit      = [];
            $due        = [];

            foreach ($rows as $row) {

                $job = $jobs[$row['name']] ?? null;

                if (!$job) continue;

                $queue = $job['queue'];

                $limit[$queue] = max($limit[$queue] ?? 1, $job['concurrency']);

                if ($this->active($row['lease_until'], $now)) {
                    $run[$queue] = ($run[$queue] ?? 0) + 1;
                    continue;
                }

                if (
                    !$this->due($job['cron'], $tick) ||
                    ($row['last_tick'] && $row['last_tick'] >= $tickString)
                ) {
                    continue;
                }

                $due[] = ['row' => $row, 'job' => $job, 'queue' => $queue];
            }

            usort($due, static fn($a, $b) => ($a['job']['priority'] <=> $b['job']['priority']) ?: strcmp($a['row']['name'], $b['row']['name']));

            $executed = 0;

            foreach ($due as $candidate) {

                ['row' => $row, 'job' => $job, 'queue' => $queue] = $candidate;

                $slots = $limit[$queue] ??= max(1, $job['concurrency']);
                $running = $run[$queue] ?? 0;

                if ($running >= $slots) continue;

                $lease = $now->modify('+' . $job['lease'] . ' seconds')->format('Y-m-d H:i:s');

                $this->set($row['name'], $lease);

                try {
                    ($job['handler'])();
                    $this->success($row['name'], $tick);
                    Console::success("{$row['name']}: {$job['cron']}");
                } catch (Throwable $e) {
                    $this->error($row['name'], $tick, $e->getMessage());
                    Console::error("{$row['name']}: " . $e->getMessage());
                } finally {
                    $this->clear($row['name']);
                }

                $run[$queue] = $running + 1;
                $executed++;
            }

            return $executed;

        } finally {
            $this->unlock($pdo, 'jobs');
        }
    }

    /**
     * Ejecuta jobs en segundo plano.
     */
    public function forever(int $sleep = 5)
    {
        Console::info("Ejecución de jobs iniciada (sleep: {$sleep}s)...");

        $sleep = max(0, $sleep);
        $restore = $this->trapSignals();
        $this->running = true;

        try {
            while ($this->running) {
                $this->dispatchSignals();
                if (!$this->running) {
                    break;
                }

                if ($this->run() === 0 && $this->running) {
                    $this->pause($sleep);
                }
            }
        } finally {
            $this->running = false;
            $restore();
            Console::info('Worker de jobs detenido.');
        }
    }

    /**
     * Detiene la ejecución del worker en la próxima iteración disponible.
     */
    public function stop(): void
    {
        $this->running = false;
    }

    private function install()
    {
        $this->ensure();
        Console::success('Tabla lista.');
        $this->sync();
        return 0;
    }

    private function status(): int
    {
        $this->ensure();

        $rows = $this->fetchAll();

        if ($rows === []) {
            Console::log('Total: 0 | Activos: 0 | Inactivos: 0');
            Console::blank();
            Console::log('No hay jobs registrados.');
            return 0;
        }

        $now = new DateTimeImmutable('now');
        $active = array_reduce(
            $rows,
            fn(int $carry, array $row): int => $carry + ($this->active($row['lease_until'] ?? null, $now) ? 1 : 0),
            0,
        );

        $total = count($rows);
        Console::log(sprintf(
            'Total: %d | Activos: %d | Inactivos: %d',
            $total,
            $active,
            max(0, $total - $active),
        ));

        Console::blank();
        $this->render($rows, $now);

        return 0;
    }

    private function prune(int $days = 30)
    {
        $this->ensure();

        $threshold = (new DateTimeImmutable('now'))
            ->modify('-' . max(0, $days) . ' days')
            ->format('Y-m-d H:i:s');

        $sql = "DELETE FROM jobs WHERE seen_at IS NULL OR seen_at < :threshold";
        $statement  = $this->pdo()->prepare($sql);
        $statement->execute([':threshold' => $threshold]);

        Console::success('Prune OK.');
    }

    private function tap(callable $f)
    {
        if ($this->active !== null) $f($this->jobs[$this->active]);
        return $this;
    }

    private function pdo()
    {
        $pdo = Context::get('db');
        if (!$pdo instanceof PDO) throw new RuntimeException('No hay conexión a la base de datos.');
        return $pdo;
    }

    private function ensure()
    {
        /**
         * last_tick: minuto en que se ejecutó por última vez (evita doble corrida en el mismo minuto)
         * lease_until: lease activo (si el proceso muere, otra instancia puede retomar cuando expire)
         * last_run: última ejecución exitosa
         * last_error, fail_count: obsevabilidad y futura implementación de retries
         * seen_at: manejo del ciclo de vida del job
         */
        $this->pdo()->exec("
            CREATE TABLE IF NOT EXISTS jobs (
                name VARCHAR(255) NOT NULL PRIMARY KEY,
                last_tick DATETIME NULL,
                lease_until DATETIME NULL,
                last_run DATETIME NULL,
                last_error TEXT NULL,
                fail_count INT NOT NULL DEFAULT 0,
                seen_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_lease (lease_until),
                KEY idx_seen (seen_at)
            );
        ");
    }

    private function sync()
    {
        if (!$this->jobs) return;

        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        $sql = "INSERT INTO jobs (name, seen_at) VALUES (:n, :s)
                ON DUPLICATE KEY UPDATE seen_at = VALUES(seen_at)";

        $statement  = $this->pdo()->prepare($sql);

        foreach ($this->jobs as $job) {
            $statement->execute([':n' => $job['name'], ':s' => $now]);
        }
    }

    /** @param string[] $names */
    private function fetch(array $names)
    {
        if (!$names) return [];

        $in = implode(',', array_fill(0, count($names), '?'));
        $statement = $this->pdo()->prepare("SELECT * FROM jobs WHERE name IN ($in)");
        $statement->execute(array_values($names));

        return $statement->fetchAll();
    }

    private function fetchAll(): array
    {
        $st = $this->pdo()->query("SELECT * FROM jobs ORDER BY name ASC");
        return $st ? $st->fetchAll() : [];
    }

    private function set(string $name, string $until): void
    {
        $sql = "UPDATE jobs SET lease_until = :u WHERE name = :n";
        $this->pdo()->prepare($sql)->execute([':u' => $until, ':n' => $name]);
    }

    private function clear(string $name): void
    {
        $sql = "UPDATE jobs SET lease_until = NULL WHERE name = :n";
        $this->pdo()->prepare($sql)->execute([':n' => $name]);
    }

    private function success(string $name, DateTimeImmutable $tick): void
    {
        $sql = "UPDATE jobs
                   SET last_run = :lr,
                       last_tick = :lt,
                       last_error = NULL
                 WHERE name = :n";

        $this->pdo()->prepare($sql)->execute([
            ':lr' => $tick->format('Y-m-d H:i:s'),
            ':lt' => $tick->format('Y-m-d H:i:s'),
            ':n' => $name,
        ]);
    }

    private function error(string $name, DateTimeImmutable $tick, string $message): void
    {

        $sql = "UPDATE jobs
                   SET last_tick = :lt,
                       last_error = :e,
                       fail_count = fail_count + 1
                 WHERE name = :n";

        $this->pdo()->prepare($sql)->execute([
            ':lt' => $tick->format('Y-m-d H:i:s'),
            ':e' => substr($message, 0, 2000),
            ':n' => $name,
        ]);
    }

    private function lock(PDO $pdo, string $key)
    {
        $query = $pdo->query("SELECT GET_LOCK(" . $pdo->quote($key) . ", 0)");
        return (bool)$query->fetchColumn();
    }

    private function unlock(PDO $pdo, string $key)
    {
        $pdo->query("DO RELEASE_LOCK(" . $pdo->quote($key) . ")");
    }

    private function active(?string $until, DateTimeImmutable $now): bool
    {
        return $until ? (new DateTimeImmutable($until) > $now) : false;
    }

    private function floor(DateTimeImmutable $timestamp): DateTimeImmutable
    {
        return $timestamp->setTime((int)$timestamp->format('H'), (int)$timestamp->format('i'), 0);
    }

    private function due(string $expr, DateTimeImmutable $tick): bool
    {
        $C = $this->parse($expr);
        $mi = (int)$tick->format('i');
        $ho = (int)$tick->format('G');
        $dm = (int)$tick->format('j');
        $mo = (int)$tick->format('n');
        $dw = (int)$tick->format('w'); // 0..6

        if (!isset($C['m'][$mi]) || !isset($C['h'][$ho]) || !isset($C['mon'][$mo])) return false;

        $dom = isset($C['dom'][$dm]);
        $dow = isset($C['dow'][$dw]);

        if ($C['domStar'] && $C['dowStar']) return true;
        if ($C['domStar']) return $dow;
        if ($C['dowStar']) return $dom;

        return $dom || $dow;
    }

    private function parse(string $expr)
    {
        $expr = trim(preg_replace('/\s+/', ' ', $expr));
        $p = explode(' ', $expr);

        if (count($p) !== 5) throw new InvalidArgumentException("Cron inválido: '$expr'");

        [$m, $h, $dom, $mon, $dow] = $p;

        return [
            'm'       => $this->expand($m,   0, 59, false),
            'h'       => $this->expand($h,   0, 23, false),
            'dom'     => $this->expand($dom, 1, 31, false),
            'mon'     => $this->expand($mon, 1, 12, false),
            'dow'     => $this->expand($dow, 0, 6, true),
            'domStar' => ($dom === '*'),
            'dowStar' => ($dow === '*'),
        ];
    }

    private function expand(string $field, int $min, int $max, bool $dow): array
    {
        $field = trim($field);

        if ($field === '*') {

            $all = [];

            for ($i = $min; $i <= $max; $i++) $all[$i] = true;

            return $all;
        }

        $out = [];

        foreach (explode(',', $field) as $tok) {

            if ($tok === '') continue;

            $step = 1;
            $base = $tok;

            if (strpos($tok, '/') !== false) {
                [$base, $s] = explode('/', $tok, 2);
                $step = max(1, (int)$s);
            }

            if ($base === '' || $base === '*') {
                $a = $min;
                $b = $max;
            } elseif (strpos($base, '-') !== false) {

                [$a, $b] = explode('-', $base, 2);

                $a = $this->normalize((int)$a, $min, $max, $dow);
                $b = $this->normalize((int)$b, $min, $max, $dow);

                if ($a > $b) {
                    $t = $a;
                    $a = $b;
                    $b = $t;
                }
            } else {
                $a = $b = $this->normalize((int)$base, $min, $max, $dow);
            }

            for ($i = $a; $i <= $b; $i += $step) $out[$i] = true;
        }

        return $out;
    }

    private function normalize(int $value, int $min, int $max, bool $dow)
    {
        if ($dow && $value === 7) $value = 0; // 7 ≡ domingo
        if ($value < $min) $value = $min;
        if ($value > $max) $value = $max;

        return $value;
    }

    private function trapSignals(): callable
    {
        if (!function_exists('pcntl_signal')) {
            return static function (): void {};
        }

        $signals = array_values(array_filter([
            defined('SIGINT') ? SIGINT : null,
            defined('SIGTERM') ? SIGTERM : null,
        ]));

        if ($signals === []) {
            return static function (): void {};
        }

        $previous = [];

        foreach ($signals as $signal) {
            $previous[$signal] = function_exists('pcntl_signal_get_handler')
                ? pcntl_signal_get_handler($signal)
                : SIG_DFL;

            pcntl_signal($signal, function () use ($signal): void {
                Console::info('Recibida ' . $this->signalName($signal) . ', apagando worker...');
                $this->stop();
            });
        }

        return static function () use ($signals, $previous): void {
            if (!function_exists('pcntl_signal')) return;
            foreach ($signals as $signal) {
                pcntl_signal($signal, $previous[$signal] ?? SIG_DFL);
            }
        };
    }

    private function dispatchSignals(): void
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }

    private function pause(int $seconds): void
    {
        while ($this->running && $seconds-- > 0) {
            sleep(1);
            $this->dispatchSignals();
        }
    }

    private function signalName(int $signal): string
    {
        return match (true) {
            defined('SIGINT') && $signal === SIGINT => 'SIGINT',
            defined('SIGTERM') && $signal === SIGTERM => 'SIGTERM',
            default => (string)$signal,
        };
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function render(array $rows, DateTimeImmutable $now): void
    {
        $columns = [
            'name'      => 'Nombre',
            'last_run'  => 'Ultima ejecucion',
            'last_tick' => 'Ultimo tick',
            'lease'     => 'Lease activo',
            'fails'     => 'Fails',
            'seen_at'   => 'Visto',
        ];

        $lines = array_map(function (array $row) use ($now): array {
            return [
                'name'      => (string)($row['name'] ?? '-'),
                'last_run'  => (string)($row['last_run'] ?? '-'),
                'last_tick' => (string)($row['last_tick'] ?? '-'),
                'lease'     => $this->active($row['lease_until'] ?? null, $now) ? 'si' : 'no',
                'fails'     => (string)($row['fail_count'] ?? 0),
                'seen_at'   => (string)($row['seen_at'] ?? '-'),
            ];
        }, $rows);

        Console::table($columns, $lines);
    }
}
