<?php

declare(strict_types=1);

namespace Ajo\Core;

use Ajo\Core\Console as CoreConsole;
use Ajo\Console;
use Ajo\Container;
use BadMethodCallException;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Job scheduler with cron-based execution, concurrency control, and persistent state.
 *
 * Supports 5-field (minute precision) and 6-field (second precision) cron expressions.
 * Manages job execution with database-backed state, leases, and error tracking.
 */
final class Job
{
    private const DB_DATETIME_FORMAT = 'Y-m-d H:i:s';

    /** @var array<string, array> Job configurations (legacy format for test compatibility) */
    private array $jobs = [];

    private ?string $activeJobName = null;
    public private(set) bool $running = false;
    private ?DateTimeImmutable $nextWakeTime = null;
    private ClockInterface $clock;

    public function __construct(?ClockInterface $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
    }

    // PUBLIC API =============================================================

    /** Registers job commands in the CLI */
    public function register(CoreConsole $cli): self
    {
        $commands = [
            'jobs:install' => [fn() => $this->install(), 'Initializes the jobs state table.'],
            'jobs:status'  => [fn() => $this->status(),  'Shows the current status of jobs.'],
            'jobs:collect' => [fn() => $this->collect(), 'Executes due jobs.'],
            'jobs:work'    => [fn() => $this->forever(), 'Runs jobs in the background.'],
            'jobs:prune'   => [fn() => $this->prune(),   'Removes states of jobs not seen recently.'],
        ];

        foreach ($commands as $name => [$handler, $description]) {
            $cli->command($name, $handler)->describe($description);
        }

        return $this;
    }

    /** Registers a scheduled job with a unique name and handler */
    public function schedule(string $name, callable $handler): self
    {
        if (isset($this->jobs[$name])) throw new RuntimeException("Job '{$name}' is already defined.");

        $this->jobs[$name] = [
            'cron'        => null,
            'queue'       => 'default',
            'concurrency' => 1,
            'priority'    => 100,
            'lease'       => 3600,
            'handler'     => $handler,
            'parsed'      => null,
            'filters'     => [],
            'before'      => [],
            'success'     => [],
            'error'       => [],
            'after'       => [],
        ];

        $this->activeJobName = $name;

        return $this;
    }

    /** Sets an explicit cron expression for the active job */
    public function cron(string $expression): self
    {
        return $this->modifyJob(function (array &$job) use ($expression): void {

            $expression = trim($expression);
            $parts = preg_split('/\s+/', $expression);

            // Normalize to 6-field by prepending '0' if 5-field
            if ($parts !== false && count($parts) === 5) $expression = '0 ' . $expression;

            $job['cron'] = $expression;
            $job['parsed'] = CronParser::parse($job['cron']);
        });
    }

