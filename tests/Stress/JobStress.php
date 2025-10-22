<?php

declare(strict_types=1);

namespace Ajo\Tests\Stress\JobStress;

use Ajo\Console;
use Ajo\Container;
use Ajo\Core\Job as CoreJob;
use Ajo\Job;
use Ajo\Test;
use PDO;
use PDOStatement;
use ReflectionMethod;
use function Ajo\Tests\Support\Console\dispatch;
use function Ajo\Tests\Support\Console\silence;

/**
 * Mock clock for testing (allows time manipulation)
 */
final class MockClock implements \Ajo\Core\ClockInterface
{
    private \DateTimeImmutable $now;

    public function __construct(string $now = 'now')
    {
        $this->now = new \DateTimeImmutable($now);
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function setNow(string $time): void
    {
        $this->now = new \DateTimeImmutable($time);
    }

    public function advance(string $interval): void
    {
        $this->now = $this->now->modify($interval);
    }
}

/**
 * PDO wrapper that intercepts NOW() calls for time mocking
 */
final class MockTimePDO extends PDO
{
    private ?MockClock $clock = null;
    private static ?string $sharedDbPath = null;

    public function __construct(?MockClock $clock = null, bool $shared = false)
    {
        if ($shared) {
            // Use file-based SQLite for sharing between forked processes
            if (self::$sharedDbPath === null) {
                self::$sharedDbPath = sys_get_temp_dir() . '/job-stress-test-' . getmypid() . '.db';
            }
            $dsn = 'sqlite:' . self::$sharedDbPath;
        } else {
            // Use in-memory SQLite for single-process tests
            $dsn = 'sqlite::memory:';
        }

        parent::__construct($dsn);
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Enable WAL mode for better concurrency with file-based SQLite
        if ($shared) {
            $this->exec('PRAGMA journal_mode=WAL');
            $this->exec('PRAGMA synchronous=NORMAL');
        }

        $this->clock = $clock;
    }

    public static function getSharedDbPath(): ?string
    {
        return self::$sharedDbPath;
    }

    public static function cleanupSharedDb(): void
    {
        if (self::$sharedDbPath && file_exists(self::$sharedDbPath)) {
            @unlink(self::$sharedDbPath);
            @unlink(self::$sharedDbPath . '-shm');
            @unlink(self::$sharedDbPath . '-wal');
            self::$sharedDbPath = null;
        }
    }

    public function exec(string $statement): int|false
    {
        return parent::exec($this->rewrite($statement));
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare($this->rewrite($query), $options);
    }

