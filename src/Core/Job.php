<?php

declare(strict_types=1);

namespace Ajo\Core;

use Ajo\Console as ConsoleFacade;
use Ajo\Container;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Clase para manejar trabajos (jobs) en segundo plano.
 */
final class Job
{
    /** @var array<int, array{name:string,cron:string,queue:string,concurrency:int,priority:int,lease:int,handler:callable}> */
    private array $jobs = [];
    private ?int $active = null;
    private bool $running = false;

    public function register(Console $cli): self
    {
        $cli->command('jobs:install', fn() => $this->install())->describe('Inicializa la tabla de estado de jobs.');
        $cli->command('jobs:status', fn() => $this->status())->describe('Muestra el estado actual de los jobs.');
        $cli->command('jobs:collect', function () {
            $n = $this->run();
            if ($n > 0) ConsoleFacade::success($n > 0 ? "Ejecutados {$n} job(s)." : 'No había jobs vencidos.');
        })->describe('Ejecuta los jobs vencidos.');
        $cli->command('jobs:work', fn() => $this->forever())->describe('Ejecuta jobs en segundo plano.');
        $cli->command('jobs:prune', fn() => $this->prune())->describe('Elimina estados de jobs no vistos recientemente.');

        return $this;
    }

    public function schedule(string $cron, callable $handler): self
    {
        $this->jobs[] = [
            'name'        => substr(sha1(__FILE__ . ':' . __LINE__ . ':' . $cron), 0, 12),
            'cron'        => trim($cron),
            'queue'       => 'default',
            'concurrency' => 1,
            'priority'    => 100,
            'lease'       => 3600,
            'handler'     => $handler,
        ];

        $this->active = array_key_last($this->jobs);

        return $this;
    }

    public function name(string $name): self
    {
        return $this->tap(fn(array &$job) => $job['name'] = $name);
    }

    public function queue(?string $name): self
    {
        return $this->tap(fn(array &$job) => $job['queue'] = $name ?: 'default');
    }

    public function concurrency(int $n): self
    {
        return $this->tap(fn(array &$job) => $job['concurrency'] = max(1, $n));
    }

    public function priority(int $n): self
    {
        return $this->tap(fn(array &$job) => $job['priority'] = $n);
    }

    public function lease(int $seconds): self
    {
        return $this->tap(fn(array &$job) => $job['lease'] = max(60, $seconds));
    }