    /**
     * Dynamic dispatcher for frequency helper methods
     *
     * @method self everySecond()
     * @method self everyTwoSeconds()
     * @method self everyFiveSeconds()
     * @method self everyTenSeconds()
     * @method self everyFifteenSeconds()
     * @method self everyTwentySeconds()
     * @method self everyThirtySeconds()
     * @method self everyMinute()
     * @method self everyTwoMinutes()
     * @method self everyThreeMinutes()
     * @method self everyFourMinutes()
     * @method self everyFiveMinutes()
     * @method self everyTenMinutes()
     * @method self everyFifteenMinutes()
     * @method self everyThirtyMinutes()
     * @method self hourly()
     * @method self everyTwoHours()
     * @method self everyThreeHours()
     * @method self everyFourHours()
     * @method self everySixHours()
     * @method self daily()
     * @method self weekly()
     * @method self monthly()
     * @method self quarterly()
     * @method self yearly()
     * @method self weekdays()
     * @method self weekends()
     * @method self sundays()
     * @method self mondays()
     * @method self tuesdays()
     * @method self wednesdays()
     * @method self thursdays()
     * @method self fridays()
     * @method self saturdays()
     */
    public function __call(string $name, array $args): self
    {
        $frequencies = [
            // Second-based
            'everySecond'        => '* * * * * *',
            'everyTwoSeconds'    => '*/2 * * * * *',
            'everyFiveSeconds'   => '*/5 * * * * *',
            'everyTenSeconds'    => '*/10 * * * * *',
            'everyFifteenSeconds' => '*/15 * * * * *',
            'everyTwentySeconds' => '*/20 * * * * *',
            'everyThirtySeconds' => '*/30 * * * * *',
            // Minute-based
            'everyMinute'        => '0 * * * * *',
            'everyTwoMinutes'    => '0 */2 * * * *',
            'everyThreeMinutes'  => '0 */3 * * * *',
            'everyFourMinutes'   => '0 */4 * * * *',
            'everyFiveMinutes'   => '0 */5 * * * *',
            'everyTenMinutes'    => '0 */10 * * * *',
            'everyFifteenMinutes' => '0 */15 * * * *',
            'everyThirtyMinutes' => '0 */30 * * * *',
            // Hour-based
            'hourly'             => '0 0 * * * *',
            'everyTwoHours'      => '0 0 */2 * * *',
            'everyThreeHours'    => '0 0 */3 * * *',
            'everyFourHours'     => '0 0 */4 * * *',
            'everySixHours'      => '0 0 */6 * * *',
            // Day-based
            'daily'              => '0 0 0 * * *',
            'weekly'             => '0 0 0 * * 0',
            'monthly'            => '0 0 0 1 * *',
            'quarterly'          => '0 0 0 1 */3 *',
            'yearly'             => '0 0 0 1 1 *',
            'weekdays'           => '0 0 0 * * 1-5',
            'weekends'           => '0 0 0 * * 0,6',
            'sundays'            => '0 0 0 * * 0',
            'mondays'            => '0 0 0 * * 1',
            'tuesdays'           => '0 0 0 * * 2',
            'wednesdays'         => '0 0 0 * * 3',
            'thursdays'          => '0 0 0 * * 4',
            'fridays'            => '0 0 0 * * 5',
            'saturdays'          => '0 0 0 * * 6',
        ];

        if (isset($frequencies[$name])) return $this->cron($frequencies[$name]);

        throw new BadMethodCallException("Method {$name} does not exist on " . self::class);
    }

    /** Limits execution to specific second(s) */
    public function second(int|array $seconds): self
    {
        return $this->modifyCron(0, $seconds);
    }

    /** Limits execution to specific minute(s) */
    public function minute(int|array $minutes): self
    {
        return $this->modifyCron(1, $minutes);
    }

    /** Limits execution to specific hour(s) */
    public function hour(int|array $hours): self
    {
        return $this->modifyCron(2, $hours);
    }

    /** Limits execution to specific day(s) of the month */
    public function day(int|array $days): self
    {
        return $this->modifyCron(3, $days);
    }

    /** Limits execution to specific month(s) */
    public function month(int|array $months): self
    {
        return $this->modifyCron(4, $months);
    }

    /** Helper to modify a specific cron field */
    private function modifyCron(int $fieldIndex, int|array $values): self
    {
        return $this->modifyJob(function (array &$job) use ($fieldIndex, $values): void {

            $parts = explode(' ', $job['cron'] ??= '0 0 0 * * *');

            if (count($parts) === 6) {
                $parts[$fieldIndex] = is_array($values) ? implode(',', $values) : (string)$values;
                $job['cron'] = implode(' ', $parts);
                $job['parsed'] = CronParser::parse($job['cron']);
            }
        });
    }

    /** Execute job only when callback returns true (can be called multiple times, all must pass) */
    public function when(callable $callback): self
    {
        return $this->modifyJob(fn(array &$job) => $job['filters'][] = $callback);
    }

    /** Skip job execution when callback returns true (can be called multiple times, any can skip) */
    public function skip(callable $callback): self
    {
        return $this->modifyJob(fn(array &$job) => $job['filters'][] = fn() => !$callback());
    }

    /** Sets the queue for the active job */
    public function queue(?string $name): self
    {
        return $this->modifyJob(fn(array &$job) => $job['queue'] = $name ?: 'default');
    }

    /** Sets concurrency limit for the active job */
    public function concurrency(int $n): self
    {
        return $this->modifyJob(fn(array &$job) => $job['concurrency'] = max(1, $n));
    }

    /** Sets execution priority for the active job */
    public function priority(int $n): self
    {
        return $this->modifyJob(fn(array &$job) => $job['priority'] = $n);
    }

    /** Sets lease duration in seconds for the active job (minimum 60) */
    public function lease(int $seconds): self
    {
        return $this->modifyJob(fn(array &$job) => $job['lease'] = max(60, $seconds));
    }

    /** Adds a hook to execute before the job handler */
    public function onBefore(callable $callback): self
    {
        return $this->addHook('before', $callback);
    }