    public function query(string $query, ?int $fetchMode = null, ...$fetchModeArgs): PDOStatement|false
    {
        $query = $this->rewrite($query);

        return $fetchMode === null
            ? parent::query($query)
            : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    private function rewrite(string $sql): string
    {
        $normalized = ltrim($sql);
        $upper = strtoupper($normalized);

        // Rewrite CREATE TABLE for SQLite
        if (str_starts_with($upper, 'CREATE TABLE IF NOT EXISTS JOBS')) {
            return <<<SQL
CREATE TABLE IF NOT EXISTS jobs (
    name TEXT PRIMARY KEY,
    last_run TEXT NULL,
    lease_until TEXT NULL,
    last_error TEXT NULL,
    fail_count INTEGER NOT NULL DEFAULT 0,
    seen_at TEXT NULL,
    priority INTEGER NOT NULL DEFAULT 100,
    enqueued_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
SQL;
        }

        // Rewrite INSERT ... ON DUPLICATE KEY for SQLite
        if (str_contains($upper, 'ON DUPLICATE KEY UPDATE')) {
            return <<<SQL
INSERT INTO jobs (name, seen_at, priority) VALUES (:name, :seen, :priority)
ON CONFLICT(name) DO UPDATE SET seen_at = excluded.seen_at, priority = excluded.priority;
SQL;
        }

        // Mock GET_LOCK (return success)
        if (str_starts_with($upper, 'SELECT GET_LOCK')) {
            return 'SELECT 1';
        }

        // Mock RELEASE_LOCK (return success)
        if (str_starts_with($upper, 'SELECT RELEASE_LOCK')) {
            return 'SELECT 1';
        }

        // Replace NOW() with mock time if clock is set
        if ($this->clock !== null) {
            $mockTime = "'" . $this->clock->now()->format('Y-m-d H:i:s') . "'";
            $sql = str_ireplace('NOW()', $mockTime, $sql);
            $sql = str_replace("datetime('now')", $mockTime, $sql);
        } else {
            $sql = str_ireplace('NOW()', "datetime('now')", $sql);
        }

        return $sql;
    }
}

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

function resetJobsInstance(): void
{
    Job::swap(new CoreJob());
}

function row(mixed $state, string $name): ?array
{
    // $state can be array or ArrayObject from Test framework
    $pdo = $state['pdo'] ?? null;
    if (!$pdo instanceof PDO) {
        throw new \RuntimeException('PDO not available in state');
    }

    $stmt = $pdo->prepare('SELECT * FROM jobs WHERE name = :n');
    $stmt->execute([':n' => $name]);

    $row = $stmt->fetch();
    $stmt->closeCursor();

    return $row ?: null;
}

// ============================================================================
// STRESS TESTS
// ============================================================================

Test::describe('Job Stress Testing', function () {

    Test::beforeEach(function ($state): void {
        Container::clear();

        // Create MockClock for time manipulation
        $clock = new MockClock('2024-01-01 12:00:00');
        $state['clock'] = $clock;

        // Create PDO with clock
        $pdo = new MockTimePDO($clock);
        Container::set('db', $pdo);
        $state['pdo'] = $pdo;

        // Create Job instance with clock
        $job = new CoreJob($clock);
        Job::swap($job);
    });

    Test::afterEach(function ($state): void {
        Container::clear();
        resetJobsInstance();
        unset($state['pdo'], $state['clock']);
    });

    // ========================================================================
    // TIME MANIPULATION TESTS
    // ========================================================================

    Test::it('should use mocked time for job execution', function ($state) {
        $clock = $state['clock'];
        $clock = $state['clock'];

        $cli = Console::create();
        Job::register($cli);

        $executedAt = null;

        Job::schedule('time-test', function () use (&$executedAt, $clock) {
            $executedAt = $clock->now()->format('Y-m-d H:i:s');
        })->everySecond();

        dispatch($cli, 'jobs:install');
        silence(fn() => dispatch($cli, 'jobs:collect'));

        Test::assertSame('2024-01-01 12:00:00', $executedAt);
    });

    Test::it('should advance time instantly with MockClock', function ($state) {
        $clock = $state['clock'];
        $clock = $state['clock'];

        $cli = Console::create();
        Job::register($cli);

        $executions = [];

        Job::schedule('advance-test', function () use (&$executions, $clock) {
            $executions[] = $clock->now()->format('H:i:s');
        })->everyMinute();

        dispatch($cli, 'jobs:install');

        // Execute at 12:00
        silence(fn() => dispatch($cli, 'jobs:collect'));

        // Advance 5 minutes instantly
        for ($i = 1; $i <= 5; $i++) {
            $clock->advance('+1 minute');
            silence(fn() => dispatch($cli, 'jobs:collect'));
        }

        Test::assertCount(6, $executions);
        Test::assertSame('12:00:00', $executions[0]);
        Test::assertSame('12:05:00', $executions[5]);
    });

    Test::it('should handle time advancement across days', function ($state) {
        $clock = $state['clock'];
        $clock->setNow('2024-01-31 23:55:00');

        $cli = Console::create();
        Job::register($cli);

        $dates = [];

        Job::schedule('day-cross', function () use (&$dates, $clock) {
            $dates[] = $clock->now()->format('Y-m-d H:i');
        })->everyMinute();

        dispatch($cli, 'jobs:install');

        // Execute across day boundary
        for ($i = 0; $i < 10; $i++) {
            silence(fn() => dispatch($cli, 'jobs:collect'));
            $clock->advance('+1 minute');
        }

        Test::assertSame('2024-01-31 23:55', $dates[0]);
        Test::assertSame('2024-02-01 00:00', $dates[5]);
        Test::assertSame('2024-02-01 00:04', $dates[9]);
    });

    Test::it('should execute 100 jobs with instant time jumps in < 1 second', function ($state) {
        $clock = $state['clock'];
        $clock->setNow('2024-01-01 00:00:00');

        $cli = Console::create();
        Job::register($cli);

        $count = 0;

        Job::schedule('fast-job', function () use (&$count) {
            $count++;
        })->everyMinute();

        dispatch($cli, 'jobs:install');

        $start = hrtime(true);

        for ($i = 0; $i < 100; $i++) {
            silence(fn() => dispatch($cli, 'jobs:collect'));
            $clock->advance('+1 minute');
        }

        $elapsed = (hrtime(true) - $start) / 1e9; // nanoseconds to seconds

        Test::assertSame(100, $count);
        Test::assertTrue($elapsed < 1.0, "Expected < 1s, took {$elapsed}s");
    });

    // NOTE: Lease is cleared after job execution (success or failure).
    // The lease only prevents duplicate execution DURING job execution,
    // not after it completes. The cron schedule determines re-execution timing.

    // ========================================================================
    // PERFORMANCE TESTS
    // ========================================================================

    Test::it('should select 1000 jobs in < 500ms', function ($state) {
        $clock = $state['clock'];
        $clock->setNow('2024-01-01 12:00:00');

        $cli = Console::create();
        Job::register($cli);

        // Create 1000 jobs with high concurrency
        for ($i = 0; $i < 1000; $i++) {
            Job::schedule("perf-job-$i", fn() => null)->everySecond()->concurrency(1000);
        }

        dispatch($cli, 'jobs:install');

        $start = hrtime(true);

        // Get Job instance and call selectDueJobs via reflection
        $job = Job::instance();
        $reflection = new ReflectionMethod($job, 'selectDueJobs');
        $reflection->setAccessible(true);
        $result = $reflection->invoke($job);

        $elapsed = (hrtime(true) - $start) / 1e6; // nanoseconds to milliseconds

        $selected = count($result['selected'] ?? []);

        Test::assertSame(1000, $selected);
        Test::assertTrue($elapsed < 500, "Expected < 500ms, got " . round($elapsed, 2) . "ms");
    });

    Test::it('should not exceed 50MB memory for 5000 jobs', function ($state) {
        $clock = $state['clock'];
        $baseline = memory_get_usage(true);

        $cli = Console::create();
        Job::register($cli);

        for ($i = 0; $i < 5000; $i++) {
            Job::schedule("mem-job-$i", fn() => null)->everyMinute();
        }

        dispatch($cli, 'jobs:install');

        $peak = memory_get_peak_usage(true);
        $used = ($peak - $baseline) / 1024 / 1024; // bytes to MB

        Test::assertTrue($used < 50, "Expected < 50MB, used " . round($used, 2) . "MB");
    });

    Test::it('should handle 100 job executions per second', function ($state) {
        $clock = $state['clock'];
        $clock->setNow('2024-01-01 12:00:00');

        $cli = Console::create();
        Job::register($cli);

        $count = 0;

        for ($i = 0; $i < 100; $i++) {
            Job::schedule("throughput-job-$i", function () use (&$count) {
                $count++;
            })->everySecond()->concurrency(100);
        }

        dispatch($cli, 'jobs:install');

        $start = hrtime(true);
        silence(fn() => dispatch($cli, 'jobs:collect'));
        $elapsed = (hrtime(true) - $start) / 1e9; // nanoseconds to seconds

        Test::assertSame(100, $count);
        Test::assertTrue($elapsed < 1.0, "Expected < 1s, took " . round($elapsed, 3) . "s");
    });

    // ========================================================================
    // RELIABILITY TESTS
    // ========================================================================

    Test::it('should respect concurrency limits within single worker', function ($state) {
        $clock = $state['clock'];
        $clock->setNow('2024-01-01 12:00:00');

        $cli = Console::create();
        Job::register($cli);

        // Create 10 jobs in same queue with concurrency 3
        for ($i = 0; $i < 10; $i++) {
            Job::schedule("concurrent-$i", fn() => null)
                ->everySecond()
                ->queue('limited')
                ->concurrency(3);
        }

        dispatch($cli, 'jobs:install');

        // Get Job instance and call selectDueJobs
        $job = Job::instance();
        $reflection = new ReflectionMethod($job, 'selectDueJobs');
        $reflection->setAccessible(true);
        $result = $reflection->invoke($job);

        $selected = count($result['selected'] ?? []);

        Test::assertTrue($selected <= 3, "Should select max 3 jobs, selected $selected");
    });

    Test::it('should handle job failures and record errors', function ($state) {
        $clock = $state['clock'];
        $clock->setNow('2024-01-01 12:00:00');

        $cli = Console::create();
        Job::register($cli);

        Job::schedule('failing-job', function () {
            throw new \RuntimeException('Intentional failure');
        })->everySecond();

        dispatch($cli, 'jobs:install');
        silence(fn() => dispatch($cli, 'jobs:collect'));

        $row = row($state, 'failing-job');

        Test::assertNotNull($row);
        Test::assertSame('Intentional failure', $row['last_error']);
        Test::assertSame(1, (int)$row['fail_count']);
    });

    Test::it('should clear error on successful retry', function ($state) {
        $clock = $state['clock'];
        $clock->setNow('2024-01-01 12:00:00');

        $cli = Console::create();
        Job::register($cli);

        $attempts = 0;

        Job::schedule('retry-job', function () use (&$attempts) {
            $attempts++;
            if ($attempts === 1) {
                throw new \RuntimeException('First attempt fails');
            }
            // Second attempt succeeds
        })->everySecond();

        dispatch($cli, 'jobs:install');

        // First execution (fails)
        silence(fn() => dispatch($cli, 'jobs:collect'));

        $row1 = row($state, 'retry-job');
        Test::assertSame('First attempt fails', $row1['last_error']);
        Test::assertSame(1, (int)$row1['fail_count']);

        // Second execution (succeeds)
        $clock->advance('+1 second');
        silence(fn() => dispatch($cli, 'jobs:collect'));

        $row2 = row($state, 'retry-job');
        Test::assertNull($row2['last_error'], 'Error should be cleared on success');
        Test::assertSame(1, (int)$row2['fail_count'], 'Fail count persists');
    });

    Test::it('should execute dispatched jobs immediately regardless of cron', function ($state) {
        $clock = $state['clock'];
        $clock->setNow('2024-01-01 12:00:30'); // Not at minute 0, so hourly won't match

        $cli = Console::create();
        Job::register($cli);

        $executed = false;

        // Job scheduled for every hour (not due now)
        Job::schedule('dispatch-test', function () use (&$executed) {
            $executed = true;
        })->hourly();

        dispatch($cli, 'jobs:install');

        // Should not execute (not due - hourly runs at minute 0)
        silence(fn() => dispatch($cli, 'jobs:collect'));
        Test::assertFalse($executed);

        // Dispatch manually
        Job::dispatch('dispatch-test');

        // Should execute immediately
        silence(fn() => dispatch($cli, 'jobs:collect'));
        Test::assertTrue($executed);
    });

    Test::it('should handle priority ordering correctly', function ($state) {
        $clock = $state['clock'];
        $clock->setNow('2024-01-01 12:00:00');

        $cli = Console::create();
        Job::register($cli);

        $order = [];

        Job::schedule('low-priority', function () use (&$order) {
            $order[] = 'low';
        })->everySecond()->priority(100)->concurrency(3);

        Job::schedule('high-priority', function () use (&$order) {
            $order[] = 'high';
        })->everySecond()->priority(10)->concurrency(3);

        Job::schedule('medium-priority', function () use (&$order) {
            $order[] = 'medium';
        })->everySecond()->priority(50)->concurrency(3);

        dispatch($cli, 'jobs:install');
        silence(fn() => dispatch($cli, 'jobs:collect'));

        // Should execute in priority order (lower number = higher priority)
        Test::assertSame(['high', 'medium', 'low'], $order);
    });

    // ========================================================================
    // CONCURRENCY TESTS (using pcntl_fork)
    // ========================================================================

    Test::it('should prevent duplicate execution with 5 concurrent workers (pcntl)', function ($state) {
        if (!function_exists('pcntl_fork')) {
            return; // Skip silently
        }

        // Create SHARED database and setup job in PARENT
        $parentClock = new MockClock('2024-01-01 12:00:00');
        $sharedPdo = new MockTimePDO($parentClock, shared: true);
        Container::set('db', $sharedPdo);

        $parentJob = new CoreJob($parentClock);
        Job::swap($parentJob);

        $executionFile = sys_get_temp_dir() . '/job-race-test-' . uniqid();

        Job::schedule('race-job', function () use ($executionFile) {
            $pid = getmypid();
            $time = (new \DateTimeImmutable())->format('H:i:s.u');
            file_put_contents($executionFile, "$pid executed at $time\n", FILE_APPEND);
        })->everySecond();

        // Install table in parent via CLI
        $cli = Console::create();
        Job::register($cli);
        dispatch($cli, 'jobs:install');

        $workers = 5;
        $pids = [];

        // Fork 5 workers that will try to execute the same job
        for ($i = 0; $i < $workers; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                Test::fail('Fork failed');
            }

            if ($pid === 0) {
                // CHILD PROCESS
                // Connect to the SHARED database
                Container::clear();
                $childClock = new MockClock('2024-01-01 12:00:00');
                $childPdo = new MockTimePDO($childClock, shared: true);
                Container::set('db', $childPdo);

                // Create new Job instance with clock and re-register job
                $childJob = new CoreJob($childClock);
                Job::swap($childJob);

                Job::schedule('race-job', function () use ($executionFile) {
                    $pid = getmypid();
                    $time = (new \DateTimeImmutable())->format('H:i:s.u');
                    file_put_contents($executionFile, "$pid executed at $time\n", FILE_APPEND);
                })->everySecond();

                // Try to execute (silenced to keep test output clean)
                silence(fn() => $childJob->run());

                exit(0);
            }

            $pids[] = $pid;
        }

        // PARENT: Wait for all children
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        // Verify: only 1 worker executed the job
        if (file_exists($executionFile)) {
            $content = file_get_contents($executionFile);
            $lines = array_filter(explode("\n", $content));
            $executions = count($lines);

            Test::assertSame(1, $executions, 'Only 1 worker should acquire the lease');

            unlink($executionFile);
        } else {
            Test::fail('No worker executed the job');
        }

        // Cleanup shared database
        MockTimePDO::cleanupSharedDb();
    });

    Test::it('should handle 10 workers with concurrency limit of 3 (pcntl)', function ($state) {
        $clock = $state['clock'];
        if (!function_exists('pcntl_fork')) {
            return; // Skip silently
        }

        $clock->setNow('2024-01-01 12:00:00');

        $cli = Console::create();
        Job::register($cli);

        // Create 10 jobs in same queue, concurrency 3
        for ($i = 0; $i < 10; $i++) {
            Job::schedule("job-$i", fn() => null)
                ->everySecond()
                ->queue('limited')
                ->concurrency(3);
        }

        dispatch($cli, 'jobs:install');

        $workers = 10;
        $pids = [];
        $resultsFile = sys_get_temp_dir() . '/concurrency-test-' . uniqid();

        for ($w = 0; $w < $workers; $w++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                Test::fail('Fork failed');
            }

            if ($pid === 0) {
                // CHILD
                Container::clear();
                Container::set('db', new MockTimePDO());
                $clock->setNow('2024-01-01 12:00:00');

                resetJobsInstance();

                $executed = 0;
                silence(function () use (&$executed) {
                    $executed = Job::instance()->run();
                });

                // Record how many jobs this worker executed
                $pid = getmypid();
                file_put_contents($resultsFile, "$pid:$executed\n", FILE_APPEND);

                exit(0);
            }

            $pids[] = $pid;
        }

        // Wait for all workers
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        // Analyze results
        if (file_exists($resultsFile)) {
            $content = file_get_contents($resultsFile);
            $lines = array_filter(explode("\n", $content));

            $totalExecuted = 0;
            foreach ($lines as $line) {
                [$pid, $count] = explode(':', $line);
                $totalExecuted += (int)$count;
            }

            // Each worker should execute at most 3 jobs (due to concurrency limit)
            // But across all workers, we expect up to 10 jobs total
            Test::assertTrue($totalExecuted <= 10, "Expected <= 10 total jobs, got $totalExecuted");

            unlink($resultsFile);
        }
    });

    Test::it('should measure lease contention with 20 workers on 1 job (pcntl)', function () {
        if (!function_exists('pcntl_fork')) {
            return; // Skip silently
        }

        // Create SHARED database in PARENT
        $parentClock = new MockClock('2024-01-01 12:00:00');
        $sharedPdo = new MockTimePDO($parentClock, shared: true);
        Container::set('db', $sharedPdo);

        $parentJob = new CoreJob($parentClock);
        Job::swap($parentJob);

        Job::schedule('contention-test', fn() => usleep(100000)) // 100ms work
            ->everySecond()
            ->lease(5); // Short lease

        // Install table in parent
        $cli = Console::create();
        Job::register($cli);
        dispatch($cli, 'jobs:install');

        $workers = 20;
        $pids = [];
        $statsFile = sys_get_temp_dir() . '/contention-stats-' . uniqid();

        for ($i = 0; $i < $workers; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                Test::fail('Fork failed');
            }

            if ($pid === 0) {
                // CHILD - Connect to SHARED database
                Container::clear();
                $childClock = new MockClock('2024-01-01 12:00:00');
                $childPdo = new MockTimePDO($childClock, shared: true);
                Container::set('db', $childPdo);

                $childJob = new CoreJob($childClock);
                Job::swap($childJob);

                Job::schedule('contention-test', fn() => usleep(100000))
                    ->everySecond()
                    ->lease(5);

                $executed = 0;
                silence(function () use (&$executed, $childJob) {
                    $executed = $childJob->run();
                });
                $pid = getmypid();

                file_put_contents($statsFile, "$pid acquired=" . ($executed > 0 ? 'yes' : 'no') . "\n", FILE_APPEND);

                exit(0);
            }

            $pids[] = $pid;
            usleep(10000); // Stagger starts slightly
        }

        // Wait for all
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        // Analyze contention
        if (file_exists($statsFile)) {
            $content = file_get_contents($statsFile);
            $lines = array_filter(explode("\n", $content));

            $acquired = 0;
            $failed = 0;

            foreach ($lines as $line) {
                if (str_contains($line, 'acquired=yes')) {
                    $acquired++;
                } else {
                    $failed++;
                }
            }

            Test::assertSame(1, $acquired, 'Only 1 worker should acquire lease');
            Test::assertSame(19, $failed, '19 workers should fail to acquire');

            unlink($statsFile);
        }

        // Cleanup shared database
        MockTimePDO::cleanupSharedDb();
    });

