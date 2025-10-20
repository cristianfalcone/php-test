<?php

declare(strict_types=1);

namespace Ajo\Core;

use Ajo\Core\Console as CoreConsole;
use Ajo\Console;
use Ajo\Container;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class Job
{
    private array $jobs = [];
    private ?int $active = null;
    private bool $running = false;
    private ?DateTimeImmutable $nextWake = null;

    // PUBLIC API ============================================================

    /** Registers job commands in the CLI */
    public function register(CoreConsole $cli)
    {
        $cli->command('jobs:install', fn() => $this->install())->describe('Initializes the jobs state table.');
        $cli->command('jobs:status', fn() => $this->status())->describe('Shows the current status of jobs.');
        $cli->command('jobs:collect', function () {
            $executed = $this->run();
            $executed === 0 ? Console::info('No due jobs.') : Console::success("Executed {$executed} job(s).");
            return 0;
        })->describe('Executes due jobs.');
        $cli->command('jobs:work', fn() => $this->forever() ?? 0)->describe('Runs jobs in the background.');
        $cli->command('jobs:prune', fn() => $this->prune())->describe('Removes states of jobs not seen recently.');

        return $this;
    }

    /** Registers a scheduled job */
    public function schedule(string $cron, callable $handler)
    {
        $cron = trim($cron);

        $this->jobs[] = [
            'name'        => $this->hash($cron, null),
            'cron'        => $cron,
            'queue'       => 'default',
            'concurrency' => 1,
            'priority'    => 100,
            'lease'       => 3600,
            'handler'     => $handler,
            'parsed'      => $this->parse($cron),
            'hash'        => null,
            'custom'      => false,
        ];

        $this->active = array_key_last($this->jobs);

        return $this;
    }

    /** Sets a unique key for the active job */
    public function key(string $key)
    {
        return $this->tap(function (array &$job) use ($key) {

            $job['hash'] = trim($key);

            if (!$job['custom']) $job['name'] = $this->hash($job['cron'], $job['hash']);
        });
    }

    /** Sets an explicit name for the active job */
    public function name(string $name)
    {
        return $this->tap(function (array &$job) use ($name) {
            $job['name'] = $name;
            $job['custom'] = true;
        });
    }

    /** Sets the queue for the active job */
    public function queue(?string $name)
    {
        return $this->tap(fn(array &$job) => $job['queue'] = $name ?: 'default');
    }

    /** Sets concurrency limit for the active job */
    public function concurrency(int $n)
    {
        return $this->tap(fn(array &$job) => $job['concurrency'] = max(1, $n));
    }

    /** Sets execution priority for the active job */
    public function priority(int $n)
    {
        return $this->tap(fn(array &$job) => $job['priority'] = $n);
    }

    /** Sets lease duration in seconds for the active job */
    public function lease(int $seconds)
    {
        return $this->tap(fn(array &$job) => $job['lease'] = max(60, $seconds));
    }

    /** Stops the background worker */
    public function stop()
    {
        $this->running = false;
    }

    // EXECUTION =============================================================

    /** Executes all due jobs once */
    public function run()
    {
        $this->ensure();
        $this->sync();
        $this->nextWake = null;

        $result = $this->withLock('jobs:collect', function () {

            $jobs = array_column($this->jobs, null, 'name');

            if ($jobs === []) return ['selected' => [], 'next' => null];

            $now = new DateTimeImmutable('now');
            $rows = $this->fetch(array_keys($jobs));

            // Build pool of due jobs and track queue concurrency
            $pool = [];
            $limit = [];
            $busy = [];
            $next = null;

            foreach ($rows as $row) {

                $job = $jobs[$row['name']] ?? null;

                if (!$job) continue;

                $queue = $job['queue'];
                $limit[$queue] = max($limit[$queue] ?? 1, $job['concurrency']);

                // Skip if lease is still active
                $leaseUntil = ($row['lease_until'] ?? null) ? new DateTimeImmutable($row['lease_until']) : null;

                if ($leaseUntil && $leaseUntil > $now) {
                    $busy[$queue] = ($busy[$queue] ?? 0) + 1;
                    $next = $this->earliest($next, $leaseUntil);
                    continue;
                }

                // Check if job is due
                $lastRun = ($row['last_run'] ?? null) ? new DateTimeImmutable($row['last_run']) : null;
                $status = $this->evaluate($job['parsed'], $now, $lastRun);
                $next = $this->earliest($next, $status['next']);

                if ($status['due']) $pool[] = ['row' => $row, 'job' => $job, 'queue' => $queue];
            }

            if ($pool === []) return ['selected' => [], 'next' => $next];

            // Sort by priority, then by name
            usort($pool, static fn($a, $b) =>
                ($a['job']['priority'] <=> $b['job']['priority']) ?: strcmp($a['row']['name'], $b['row']['name'])
            );

            // Select jobs respecting concurrency limits per queue
            $selected = [];

            foreach ($pool as $candidate) {

                $queue = $candidate['queue'];

                // Skip if queue is at capacity
                if (($busy[$queue] ?? 0) >= ($limit[$queue] ?? 1)) {
                    $next = $this->earliest($next, $now);
                    continue;
                }

                // Acquire lease
                $leaseUntil = $now->modify('+' . $candidate['job']['lease'] . ' seconds')->format('Y-m-d H:i:s');
                $this->update($candidate['row']['name'], ['lease_until' => $leaseUntil]);

                $selected[] = $candidate;
                $busy[$queue] = ($busy[$queue] ?? 0) + 1;
            }

            return ['selected' => $selected, 'next' => $next];
        });

        if ($result === null) return 0;

        $this->nextWake = $result['next'];

        foreach ($result['selected'] as $entry) {
            $this->execute($entry['job'], $entry['row']['name']);
        }

        return count($result['selected']);
    }

    /** Runs jobs continuously in the background */
    public function forever()
    {
        Console::info('Job execution started...');

        $restore = $this->trapSignals();
        $this->running = true;

        try {
            while ($this->running) {

                $this->dispatchSignals();

                if (!$this->running) break;

                $executed = $this->run();

                $this->dispatchSignals();

                if (!$this->running) break;

                if ($executed === 0) {
                    $this->sleepUntilNext();
                }
            }
        } finally {
            $this->running = false;
            $restore();
            Console::info('Job worker stopped.');
        }
    }

    /** Executes a single job handler and updates state */
    private function execute(array $job, string $name)
    {
        try {
            ($job['handler'])();

            $stamp = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
            $this->update($name, ['last_run' => $stamp, 'lease_until' => null, 'last_error' => null]);

            Console::success("{$name}: {$job['cron']}");
        } catch (Throwable $e) {

            $stamp = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

            $this->pdo()->prepare("
                UPDATE jobs
                   SET last_run = :run, lease_until = NULL, last_error = :err, fail_count = fail_count + 1
                 WHERE name = :name
            ")->execute([':run' => $stamp, ':err' => $e->getMessage(), ':name' => $name]);

            Console::error("{$name}: " . $e->getMessage());
        }
    }

    // COMMANDS ==============================================================

    /** Initializes the jobs table */
    private function install()
    {
        $this->ensure();
        $this->sync();
        Console::success('Table ready.');
        return 0;
    }

    /** Shows current status of all jobs */
    private function status()
    {
        $this->ensure();

        $rows = $this->fetchAll();

        if ($rows === []) {
            Console::log('Defined: 0 | Running: 0 | Idle: 0');
            Console::blank();
            Console::log('No registered jobs.');
            return 0;
        }

        $jobs = array_column($this->jobs, null, 'name');
        $now = new DateTimeImmutable('now');

        $active = array_reduce($rows, fn(int $c, array $r): int =>
            $c + (($r['lease_until'] ?? null) && new DateTimeImmutable($r['lease_until']) > $now ? 1 : 0), 0
        );

        Console::log(sprintf('Defined: %d | Running: %d | Idle: %d', count($rows), $active, max(0, count($rows) - $active)));
        Console::blank();

        $this->render($rows, $jobs, $now);

        return 0;
    }

    /** Removes stale job states from database */
    private function prune(int $days = 30)
    {
        $this->ensure();

        $result = $this->withLock('jobs:prune', function () use ($days) {
            $cutoff = (new DateTimeImmutable('now'))->modify('-' . max(0, $days) . ' days')->format('Y-m-d H:i:s');
            $stmt = $this->pdo()->prepare("
                DELETE FROM jobs
                 WHERE (seen_at IS NULL OR seen_at < :cutoff) AND (lease_until IS NULL OR lease_until < NOW())
            ");
            $stmt->execute([':cutoff' => $cutoff]);

            Console::success("Pruned {$stmt->rowCount()} job state(s).");

            return true;
        });

        if ($result === null) {
            Console::info('Prune skipped: another process holds the lock.');
        }

        return 0;
    }

    // CRON EVALUATION =======================================================

    /** Determines if a job is due and calculates next run time */
    private function evaluate(array $parsed, DateTimeImmutable $now, ?DateTimeImmutable $lastRun)
    {
        $matches = $this->matches($parsed, $now);
        $due = false;

        if ($matches) {
            if ($parsed['hasSeconds']) {
                $due = !$lastRun || $lastRun->format('Y-m-d H:i:s') !== $now->format('Y-m-d H:i:s');
            } else {
                $current = $now->setTime((int)$now->format('H'), (int)$now->format('i'), 0);
                $previous = $lastRun ? $lastRun->setTime((int)$lastRun->format('H'), (int)$lastRun->format('i'), 0) : null;
                $due = !$previous || $previous->format('Y-m-d H:i:s') !== $current->format('Y-m-d H:i:s');
            }
        }

        return ['next' => $this->nextMatch($parsed, $now), 'due' => $due];
    }

    /** Checks if a moment matches a cron expression */
    private function matches(array $parsed, DateTimeImmutable $moment)
    {
        $minute = (int)$moment->format('i');
        $hour = (int)$moment->format('G');
        $dom = (int)$moment->format('j');
        $mon = (int)$moment->format('n');
        $dow = (int)$moment->format('w');

        if (!isset($parsed['min']['map'][$minute], $parsed['hour']['map'][$hour], $parsed['mon']['map'][$mon])) {
            return false;
        }

        if ($parsed['hasSeconds'] && !isset($parsed['sec']['map'][(int)$moment->format('s')])) {
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

    /** Parses a cron expression into an array structure */
    private function parse(string $expression)
    {
        $tokens = preg_split('/\s+/', trim($expression));

        if ($tokens === false || count($tokens) < 5 || count($tokens) > 6) {
            throw new RuntimeException("Invalid cron expression: {$expression}");
        }

        // Expands a cron field (e.g., "*/5", "1-3", "1,2,3") into a map and list
        $expand = function (string $field, int $min, int $max, bool $isDow) {

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
                    // Use array keys for O(1) deduplication
                    for ($v = $min; $v <= $max; $v += $step) $seen[$v] = true;
                } elseif (str_contains($range, '-')) {
                    [$start, $end] = array_map('intval', explode('-', $range, 2));
                    [$start, $end] = [$clamp($start), $clamp($end)];
                    if ($start > $end) [$start, $end] = [$end, $start];
                    for ($v = $start; $v <= $end; $v += $step) $seen[$v] = true;
                } else {
                    $seen[$clamp((int)$range)] = true;
                }
            }

            $values = $seen !== [] ? array_keys($seen) : range($min, $max);

            sort($values, SORT_NUMERIC);

            return ['map' => array_fill_keys($values, true), 'list' => $values];
        };

        // Parse 5-field (minute precision) or 6-field (second precision)
        if (count($tokens) === 5) {
            [$min, $hour, $dom, $mon, $dow] = $tokens;
            [$sec, $hasSeconds] = ['0', false];
        } else {
            [$sec, $min, $hour, $dom, $mon, $dow] = $tokens;
            $hasSeconds = true;
        }

        return [
            'hasSeconds' => $hasSeconds,
            'sec' => $expand($sec, 0, 59, false),
            'min' => $expand($min, 0, 59, false),
            'hour' => $expand($hour, 0, 23, false),
            'dom' => $expand($dom, 1, 31, false),
            'mon' => $expand($mon, 1, 12, false),
            'dow' => $expand($dow, 0, 6, true),
            'domStar' => ($dom === '*'),
            'dowStar' => ($dow === '*'),
        ];
    }

    /** Calculates the next time a cron expression will match using incremental field-by-field algorithm */
    private function nextMatch(array $parsed, DateTimeImmutable $from)
    {
        // Start from next second/minute
        $current = $parsed['hasSeconds'] ? $from->modify('+1 second') : $from->modify('+1 minute');

        if (!$parsed['hasSeconds']) {
            $current = $current->setTime((int)$current->format('H'), (int)$current->format('i'), 0);
        }

        $maxYear = (int)$current->format('Y') + 5;

        // Binary search for next valid value in sorted list - O(log n) instead of O(n)
        $nextIn = static function (array $list, int $val): ?int {
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
        };

        // Check if day matches both day-of-month and day-of-week constraints
        $dayMatches = fn(int $d, DateTimeImmutable $date) => match (true) {
            $parsed['domStar'] && $parsed['dowStar'] => true,
            $parsed['domStar'] => isset($parsed['dow']['map'][(int)$date->format('w')]),
            $parsed['dowStar'] => isset($parsed['dom']['map'][$d]),
            default => isset($parsed['dom']['map'][$d]) || isset($parsed['dow']['map'][(int)$date->format('w')]),
        };

        // Incremental field-by-field search - adjust current until all fields match
        for ($attempts = 0; $attempts < 10000; $attempts++) {

            [$year, $month, $day, $hour, $minute, $second] = [
                (int)$current->format('Y'), (int)$current->format('n'), (int)$current->format('j'),
                (int)$current->format('G'), (int)$current->format('i'), (int)$current->format('s'),
            ];

            if ($year > $maxYear) {
                throw new RuntimeException('Unable to compute next cron match within 5 years.');
            }

            // Check month
            if (!isset($parsed['mon']['map'][$month])) {
                $nextMonth = $nextIn($parsed['mon']['list'], $month);
                $current = $nextMonth
                    ? $current->setDate($year, $nextMonth, 1)
                    : $current->setDate($year + 1, $parsed['mon']['list'][0], 1);
                $current = $current->setTime($parsed['hour']['list'][0], $parsed['min']['list'][0], $parsed['hasSeconds'] ? $parsed['sec']['list'][0] : 0);
                continue;
            }

            // Check day (considering both DOM and DOW)
            if (!$dayMatches($day, $current)) {
                $found = false;
                $daysInMonth = (int)$current->format('t');
                for ($d = $day + 1; $d <= $daysInMonth; $d++) {
                    $testDate = $current->setDate($year, $month, $d);
                    if ($dayMatches($d, $testDate)) {
                        $current = $testDate->setTime($parsed['hour']['list'][0], $parsed['min']['list'][0], $parsed['hasSeconds'] ? $parsed['sec']['list'][0] : 0);
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    // No valid day this month, advance to next month
                    $nextMonth = $nextIn($parsed['mon']['list'], $month + 1);
                    $current = $nextMonth
                        ? $current->setDate($year, $nextMonth, 1)
                        : $current->setDate($year + 1, $parsed['mon']['list'][0], 1);
                    $current = $current->setTime($parsed['hour']['list'][0], $parsed['min']['list'][0], $parsed['hasSeconds'] ? $parsed['sec']['list'][0] : 0);
                }
                continue;
            }

            // Check hour
            if (!isset($parsed['hour']['map'][$hour])) {
                $nextHour = $nextIn($parsed['hour']['list'], $hour);
                if ($nextHour) {
                    $current = $current->setTime($nextHour, $parsed['min']['list'][0], $parsed['hasSeconds'] ? $parsed['sec']['list'][0] : 0);
                } else {
                    $current = $current->modify('+1 day')->setTime($parsed['hour']['list'][0], $parsed['min']['list'][0], $parsed['hasSeconds'] ? $parsed['sec']['list'][0] : 0);
                }
                continue;
            }

            // Check minute
            if (!isset($parsed['min']['map'][$minute])) {
                $nextMinute = $nextIn($parsed['min']['list'], $minute);
                if ($nextMinute) {
                    $current = $current->setTime($hour, $nextMinute, $parsed['hasSeconds'] ? $parsed['sec']['list'][0] : 0);
                } else {
                    $current = $current->modify('+1 hour')->setTime((int)$current->format('G'), $parsed['min']['list'][0], $parsed['hasSeconds'] ? $parsed['sec']['list'][0] : 0);
                }
                continue;
            }

            // Check second (if applicable)
            if ($parsed['hasSeconds'] && !isset($parsed['sec']['map'][$second])) {
                $nextSecond = $nextIn($parsed['sec']['list'], $second);
                if ($nextSecond) {
                    $current = $current->setTime($hour, $minute, $nextSecond);
                } else {
                    $current = $current->modify('+1 minute');
                }
                continue;
            }

            // All fields match!
            return $current;
        }

        throw new RuntimeException('Next cron match calculation exceeded maximum iterations.');
    }

    // DATABASE ==============================================================

    /** Returns the PDO connection from the container */
    private function pdo()
    {
        $pdo = Container::get('db');
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('No database connection available.');
        }
        return $pdo;
    }

    /** Creates the jobs table if it doesn't exist */
    private function ensure()
    {
        $this->pdo()->exec("
            CREATE TABLE IF NOT EXISTS jobs (
                name VARCHAR(255) NOT NULL PRIMARY KEY,
                last_run DATETIME NULL,
                lease_until DATETIME NULL,
                last_error TEXT NULL,
                fail_count INT NOT NULL DEFAULT 0,
                seen_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_jobs_lease (lease_until),
                KEY idx_jobs_seen (seen_at)
            )
        ");
    }

    /** Syncs registered jobs with the database */
    private function sync()
    {
        if ($this->jobs === []) return;

        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $stmt = $this->pdo()->prepare("
            INSERT INTO jobs (name, seen_at) VALUES (:name, :seen)
            ON DUPLICATE KEY UPDATE seen_at = VALUES(seen_at)
        ");

        foreach ($this->jobs as $job) {
            $stmt->execute([':name' => $job['name'], ':seen' => $now]);
        }
    }

    /** Fetches job rows by name */
    private function fetch(array $names)
    {
        if ($names === []) return [];

        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $stmt = $this->pdo()->prepare("SELECT * FROM jobs WHERE name IN ($placeholders)");
        $stmt->execute(array_values($names));

        return $stmt->fetchAll();
    }

    /** Fetches all job rows */
    private function fetchAll()
    {
        $stmt = $this->pdo()->query("SELECT * FROM jobs ORDER BY name ASC");
        return $stmt ? $stmt->fetchAll() : [];
    }

    /** Updates job row fields */
    private function update(string $name, array $fields)
    {
        if ($fields === []) return;

        $sets = implode(', ', array_map(fn($k) => "{$k} = :{$k}", array_keys($fields)));
        $params = array_combine(array_map(fn($k) => ":{$k}", array_keys($fields)), array_values($fields));
        $this->pdo()->prepare("UPDATE jobs SET {$sets} WHERE name = :name")->execute([...$params, ':name' => $name]);
    }

    /** Executes callback within a database lock */
    private function withLock(string $name, callable $callback)
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

    // OUTPUT ================================================================

    /** Renders job status as a table */
    private function render(array $rows, array $jobs, DateTimeImmutable $now)
    {
        $nowTs = $now->getTimestamp();

        // Format relative time - cached timestamp for performance
        $since = static fn(?string $t, int $nowTs) => !$t ? '-' : match (true) {
            ($d = $nowTs - (new DateTimeImmutable($t))->getTimestamp()) < 60 => $d . 's',
            $d < 3600 => intdiv($d, 60) . 'm',
            $d < 86400 => intdiv($d, 3600) . 'h',
            default => intdiv($d, 86400) . 'd',
        };

        $data = array_map(function (array $row) use ($jobs, $now, $nowTs, $since): array {
            $job = $jobs[$row['name']] ?? null;
            $leaseActive = ($row['lease_until'] ?? null) && new DateTimeImmutable($row['lease_until']) > $now;

            return [
                'name' => $row['name'],
                'queue' => $job['queue'] ?? '-',
                'priority' => $job['priority'] ?? '-',
                'cron' => $job['cron'] ?? '-',
                'last' => $since($row['last_run'] ?? null, $nowTs),
                'lease' => $leaseActive ? 'active' : '-',
                'fails' => $row['fail_count'] ?? 0,
                'seen' => $since($row['seen_at'] ?? null, $nowTs),
                'error' => $row['last_error'] ?? '-',
            ];
        }, $rows);

        Console::table([
            'name' => 'Name', 'queue' => 'Queue', 'priority' => 'Priority', 'cron' => 'Cron',
            'last' => 'Last Run', 'lease' => 'Leased', 'fails' => 'Fails', 'seen' => 'Seen', 'error' => 'Error',
        ], $data);
    }

    // SLEEP & SIGNALS =======================================================

    /** Sleeps until the next scheduled job or a default interval */
    private function sleepUntilNext()
    {
        if (!$this->nextWake) {
            $this->nap(1.0);
            return;
        }

        $now = new DateTimeImmutable('now');
        $seconds = $this->nextWake->getTimestamp() - $now->getTimestamp();
        $micro = (int)$this->nextWake->format('u') - (int)$now->format('u');
        $seconds += ($micro / 1_000_000) + (mt_rand(5, 20) / 1000);

        if ($seconds > 0) {
            $this->nap($seconds);
        }
    }

    /** Sleeps in small intervals while checking for stop signal */
    private function nap(float $seconds)
    {
        $remaining = $seconds;

        while ($this->running && $remaining > 0) {
            $slice = min($remaining, 0.5);
            usleep((int)round($slice * 1_000_000));
            $remaining -= $slice;
            $this->dispatchSignals();
        }
    }

    /** Sets up signal handlers for graceful shutdown */
    private function trapSignals()
    {
        if (!function_exists('pcntl_signal')) {
            return static fn() => null;
        }

        $signals = array_filter([defined('SIGINT') ? SIGINT : null, defined('SIGTERM') ? SIGTERM : null]);

        if ($signals === []) {
            return static fn() => null;
        }

        $previous = [];

        foreach ($signals as $sig) {
            $previous[$sig] = function_exists('pcntl_signal_get_handler') ? pcntl_signal_get_handler($sig) : SIG_DFL;
            pcntl_signal($sig, fn() => (Console::info('Received ' . $this->signalName($sig) . ', shutting down worker...') || true) && $this->stop());
        }

        return static function () use ($signals, $previous): void {
            foreach ($signals as $sig) {
                pcntl_signal($sig, $previous[$sig] ?? SIG_DFL);
            }
        };
    }

    /** Dispatches pending signals */
    private function dispatchSignals()
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }

    /** Returns a human-readable signal name */
    private function signalName(int $signal)
    {
        return match ($signal) {
            SIGINT => 'SIGINT',
            SIGTERM => 'SIGTERM',
            default => (string)$signal,
        };
    }

    // HELPERS ===============================================================

    /** Applies callback to the active job */
    private function tap(callable $callback)
    {
        if ($this->active !== null) {
            $callback($this->jobs[$this->active]);
        }

        return $this;
    }

    /** Generates a hash-based name for a job */
    private function hash(string $cron, ?string $key)
    {
        return substr(sha1($cron . '|' . ($key ?? '')), 0, 12);
    }

    /** Returns the earliest of two timestamps */
    private function earliest(?DateTimeImmutable $left, ?DateTimeImmutable $right)
    {
        if ($left === null) return $right;
        if ($right === null) return $left;

        return $left <= $right ? $left : $right;
    }
}