    /** Adds a hook to execute after successful job execution */
    public function onSuccess(callable $callback): self
    {
        return $this->addHook('success', $callback);
    }

    /** Adds a hook to execute when job throws an exception (receives Throwable) */
    public function onError(callable $callback): self
    {
        return $this->addHook('error', $callback);
    }

    /** Adds a hook to always execute after the job (finally block) */
    public function onAfter(callable $callback): self
    {
        return $this->addHook('after', $callback);
    }

    /** Dispatches a job to execute ASAP (enqueues it) */
    public function dispatch(string $name, ?callable $handler = null): self
    {
        if (!isset($this->jobs[$name])) {

            if (!$handler) throw new RuntimeException("Job '{$name}' not found and no handler provided.");

            $this->schedule($name, $handler)->queue('default');
        }

        $this->prepareDatabase();

        $this->updateJobState($name, [
            'enqueued_at' => $this->nowAsDbString(),
            'last_run' => null,
            'lease_until' => null,
        ]);

        return $this;
    }

    /** Stops the background worker */
    public function stop(): void
    {
        $this->running = false;
    }

    // EXECUTION ==============================================================

    /** Executes all due jobs once and returns count of executed jobs */
    public function run(): int
    {
        $this->prepareDatabase();
        $this->nextWakeTime = null;

        $result = $this->selectDueJobs();

        $this->nextWakeTime = $result['next'];

        foreach ($result['selected'] as $entry) $this->executeJob($entry['job'], $entry['row']['name']);

        return count($result['selected']);
    }

    /** Runs jobs continuously in the background */
    public function forever(): int
    {
        Console::info('Job execution started...');
        $restoreSignals = $this->trapSignals();
        $this->running = true;

        try {
            while ($this->running) {
                $this->dispatchSignals();
                if (!$this->running) break;
                $executed = $this->run();
                $this->dispatchSignals();
                if (!$this->running) break;
                if ($executed === 0) $this->sleepUntilNext();
            }
        } finally {
            $this->running = false;
            $restoreSignals();
            Console::info('Job worker stopped.');
        }

        return 0;
    }

    // COMMANDS ===============================================================

    private function install(): int
    {
        $this->prepareDatabase();
        Console::success('Table ready.');
        return 0;
    }

    private function collect(): int
    {
        $executed = $this->run();
        $executed === 0
            ? Console::info('No due jobs.')
            : Console::success("Executed {$executed} job(s).");
        return 0;
    }

    private function status(): int
    {
        $this->ensureTableExists();

        $rows = $this->fetchAllJobs();

        if ($rows === []) {
            Console::log('Defined: 0 | Running: 0 | Idle: 0');
            Console::blank();
            Console::log('No registered jobs.');
            return 0;
        }

        $now = $this->clock->now();
        $active = count(array_filter(
            $rows,
            fn($r) => ($r['lease_until'] ?? null) && new DateTimeImmutable($r['lease_until']) > $now
        ));

        Console::log(sprintf(
            'Defined: %d | Running: %d | Idle: %d',
            count($rows),
            $active,
            count($rows) - $active
        ));
        Console::blank();

        $this->renderJobTable($rows, $now);

        return 0;
    }