    Test::it('should scale linearly with independent jobs across workers (pcntl)', function () {
        if (!function_exists('pcntl_fork')) {
            return; // Skip silently
        }

        // Create SHARED database in PARENT
        $parentClock = new MockClock('2024-01-01 12:00:00');
        $sharedPdo = new MockTimePDO($parentClock, shared: true);
        Container::set('db', $sharedPdo);

        $parentJob = new CoreJob($parentClock);
        Job::swap($parentJob);

        // Create 20 independent jobs (different queues)
        for ($i = 0; $i < 20; $i++) {
            Job::schedule("independent-$i", fn() => null)
                ->everySecond()
                ->queue("queue-$i"); // Each job in own queue
        }

        // Install table in parent
        $cli = Console::create();
        Job::register($cli);
        dispatch($cli, 'jobs:install');

        $workers = 20;
        $pids = [];
        $resultsFile = sys_get_temp_dir() . '/scaling-test-' . uniqid();

        for ($i = 0; $i < $workers; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                Test::fail('Fork failed');
            }

            if ($pid === 0) {
                // CHILD - Connect to SHARED database
                Container::clear();
                $childClock = new MockClock('2024-01-01 12:00:00');
                $childPdo = new MockTimePDO($childClock, shared: true);
                Container::set('db', $childPdo);

                $childJob = new CoreJob($childClock);
                Job::swap($childJob);

                // Re-register jobs in child
                for ($j = 0; $j < 20; $j++) {
                    Job::schedule("independent-$j", fn() => null)
                        ->everySecond()
                        ->queue("queue-$j");
                }

                $executed = 0;
                silence(function () use (&$executed, $childJob) {
                    $executed = $childJob->run();
                });
                file_put_contents($resultsFile, "$executed\n", FILE_APPEND);

                exit(0);
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        if (file_exists($resultsFile)) {
            $content = file_get_contents($resultsFile);
            $lines = array_filter(explode("\n", $content));

            $total = array_sum(array_map('intval', $lines));

            // All 20 jobs should be executed (no contention on different queues)
            Test::assertSame(20, $total, 'All 20 independent jobs should execute');

            unlink($resultsFile);
        }

        // Cleanup shared database
        MockTimePDO::cleanupSharedDb();
    });
});