    public function run(): int
    {
        $this->ensure();
        $this->sync();

        $pdo = $this->pdo();

        if (!$this->lock($pdo, 'jobs')) {
            return 0;
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

                if (!$this->due($job['cron'], $tick) || ($row['last_tick'] && $row['last_tick'] >= $tickString)) {
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
                    ConsoleFacade::success("{$row['name']}: {$job['cron']}");
                } catch (Throwable $e) {
                    $this->error($row['name'], $tick, $e->getMessage());
                    ConsoleFacade::error("{$row['name']}: " . $e->getMessage());
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

    public function forever(int $sleep = 5): void
    {
        ConsoleFacade::info("Ejecución de jobs iniciada (sleep: {$sleep}s)...");

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
            ConsoleFacade::info('Worker de jobs detenido.');
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }

    private function install(): int
    {
        $this->ensure();
        ConsoleFacade::success('Tabla lista.');
        $this->sync();
        return 0;
    }

    private function status(): int
    {
        $this->ensure();

        $rows = $this->fetchAll();

        if ($rows === []) {
            ConsoleFacade::log('Definidos: 0 | Ejecutando: 0 | Inactivos: 0 | Retirados: 0');
            ConsoleFacade::blank();
            ConsoleFacade::log('No hay jobs registrados.');
            return 0;
        }

        $now = new DateTimeImmutable('now');
        $catalog = array_filter($rows, static fn(array $row): bool => ($row['retired_at'] ?? null) === null);
        $retired = count($rows) - count($catalog);

        $active = array_reduce(
            $catalog,
            fn(int $carry, array $row): int => $carry + ($this->active($row['lease_until'] ?? null, $now) ? 1 : 0),
            0,
        );

        $total = count($catalog);
        $inactive = max(0, $total - $active);

        ConsoleFacade::log(sprintf(
            'Definidos: %d | Ejecutando: %d | Inactivos: %d | Retirados: %d',
            $total,
            $active,
            $inactive,
            $retired,
        ));

        ConsoleFacade::blank();
        $this->render($rows, $now);

        return 0;
    }

    private function prune(int $markDays = 30, ?int $sweepDays = null): void
    {
        $this->ensure();

        $pdo = $this->pdo();

        if (!$this->lock($pdo, 'jobs:prune')) {
            ConsoleFacade::info('Prune omitido: otro proceso posee el lock.');
            return;
        }

        try {
            $now = new DateTimeImmutable('now');
            $markDays = max(0, $markDays);
            $sweepDays = $sweepDays === null
                ? max(90, $markDays * 3)
                : max($markDays + 1, $sweepDays);

            $markCutoff = $now->modify('-' . $markDays . ' days')->format('Y-m-d H:i:s');
            $sweepCutoff = $now->modify('-' . $sweepDays . ' days')->format('Y-m-d H:i:s');

            $mark = $pdo->prepare("
                UPDATE jobs
                   SET retired_at = NOW()
                 WHERE retired_at IS NULL
                   AND (seen_at IS NULL OR seen_at < :markCutoff)
                   AND (lease_until IS NULL OR lease_until < NOW())
                   AND (last_tick IS NULL OR last_tick < :markCutoff)
            ");

            $mark->execute([':markCutoff' => $markCutoff]);
            $retired = $mark->rowCount();

            $sweep = $pdo->prepare("
                DELETE FROM jobs
                 WHERE retired_at IS NOT NULL
                   AND retired_at < :sweepCutoff
                   AND (lease_until IS NULL OR lease_until < NOW())
                   AND (last_tick IS NULL OR last_tick < :markCutoff)
            ");

            $sweep->execute([
                ':sweepCutoff' => $sweepCutoff,
                ':markCutoff' => $markCutoff,
            ]);

            $deleted = $sweep->rowCount();

            ConsoleFacade::success("Prune OK. Retirados: {$retired}. Eliminados: {$deleted}.");
        } finally {
            $this->unlock($pdo, 'jobs:prune');
        }
    }

    private function tap(callable $callback): self
    {
        if ($this->active !== null) {
            $callback($this->jobs[$this->active]);
        }

        return $this;
    }

    private function pdo(): PDO
    {
        $pdo = Container::get('db');
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('No hay conexión a la base de datos.');
        }

        return $pdo;
    }

    private function ensure(): void
    {
        $this->pdo()->exec("
            CREATE TABLE IF NOT EXISTS jobs (
                name VARCHAR(255) NOT NULL PRIMARY KEY,
                last_tick DATETIME NULL,
                lease_until DATETIME NULL,
                last_run DATETIME NULL,
                last_error TEXT NULL,
                fail_count INT NOT NULL DEFAULT 0,
                seen_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                retired_at DATETIME NULL,
                KEY idx_lease (lease_until),
                KEY idx_seen (seen_at),
                KEY idx_retired (retired_at)
            );
        ");
    }

    private function sync(): void
    {
        if ($this->jobs === []) return;

        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        $sql = "INSERT INTO jobs (name, seen_at) VALUES (:n, :s)
                ON DUPLICATE KEY UPDATE seen_at = VALUES(seen_at), retired_at = NULL";

        $statement = $this->pdo()->prepare($sql);

        foreach ($this->jobs as $job) {
            $statement->execute([':n' => $job['name'], ':s' => $now]);
        }
    }

    private function fetch(array $names): array
    {
        if ($names === []) return [];

        $in = implode(',', array_fill(0, count($names), '?'));
        $statement = $this->pdo()->prepare("SELECT * FROM jobs WHERE name IN ($in) AND retired_at IS NULL");
        $statement->execute(array_values($names));

        return $statement->fetchAll();
    }

    private function fetchAll(): array
    {
        $st = $this->pdo()->query("SELECT * FROM jobs ORDER BY retired_at IS NULL DESC, name ASC");
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
            ':n'  => $name,
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
            ':e'  => $message,
            ':n'  => $name,
        ]);
    }

    private function render(array $rows, DateTimeImmutable $now): void
    {
        $headers = ['name' => 'Nombre', 'queue' => 'Queue', 'prio' => 'Pri', 'lease' => 'Lease', 'cron' => 'Cron'];

        $data = array_map(function (array $row) use ($now): array {
            $seen = $row['seen_at'] ? $this->since($row['seen_at'], $now) : '-';
            $last = $row['last_run'] ? $this->since($row['last_run'], $now) : '-';
            $tick = $row['last_tick'] ? $this->since($row['last_tick'], $now) : '-';
            $lease = $row['lease_until'] ? $this->since($row['lease_until'], $now) : '-';

            return [
                'name'  => $row['name'],
                'queue' => $row['queue'] ?? '-',
                'prio'  => $row['priority'] ?? '-',
                'lease' => $lease,
                'cron'  => '-',
                'seen'  => $seen,
                'last'  => $last,
                'tick'  => $tick,
                'fails' => $row['fail_count'] ?? 0,
                'error' => $row['last_error'] ?? '-',
            ];
        }, $rows);

        ConsoleFacade::table($headers, $data);
    }

    private function lock(PDO $pdo, string $name): bool
    {
        $statement = $pdo->prepare('SELECT GET_LOCK(:name, 0)');
        $statement->execute([':name' => $name]);
        $result = $statement->fetchColumn();
        $statement->closeCursor();

        return (bool)$result;
    }

    private function unlock(PDO $pdo, string $name): void
    {
        $statement = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
        $statement->execute([':name' => $name]);
        $statement->closeCursor();
    }

    private function active(?string $leaseUntil, DateTimeImmutable $now): bool
    {
        return $leaseUntil !== null && new DateTimeImmutable($leaseUntil) > $now;
    }

    private function floor(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->setTime((int)$date->format('H'), (int)$date->format('i'));
    }

    private function due(string $cron, DateTimeImmutable $now): bool
    {
        $parts = $this->parse($cron);

        $minute = (int)$now->format('i');
        $hour = (int)$now->format('G');
        $dom = (int)$now->format('j');
        $mon = (int)$now->format('n');
        $dow = (int)$now->format('w');

        if (!isset($parts['min'][$minute], $parts['hour'][$hour], $parts['mon'][$mon])) {
            return false;
        }

        $domMatch = isset($parts['dom'][$dom]);
        $dowMatch = isset($parts['dow'][$dow]);

        if ($parts['domStar'] && $parts['dowStar']) {
            return true;
        }

        if ($parts['domStar']) {
            return $dowMatch;
        }

        if ($parts['dowStar']) {
            return $domMatch;
        }

        return $domMatch && $dowMatch;
    }

    private function parse(string $expression): array
    {
        [$min, $hour, $dom, $mon, $dow] = array_pad(preg_split('/\s+/', trim($expression), 5), 5, '*');

        return [
            'min'     => $this->expand($min, 0, 59, false),
            'hour'    => $this->expand($hour, 0, 23, false),
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
            for ($i = $min; $i <= $max; $i++) {
                $all[$i] = true;
            }

            return $all;
        }

        $out = [];

        foreach (explode(',', $field) as $token) {
            if ($token === '') continue;

            $step = 1;
            $base = $token;

            if (str_contains($token, '/')) {
                [$base, $s] = explode('/', $token, 2);
                $step = max(1, (int)$s);
            }

            if ($base === '' || $base === '*') {
                $a = $min;
                $b = $max;
            } elseif (str_contains($base, '-')) {
                [$a, $b] = explode('-', $base, 2);
                $a = $this->normalize((int)$a, $min, $max, $dow);
                $b = $this->normalize((int)$b, $min, $max, $dow);

                if ($a > $b) {
                    [$a, $b] = [$b, $a];
                }
            } else {
                $a = $b = $this->normalize((int)$base, $min, $max, $dow);
            }

            for ($i = $a; $i <= $b; $i += $step) {
                $out[$i] = true;
            }
        }

        return $out;
    }

    private function normalize(int $value, int $min, int $max, bool $dow): int
    {
        if ($dow && $value === 7) {
            $value = 0;
        }

        return max($min, min($max, $value));
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
                ConsoleFacade::info('Recibida ' . $this->signalName($signal) . ', apagando worker...');
                $this->stop();
            });
        }

        return static function () use ($signals, $previous): void {
            if (!function_exists('pcntl_signal')) {
                return;
            }

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

    private function since(?string $timestamp, DateTimeImmutable $now): string
    {
        if ($timestamp === null) {
            return '-';
        }

        $diff = $now->getTimestamp() - (new DateTimeImmutable($timestamp))->getTimestamp();

        return match (true) {
            $diff < 60 => $diff . 's',
            $diff < 3600 => intdiv($diff, 60) . 'm',
            $diff < 86400 => intdiv($diff, 3600) . 'h',
            default => intdiv($diff, 86400) . 'd',
        };
    }
}