    private function prune(int $days = 30): int
    {
        $this->ensureTableExists();

        $result = $this->withDatabaseLock('jobs:prune', function () use ($days) {

            $cutoff = $this->clock->now()
                ->modify('-' . max(0, $days) . ' days')
                ->format(self::DB_DATETIME_FORMAT);

            $stmt = $this->pdo()->prepare("
                DELETE FROM jobs
                 WHERE (seen_at IS NULL OR seen_at < :cutoff)
                   AND (lease_until IS NULL OR lease_until < NOW())
            ");

            $stmt->execute([':cutoff' => $cutoff]);

            Console::success("Pruned {$stmt->rowCount()} job state(s).");

            return true;
        });

        if ($result === null) Console::info('Prune skipped: another process holds the lock.');

        return 0;
    }

    // JOB SELECTION & EXECUTION ==============================================

    /** Selects jobs that are due and not blocked by concurrency limits */
    private function selectDueJobs(): array
    {
        if ($this->jobs === []) return ['selected' => [], 'next' => null];

        $now = $this->clock->now();
        $rows = $this->fetchJobs(array_keys($this->jobs));

        $candidates = $this->buildCandidatePool($rows, $now);
        $selected = $this->acquireLeases($candidates, $now);

        return [
            'selected' => $selected['jobs'],
            'next' => $selected['next'] ?? $candidates['next'],
        ];
    }

    /** Builds pool of due jobs and tracks queue concurrency */
    private function buildCandidatePool(array $rows, DateTimeImmutable $now): array
    {
        $pool = [];
        $queueLimits = [];
        $queueBusy = [];
        $nextWake = null;

        foreach ($rows as $row) {

            $job = $this->jobs[$row['name']] ?? null;

            if (!$job) continue;

            $queue = $job['queue'];
            $queueLimits[$queue] = max($queueLimits[$queue] ?? 1, $job['concurrency']);

            // Check if lease is still active
            $leaseUntil = ($row['lease_until'] ?? null) ? new DateTimeImmutable($row['lease_until']) : null;

            if ($leaseUntil && $leaseUntil > $now) {
                $queueBusy[$queue] = ($queueBusy[$queue] ?? 0) + 1;
                $nextWake = $this->earliestTime($nextWake, $leaseUntil);
                continue;
            }

            // Check if job is due (scheduled) or dispatched
            $isDispatched = !empty($row['enqueued_at']);

            if ($isDispatched) {
                $pool[] = ['row' => $row, 'job' => $job, 'queue' => $queue];
            } else {

                $lastRun = ($row['last_run'] ?? null) ? new DateTimeImmutable($row['last_run']) : null;
                $status = CronEvaluator::evaluate($job['parsed'], $now, $lastRun);
                $nextWake = $this->earliestTime($nextWake, $status['next']);

                if ($status['due']) {
                    $pool[] = ['row' => $row, 'job' => $job, 'queue' => $queue];
                }
            }
        }

        return [
            'pool' => $pool,
            'limits' => $queueLimits,
            'busy' => $queueBusy,
            'next' => $nextWake,
        ];
    }

    /** Acquires leases for jobs respecting concurrency limits */
    private function acquireLeases(array $candidates, DateTimeImmutable $now): array
    {
        $selected = [];
        $queueBusy = $candidates['busy'];

        foreach ($candidates['pool'] as $entry) {

            $queue = $entry['queue'];

            // Skip if queue is at capacity
            if (($queueBusy[$queue] ?? 0) >= ($candidates['limits'][$queue] ?? 1)) continue;

            // Atomically acquire lease (prevents race conditions with multiple workers)
            if ($this->acquireLease($entry['row']['name'], $entry['job']['lease'])) {
                $selected[] = $entry;
                $queueBusy[$queue] = ($queueBusy[$queue] ?? 0) + 1;
            }
        }

        return ['jobs' => $selected, 'next' => $candidates['next']];
    }

    /** Executes a single job handler and updates state */
    private function executeJob(array $job, string $name): void
    {
        // Check all filters - all must return true to execute
        if (!array_all($job['filters'], fn($filter) => $filter())) return;

        try {
            $this->runHooks($job['before']);
            ($job['handler'])();
            $this->runHooks($job['success']);
            $this->recordJobSuccess($name, $job);
        } catch (Throwable $e) {
            $this->runHooks($job['error'], $e);
            $this->recordJobFailure($name, $e);
        } finally {
            $this->runHooks($job['after']);
        }
    }

    // DATABASE OPERATIONS ====================================================

    private function pdo(): PDO
    {
        $pdo = Container::get('db');
        if (!$pdo instanceof PDO) throw new RuntimeException('No database connection available.');
        return $pdo;
    }

    /** Ensures table exists and syncs current jobs to database */
    private function prepareDatabase(): void
    {
        $this->ensureTableExists();
        $this->syncJobsToDatabase();
    }

    private function ensureTableExists(): void
    {
        $this->pdo()->exec("
            CREATE TABLE IF NOT EXISTS jobs (
                name VARCHAR(255) NOT NULL PRIMARY KEY,
                last_run DATETIME NULL,
                lease_until DATETIME NULL,
                last_error TEXT NULL,
                fail_count INT NOT NULL DEFAULT 0,
                seen_at DATETIME NULL,
                priority INT NOT NULL DEFAULT 100,
                enqueued_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_jobs_lease (lease_until),
                KEY idx_jobs_seen (seen_at),
                KEY idx_jobs_priority (priority ASC, enqueued_at ASC)
            )
        ");
    }

    private function syncJobsToDatabase(): void
    {
        if ($this->jobs === []) return;

        $now = $this->nowAsDbString();
        $stmt = $this->pdo()->prepare("
            INSERT INTO jobs (name, seen_at, priority) VALUES (:name, :seen, :priority)
            ON DUPLICATE KEY UPDATE seen_at = VALUES(seen_at), priority = VALUES(priority)
        ");

        foreach ($this->jobs as $name => $job) {
            $stmt->execute([
                ':name' => $name,
                ':seen' => $now,
                ':priority' => $job['priority'],
            ]);
        }
    }

    private function fetchJobs(array $names): array
    {
        if ($names === []) return [];

        $placeholders = implode(',', array_fill(0, count($names), '?'));

        $stmt = $this->pdo()->prepare("
            SELECT * FROM jobs
            WHERE name IN ($placeholders)
            ORDER BY priority ASC, enqueued_at ASC, name ASC
        ");

        $stmt->execute(array_values($names));

        return $stmt->fetchAll();
    }

    private function fetchAllJobs(): array
    {
        $result = $this->pdo()->query("SELECT * FROM jobs ORDER BY name ASC");
        return $result ? $result->fetchAll() : [];
    }

    private function updateJobState(string $name, array $fields): void
    {
        if ($fields === []) return;

        $sets = implode(', ', array_map(fn($k) => "{$k} = :{$k}", array_keys($fields)));

        $params = array_combine(
            array_map(fn($k) => ":{$k}", array_keys($fields)),
            array_values($fields)
        );

        $this->pdo()
            ->prepare("UPDATE jobs SET {$sets} WHERE name = :name")
            ->execute([...$params, ':name' => $name]);
    }

    /**
     * Atomically acquire a lease for a job.
     *
     * Uses a conditional UPDATE to ensure only one worker can acquire the lease.
     * The WHERE clause checks that the lease is available (NULL or expired) before updating.
     *
     * @param string $name Job name
     * @param int $leaseSeconds Lease duration in seconds
     * @return bool True if lease was acquired, false if another worker got it
     */
    private function acquireLease(string $name, int $leaseSeconds): bool
    {
        $until = $this->clock->now()
            ->modify("+{$leaseSeconds} seconds")
            ->format(self::DB_DATETIME_FORMAT);

        $stmt = $this->pdo()->prepare("
            UPDATE jobs
               SET lease_until = :until
             WHERE name = :name
               AND (lease_until IS NULL OR lease_until < NOW())
        ");

        $stmt->execute([':name' => $name, ':until' => $until]);

        return $stmt->rowCount() === 1;
    }

    /** Executes callback within a database lock, returns null if lock not acquired */
    private function withDatabaseLock(string $name, callable $callback): mixed
    {
        $pdo = $this->pdo();
        $stmt = $pdo->prepare('SELECT GET_LOCK(:name, 0)');
        $stmt->execute([':name' => $name]);

        if (!$stmt->fetchColumn()) {
            $stmt->closeCursor();
            return null;
        }

        $stmt->closeCursor();

        try {
            return $callback();
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(:name)')->execute([':name' => $name]);
        }
    }

    // RENDERING ==============================================================

    private function renderJobTable(array $rows, DateTimeImmutable $now): void
    {
        $data = array_map(function (array $row) use ($now): array {

            $job = $this->jobs[$row['name']] ?? null;
            $leaseActive = ($row['lease_until'] ?? null) && new DateTimeImmutable($row['lease_until']) > $now;

            return [
                'name' => $row['name'],
                'queue' => $job['queue'] ?? '-',
                'priority' => $job['priority'] ?? '-',
                'cron' => $job['cron'] ?? '-',
                'last' => $this->formatRelativeTime($row['last_run'] ?? null, $now),
                'lease' => $leaseActive ? 'active' : '-',
                'fails' => $row['fail_count'] ?? 0,
                'seen' => $this->formatRelativeTime($row['seen_at'] ?? null, $now),
                'error' => $row['last_error'] ?? '-',
            ];
        }, $rows);

        Console::table([
            'name' => 'Name',
            'queue' => 'Queue',
            'priority' => 'Priority',
            'cron' => 'Cron',
            'last' => 'Last Run',
            'lease' => 'Leased',
            'fails' => 'Fails',
            'seen' => 'Seen',
            'error' => 'Error',
        ], $data);
    }

    private function formatRelativeTime(?string $timestamp, DateTimeImmutable $now): string
    {
        if (!$timestamp) return '-';

        $seconds = $now->getTimestamp() - (new DateTimeImmutable($timestamp))->getTimestamp();

        return match (true) {
            $seconds < 60 => "{$seconds}s",
            $seconds < 3600 => intdiv($seconds, 60) . 'm',
            $seconds < 86400 => intdiv($seconds, 3600) . 'h',
            default => intdiv($seconds, 86400) . 'd',
        };
    }

    // SLEEP & SIGNALS ========================================================

    private function sleepUntilNext(): void
    {
        if (!$this->nextWakeTime) {
            $this->nap(1.0);
            return;
        }

        $now = $this->clock->now();
        $seconds = $this->nextWakeTime->getTimestamp() - $now->getTimestamp();
        $micro = (int)$this->nextWakeTime->format('u') - (int)$now->format('u');
        $seconds += ($micro / 1_000_000) + (mt_rand(5, 20) / 1000);

        if ($seconds > 0) {
            $this->nap($seconds);
        }
    }

    private function nap(float $seconds): void
    {
        $remaining = $seconds;

        while ($this->running && $remaining > 0) {
            $slice = min($remaining, 0.5);
            usleep((int)round($slice * 1_000_000));
            $remaining -= $slice;
            $this->dispatchSignals();
        }
    }

    private function trapSignals(): callable
    {
        if (!function_exists('pcntl_signal')) return static fn() => null;

        $signals = array_filter([
            defined('SIGINT') ? SIGINT : null,
            defined('SIGTERM') ? SIGTERM : null,
        ]);

        if ($signals === []) return static fn() => null;

        $previous = [];

        foreach ($signals as $sig) {

            $previous[$sig] = function_exists('pcntl_signal_get_handler')
                ? pcntl_signal_get_handler($sig)
                : SIG_DFL;

            pcntl_signal($sig, fn() => (
                Console::info('Received ' . $this->signalName($sig) . ', shutting down worker...')
                || true
            ) && $this->stop());
        }

        return static function () use ($signals, $previous): void {
            foreach ($signals as $sig) {
                pcntl_signal($sig, $previous[$sig] ?? SIG_DFL);
            }
        };
    }

    private function dispatchSignals(): void
    {
        if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();
    }

    private function signalName(int $signal): string
    {
        return match ($signal) {
            SIGINT => 'SIGINT',
            SIGTERM => 'SIGTERM',
            default => (string)$signal,
        };
    }

    // HELPERS ================================================================

    private function modifyJob(callable $callback): self
    {
        if ($this->activeJobName !== null) $callback($this->jobs[$this->activeJobName]);
        return $this;
    }

    /** Adds a hook to a specific lifecycle stage */
    private function addHook(string $stage, callable $callback): self
    {
        return $this->modifyJob(fn(array &$job) => $job[$stage][] = $callback);
    }

    /** Executes an array of hooks/callbacks */
    private function runHooks(array $hooks, mixed ...$args): void
    {
        foreach ($hooks as $hook) $hook(...$args);
    }

    /** Records successful job execution */
    private function recordJobSuccess(string $name, array $job): void
    {
        $this->updateJobState($name, [
            'last_run' => $this->nowAsDbString(),
            'lease_until' => null,
            'last_error' => null,
            'enqueued_at' => null,
        ]);

        Console::success("{$name}: {$job['cron']}");
    }

    /** Records failed job execution */
    private function recordJobFailure(string $name, Throwable $e): void
    {
        $this->pdo()->prepare("
            UPDATE jobs
               SET last_run = :run, lease_until = NULL, last_error = :err, fail_count = fail_count + 1, enqueued_at = NULL
             WHERE name = :name
        ")->execute([
            ':run' => $this->nowAsDbString(),
            ':err' => $e->getMessage(),
            ':name' => $name,
        ]);

        Console::error("{$name}: " . $e->getMessage());
    }

    private function nowAsDbString(): string
    {
        return $this->clock->now()->format(self::DB_DATETIME_FORMAT);
    }

    private function earliestTime(?DateTimeImmutable $left, ?DateTimeImmutable $right): ?DateTimeImmutable
    {
        return match (true) {
            $left === null => $right,
            $right === null => $left,
            default => $left <= $right ? $left : $right,
        };
    }
}

// CLOCK - Time abstraction for testability ===================================

/** Clock interface for time operations */
interface ClockInterface
{
    public function now(): DateTimeImmutable;
}

/** System clock using real time (production) */
final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}

// CRON PARSER - Parses cron expressions into structured format ===============

/** Parses and validates cron expressions (5 or 6 fields) */
final readonly class CronParser
{
    /** Parses a cron expression into a structured array (always 6-field with seconds) */
    public static function parse(string $expression): array
    {
        $tokens = preg_split('/\s+/', trim($expression));

        if ($tokens === false || count($tokens) < 5 || count($tokens) > 6) {
            throw new RuntimeException("Invalid cron expression: {$expression}");
        }

        // Convert 5-field to 6-field by prepending '0' for seconds
        if (count($tokens) === 5) {
            [$min, $hour, $dom, $mon, $dow] = $tokens;
            $sec = '0';
        } else {
            [$sec, $min, $hour, $dom, $mon, $dow] = $tokens;
        }

        return [
            'sec'  => self::expandField($sec, 0, 59, false),
            'min'  => self::expandField($min, 0, 59, false),
            'hour' => self::expandField($hour, 0, 23, false),
            'dom'  => self::expandField($dom, 1, 31, false),
            'mon'  => self::expandField($mon, 1, 12, false),
            'dow'  => self::expandField($dow, 0, 6, true),
            'domStar' => ($dom === '*'),
            'dowStar' => ($dow === '*'),
        ];
    }

    /** Expands a cron field (e.g., star-slash-5, 1-3, 1,2,3) into a map and list */
    private static function expandField(string $field, int $min, int $max, bool $isDow): array
    {
        $field = trim($field);
        $field = $field === '' ? '*' : $field;
        $seen = [];
        $clamp = fn(int $v) => $isDow && $v === 7 ? 0 : max($min, min($max, $v));

        foreach (explode(',', $field) as $part) {
            $part = trim($part);
            if ($part === '') continue;

            [$range, $step] = str_contains($part, '/') ? explode('/', $part, 2) : [$part, '1'];
            $step = max(1, (int)$step);

            if ($range === '*') {
                for ($v = $min; $v <= $max; $v += $step) {
                    $seen[$v] = true;
                }
            } elseif (str_contains($range, '-')) {
                [$start, $end] = array_map('intval', explode('-', $range, 2));
                [$start, $end] = [$clamp($start), $clamp($end)];
                if ($start > $end) [$start, $end] = [$end, $start];
                for ($v = $start; $v <= $end; $v += $step) {
                    $seen[$v] = true;
                }
            } else {
                $seen[$clamp((int)$range)] = true;
            }
        }

        $values = $seen !== [] ? array_keys($seen) : range($min, $max);
        sort($values, SORT_NUMERIC);

        return ['map' => array_fill_keys($values, true), 'list' => $values];
    }
}

// CRON EVALUATOR - Evaluates if cron expressions match timestamps ============

/** Evaluates cron expressions and calculates next run times */
final readonly class CronEvaluator
{
    /** Determines if a job is due and calculates next run time */
    public static function evaluate(array $parsed, DateTimeImmutable $now, ?DateTimeImmutable $lastRun): array
    {
        $matches = self::matches($parsed, $now);
        $due = false;

        if ($matches) {
            // Always compare with second precision (6-field cron)
            $due = !$lastRun || $lastRun->format('Y-m-d H:i:s') !== $now->format('Y-m-d H:i:s');
        }

        return ['next' => self::nextMatch($parsed, $now), 'due' => $due];
    }

    /** Checks if a moment matches a cron expression */
    public static function matches(array $parsed, DateTimeImmutable $moment): bool
    {
        $second = (int)$moment->format('s');
        $minute = (int)$moment->format('i');
        $hour = (int)$moment->format('G');
        $dom = (int)$moment->format('j');
        $mon = (int)$moment->format('n');
        $dow = (int)$moment->format('w');

        if (!isset($parsed['sec']['map'][$second], $parsed['min']['map'][$minute], $parsed['hour']['map'][$hour], $parsed['mon']['map'][$mon])) {
            return false;
        }

        $domMatch = isset($parsed['dom']['map'][$dom]);
        $dowMatch = isset($parsed['dow']['map'][$dow]);

        return match (true) {
            $parsed['domStar'] && $parsed['dowStar'] => true,
            $parsed['domStar'] => $dowMatch,
            $parsed['dowStar'] => $domMatch,
            default => $domMatch || $dowMatch,
        };
    }

    /** Calculates the next time a cron expression will match using incremental algorithm */
    public static function nextMatch(array $parsed, DateTimeImmutable $from): DateTimeImmutable
    {
        $current = $from->modify('+1 second');
        $maxYear = (int)$current->format('Y') + 5;

        $dayMatches = fn(int $d, DateTimeImmutable $date) => match (true) {
            $parsed['domStar'] && $parsed['dowStar'] => true,
            $parsed['domStar'] => isset($parsed['dow']['map'][(int)$date->format('w')]),
            $parsed['dowStar'] => isset($parsed['dom']['map'][$d]),
            default => isset($parsed['dom']['map'][$d]) || isset($parsed['dow']['map'][(int)$date->format('w')]),
        };

        $resetTime = fn(DateTimeImmutable $dt) => $dt->setTime(
            $parsed['hour']['list'][0],
            $parsed['min']['list'][0],
            $parsed['sec']['list'][0]
        );

        for ($attempts = 0; $attempts < 10000; $attempts++) {
            [$year, $month, $day, $hour, $minute, $second] = [
                (int)$current->format('Y'),
                (int)$current->format('n'),
                (int)$current->format('j'),
                (int)$current->format('G'),
                (int)$current->format('i'),
                (int)$current->format('s'),
            ];

            if ($year > $maxYear) {
                throw new RuntimeException('Unable to compute next cron match within 5 years.');
            }

            // Check month
            if (!isset($parsed['mon']['map'][$month])) {
                $next = self::nextIn($parsed['mon']['list'], $month);
                $current = $resetTime($next
                    ? $current->setDate($year, $next, 1)
                    : $current->setDate($year + 1, $parsed['mon']['list'][0], 1));
                continue;
            }

            // Check day (considering both DOM and DOW)
            if (!$dayMatches($day, $current)) {
                $found = false;
                $daysInMonth = (int)$current->format('t');

                for ($d = $day + 1; $d <= $daysInMonth; $d++) {
                    $testDate = $current->setDate($year, $month, $d);
                    if ($dayMatches($d, $testDate)) {
                        $current = $resetTime($testDate);
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    $next = self::nextIn($parsed['mon']['list'], $month + 1);
                    $current = $resetTime($next
                        ? $current->setDate($year, $next, 1)
                        : $current->setDate($year + 1, $parsed['mon']['list'][0], 1));
                }

                continue;
            }

            // Check hour
            if (!isset($parsed['hour']['map'][$hour])) {
                $next = self::nextIn($parsed['hour']['list'], $hour);
                $current = $next
                    ? $current->setTime($next, $parsed['min']['list'][0], $parsed['sec']['list'][0])
                    : $current->modify('+1 day')->setTime(
                        $parsed['hour']['list'][0],
                        $parsed['min']['list'][0],
                        $parsed['sec']['list'][0]
                    );
                continue;
            }

            // Check minute
            if (!isset($parsed['min']['map'][$minute])) {
                $next = self::nextIn($parsed['min']['list'], $minute);
                if ($next) {
                    $current = $current->setTime($hour, $next, $parsed['sec']['list'][0]);
                } else {
                    $current = $current->modify('+1 hour');
                    $current = $current->setTime(
                        (int)$current->format('G'),
                        $parsed['min']['list'][0],
                        $parsed['sec']['list'][0]
                    );
                }
                continue;
            }

            // Check second
            if (!isset($parsed['sec']['map'][$second])) {
                $next = self::nextIn($parsed['sec']['list'], $second);
                if ($next) {
                    $current = $current->setTime($hour, $minute, $next);
                } else {
                    $current = $current->modify('+1 minute');
                    $current = $current->setTime(
                        (int)$current->format('G'),
                        (int)$current->format('i'),
                        $parsed['sec']['list'][0]
                    );
                }
                continue;
            }

            return $current;
        }

        throw new RuntimeException('Next cron match calculation exceeded maximum iterations.');
    }

    /** Binary search for next valid value in sorted list - O(log n) */
    private static function nextIn(array $list, int $val): ?int
    {
        $left = 0;
        $right = count($list) - 1;

        while ($left <= $right) {

            $mid = ($left + $right) >> 1;

            if ($list[$mid] < $val) {
                $left = $mid + 1;
            } else {
                $right = $mid - 1;
            }
        }

        return $list[$left] ?? null;
    }
}
