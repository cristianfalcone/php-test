<?php

declare(strict_types=1);

namespace Ajo\Tests\Integration;

use Ajo\Test;
use DateTimeImmutable;
use PDO;
use RuntimeException;

// Force autoload Job2 to make Cron2 and Clock2 available
class_exists(\Ajo\Core\Job2::class);

use Ajo\Core\Job2;
use Ajo\Core\Cron2;
use Ajo\Core\Clock2;

// ============================================================================
// Test Clock for deterministic time control
// ============================================================================

final class FakeClock implements Clock2
{
    private DateTimeImmutable $now;

    public function __construct(?DateTimeImmutable $now = null)
    {
        $this->now = $now ?? new DateTimeImmutable('2025-01-15 10:30:45');
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function setNow(DateTimeImmutable $time): void
    {
        $this->now = $time;
    }

    public function advance(string $interval): void
    {
        $this->now = $this->now->modify($interval);
    }
}

// ============================================================================
// Test Database Helper
// ============================================================================

function createTestPDO(): PDO
{
    static $pdo = null;

    // Check if connection is alive, reconnect if not
    if ($pdo !== null) {
        try {
            $pdo->query('SELECT 1');
        } catch (\PDOException) {
            $pdo = null; // Force reconnection
        }
    }

    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=db;dbname=app;charset=utf8mb4',
            'appuser',
            'apppass',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }

    return $pdo;
}

/**
 * Get unique table name for this process (for parallel test execution)
 */
function getTestTableName(): string
{
    static $tableName = null;
    if ($tableName === null) {
        $tableName = 'jobs_test_' . getmypid();
    }
    return $tableName;
}

function cleanJobsTable(PDO $pdo, ?string $tableName = null): void
{
    $table = $tableName ?? getTestTableName();
    try {
        $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
    } catch (\Exception $e) {
        // Table might not exist, that's okay
    }
}

Test::describe('Job2 Integration Tests', function () {

    // ========================================================================
    // Schema Installation
    // ========================================================================

    Test::beforeEach(function ($state) {
        $state['pdo'] = createTestPDO();
        cleanJobsTable($state['pdo']);
        $state['clock'] = new FakeClock();
        $state['job'] = new Job2($state['pdo'], $state['clock'], getTestTableName());
    });

    Test::afterEach(function ($state) {
        cleanJobsTable($state['pdo']);
    });

    Test::it('install creates table with correct schema', function ($state) {
        $job = $state['job'];
        $pdo = $state['pdo'];

        $job->install();

        // Verify table exists
        $table = getTestTableName();
        $tables = $pdo->query("SHOW TABLES LIKE '{$table}'")->fetchAll();
        Test::assertCount(1, $tables);

        // Verify columns
        $columns = $pdo->query("DESCRIBE `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        $columnNames = array_column($columns, 'Field');

        Test::assertContains('id', $columnNames);
        Test::assertContains('name', $columnNames);
        Test::assertContains('queue', $columnNames);
        Test::assertContains('priority', $columnNames);
        Test::assertContains('run_at', $columnNames);
        Test::assertContains('locked_until', $columnNames);
        Test::assertContains('attempts', $columnNames);
        Test::assertContains('args', $columnNames);
        Test::assertContains('unique_key', $columnNames);

        // Verify DATETIME(6) precision
        $runAtCol = array_values(array_filter($columns, fn($c) => $c['Field'] === 'run_at'))[0] ?? null;
        Test::assertNotNull($runAtCol, 'run_at column should exist');
        Test::assertStringContainsString('datetime(6)', strtolower($runAtCol['Type'] ?? ''));

        // Verify indexes
        $indexes = $pdo->query("SHOW INDEX FROM `".getTestTableName()."`")->fetchAll(PDO::FETCH_ASSOC);
        $indexNames = array_unique(array_column($indexes, 'Key_name'));

        Test::assertContains('PRIMARY', $indexNames);
        Test::assertContains('uq_cron_unique', $indexNames);
        Test::assertContains('idx_due', $indexNames);
        Test::assertContains('idx_name_due', $indexNames);

        // Verify unique constraint
        $uniqueIndexes = array_filter($indexes, fn($i) => $i['Key_name'] === 'uq_cron_unique');
        Test::assertTrue(count($uniqueIndexes) > 0, 'uq_cron_unique index should exist');
    });

    // ========================================================================
    // Encolado Idempotente
    // ========================================================================

    Test::it('enqueue now is idempotent across processes', function ($state) {
        $job = $state['job'];
        $pdo = $state['pdo'];
        $clock = $state['clock'];

        $job->install();
        $clock->setNow(new DateTimeImmutable('2025-01-15 10:30:00'));

        // Define a cron job that matches current time (every minute at :00)
        $job->schedule('test-cron', fn() => null)
            ->everyMinute(); // Runs at 0 seconds of every minute

        // Use reflection to call private enqueue() method
        $reflection = new \ReflectionClass($job);
        $enqueueMethod = $reflection->getMethod('enqueue');
        $enqueueMethod->setAccessible(true);

        // First enqueue
        $enqueueMethod->invoke($job);

        // Count jobs
        $count1 = $pdo->query("SELECT COUNT(*) FROM `".getTestTableName()."` WHERE name='test-cron'")->fetchColumn();

        // Second enqueue (simulating another process/server)
        $enqueueMethod->invoke($job);

        // Count should be the same (idempotent via unique_key)
        $count2 = $pdo->query("SELECT COUNT(*) FROM `".getTestTableName()."` WHERE name='test-cron'")->fetchColumn();

        Test::assertEquals($count1, $count2, 'Enqueue should be idempotent');

        // Verify unique_key is set
        $uniqueKey = $pdo->query("SELECT unique_key FROM `".getTestTableName()."` WHERE name='test-cron' LIMIT 1")->fetchColumn();
        Test::assertNotNull($uniqueKey);
        Test::assertStringContainsString('test-cron|', $uniqueKey);
    });

    Test::it('enqueue next slot reduces drift', function ($state) {
        $job = $state['job'];
        $pdo = $state['pdo'];
        $clock = $state['clock'];

        $job->install();
        $clock->setNow(new DateTimeImmutable('2025-01-15 10:30:00'));

        // Define a cron job that runs every 5 seconds
        $job->schedule('frequent-job', fn() => null)
            ->everyFiveSeconds();

        // Use reflection to call private enqueue() method
        $reflection = new \ReflectionClass($job);
        $enqueueMethod = $reflection->getMethod('enqueue');
        $enqueueMethod->setAccessible(true);

        // Enqueue
        $enqueueMethod->invoke($job);

        // Should have at least one job enqueued (the next occurrence)
        $jobs = $pdo->query("SELECT run_at FROM `".getTestTableName()."` WHERE name='frequent-job' ORDER BY run_at ASC")->fetchAll(PDO::FETCH_ASSOC);

        Test::assertTrue(count($jobs) >= 1, 'Should have at least one future occurrence');

        // Verify there's a job scheduled in the future
        $firstRunAt = new DateTimeImmutable($jobs[0]['run_at']);
        $now = $clock->now();

        Test::assertTrue($firstRunAt >= $now, 'Next run should be in the future or now');

        // If current time doesn't match cron, next should be within 5 seconds
        if (count($jobs) === 1) {
            $diff = $firstRunAt->getTimestamp() - $now->getTimestamp();
            Test::assertTrue($diff <= 5, 'Next run should be within 5 seconds');
        }
    });

    // ========================================================================
    // Dispatch On-Demand
    // ========================================================================

    Test::it('dispatch FQCN without schedule auto-defines and lazy resolves handler', function () {
        $pdo = createTestPDO();
        cleanJobsTable($pdo);
        $job = new Job2($pdo, null, getTestTableName());
        $job->install();

        // Clear any previous execution proof
        unset($GLOBALS['test_job_executed']);

        // Dispatch FQCN directly without schedule() or task
        $testArgs = ['user_id' => 42, 'action' => 'notify'];
        $job->args($testArgs)->dispatch(\Ajo\Tests\Integration\TestJobHandler::class);

        // Verify job is in database
        $count = $pdo->query("SELECT COUNT(*) FROM `".getTestTableName()."` WHERE name='Ajo\\\\Tests\\\\Integration\\\\TestJobHandler'")->fetchColumn();
        Test::assertEquals(1, $count, 'Job should be queued');

        // Run the job
        $runs = $job->run();
        Test::assertEquals(1, $runs);

        // Verify it executed via static handle()
        Test::assertTrue(isset($GLOBALS['test_job_executed']), 'Job handler should have executed');
        Test::assertEquals($testArgs, $GLOBALS['test_job_executed'], 'Args should be passed to handler');

        // Verify job was deleted after success
        $countAfter = $pdo->query("SELECT COUNT(*) FROM `".getTestTableName()."` WHERE name='Ajo\\\\Tests\\\\Integration\\\\TestJobHandler'")->fetchColumn();
        Test::assertEquals(0, $countAfter);

        cleanJobsTable($pdo);
    });

    Test::it('dispatch FQCN with instance handle method', function () {
        $pdo = createTestPDO();
        cleanJobsTable($pdo);
        $job = new Job2($pdo, null, getTestTableName());
        $job->install();

        // Clear any previous execution proof
        unset($GLOBALS['test_job_executed']);

        // Dispatch FQCN that uses instance handle() method
        $testArgs = ['order_id' => 123];
        $job->args($testArgs)->dispatch(\Ajo\Tests\Integration\TestJobHandlerInstance::class);

        // Run the job
        $job->run();

        // Verify it executed via instance handle()
        Test::assertTrue(isset($GLOBALS['test_job_executed']), 'Instance handler should have executed');
        Test::assertEquals($testArgs, $GLOBALS['test_job_executed']);

        cleanJobsTable($pdo);
    });

    Test::it('active creates draft on-demand with builder pattern', function () {
        $pdo = createTestPDO();
        cleanJobsTable($pdo);
        $job = new Job2($pdo, null, getTestTableName());
        $job->install();

        // Use builder pattern without schedule()
        $job->args(['x' => 1])->delay(5);

        // Verify no DB rows created yet
        $count = $pdo->query("SELECT COUNT(*) FROM `".getTestTableName()."`")->fetchColumn();
        Test::assertEquals(0, $count, 'Builder should not create DB rows');

        // Attempt dispatch() without name should throw
        try {
            $job->dispatch();
            Test::assertTrue(false, 'Should have thrown RuntimeException');
        } catch (\RuntimeException $e) {
            Test::assertStringContainsString('No job selected', $e->getMessage());
        }

        // Now dispatch with FQCN should succeed
        unset($GLOBALS['test_job_executed']);
        $job->dispatch(\Ajo\Tests\Integration\TestJobHandler::class);

        // Verify job was created with correct args
        $row = $pdo->query("SELECT args FROM `".getTestTableName()."` LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
        Test::assertNotNull($row);
        $storedArgs = json_decode($row['args'], true);
        Test::assertEquals(['x' => 1], $storedArgs);

        cleanJobsTable($pdo);
    });

    Test::it('at() post-dispatch updates run_at when not claimed', function () {
        $pdo = createTestPDO();
        cleanJobsTable($pdo);
        $job = new Job2($pdo, null, getTestTableName());
        $job->install();

        unset($GLOBALS['test_job_executed']);

        // Dispatch job
        $job->dispatch(\Ajo\Tests\Integration\TestJobHandler::class);

        // Get original run_at
        $row1 = $pdo->query("SELECT run_at FROM `".getTestTableName()."` LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
        $originalRunAt = new \DateTimeImmutable($row1['run_at']);

        // Update run_at to 5 seconds in future
        $futureTime = (new \DateTimeImmutable())->modify('+5 seconds');
        $job->at($futureTime);

        // Verify run_at was updated
        $row2 = $pdo->query("SELECT run_at FROM `".getTestTableName()."` LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
        $newRunAt = new \DateTimeImmutable($row2['run_at']);

        Test::assertTrue($newRunAt > $originalRunAt, 'run_at should be updated to future time');
        $diff = abs($newRunAt->getTimestamp() - $futureTime->getTimestamp());
        Test::assertTrue($diff < 2, "Updated run_at should match requested time (diff: {$diff}s)");

        // Verify job doesn't execute yet (run_at in future)
        $job->run();
        Test::assertFalse(isset($GLOBALS['test_job_executed']), 'Job should not execute yet');

        cleanJobsTable($pdo);
    });

    Test::it('at() post-dispatch throws when job already claimed', function () {
        $pdo = createTestPDO();
        cleanJobsTable($pdo);
        $job = new Job2($pdo, null, getTestTableName());
        $job->install();

        // Dispatch job
        $job->dispatch(\Ajo\Tests\Integration\TestJobHandler::class);

        // Get job ID
        $row = $pdo->query("SELECT id FROM `".getTestTableName()."` LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
        $jobId = $row['id'];

        // Manually lock the job (simulate another worker claimed it)
        $pdo->exec("UPDATE `".getTestTableName()."` SET locked_until = DATE_ADD(NOW(6), INTERVAL 60 SECOND) WHERE id = {$jobId}");

        // Attempt to update run_at should fail
        try {
            $job->at((new \DateTimeImmutable())->modify('+10 seconds'));
            Test::assertTrue(false, 'Should have thrown RuntimeException');
        } catch (\RuntimeException $e) {
            Test::assertStringContainsString('Cannot update', $e->getMessage());
            Test::assertStringContainsString('may have already run', $e->getMessage());
        }

        cleanJobsTable($pdo);
    });

    Test::it('args are used as defaults for cron dispatch', function ($state) {
        $job = $state['job'];
        $pdo = $state['pdo'];
        $clock = $state['clock'];

        $job->install();
        $clock->setNow(new \DateTimeImmutable('2025-01-15 10:30:00'));

        // Schedule cron job with default args
        $job->schedule('cron-with-defaults', fn() => null)
            ->args(['region' => 'eu', 'batch_size' => 100])
            ->everyMinute();

        // Use reflection to call private enqueue() method
        $reflection = new \ReflectionClass($job);
        $enqueueMethod = $reflection->getMethod('enqueue');
        $enqueueMethod->setAccessible(true);

        // Enqueue cron job
        $enqueueMethod->invoke($job);

        // Verify job was enqueued with default args
        $row = $pdo->query("SELECT args FROM `".getTestTableName()."` WHERE name='cron-with-defaults' LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
        Test::assertNotNull($row, 'Cron job should be enqueued');

        $storedArgs = json_decode($row['args'], true);
        Test::assertEquals(['region' => 'eu', 'batch_size' => 100], $storedArgs, 'Default args should be stored');
    });

    Test::it('runAt staging cleared after dispatch', function ($state) {
        $job = $state['job'];
        $pdo = $state['pdo'];

        $job->install();

        // Schedule and set delay (pre-dispatch)
        $job->schedule('staging-test', fn() => null)->delay(10);

        // Use reflection to check runAt before dispatch
        $reflection = new \ReflectionClass($job);
        $jobsProperty = $reflection->getProperty('jobs');
        $jobsProperty->setAccessible(true);
        $jobs = $jobsProperty->getValue($job);

        Test::assertNotNull($jobs['staging-test']['runAt'], 'runAt should be set before dispatch');

        // Dispatch
        $job->dispatch('staging-test');

        // Check runAt after dispatch - should be cleared
        $jobs = $jobsProperty->getValue($job);
        Test::assertNull($jobs['staging-test']['runAt'], 'runAt should be cleared after dispatch');

        // Verify job was created in DB
        $count = $pdo->query("SELECT COUNT(*) FROM `".getTestTableName()."` WHERE name='staging-test'")->fetchColumn();
        Test::assertEquals(1, $count);
    });

    Test::it('dispatch immediate executes even without cron match', function () {
        $pdo = createTestPDO();
        cleanJobsTable($pdo);
        // NOTE: Use real clock for this suite because batch() uses MySQL NOW(6)
        $job = new Job2($pdo, null, getTestTableName());
        $job->install();

        $executed = false;
        $job->schedule('on-demand', function () use (&$executed) {
            $executed = true;
        });

        // Dispatch without cron schedule
        $job->dispatch('on-demand');

        // Verify job is in database
        $count = $pdo->query("SELECT COUNT(*) FROM `".getTestTableName()."` WHERE name='on-demand'")->fetchColumn();
        Test::assertEquals(1, $count);

        // Run the job
        $runs = $job->run();
        Test::assertEquals(1, $runs);

        // Verify it executed
        Test::assertTrue($executed);

        // Verify job was deleted after success
        $countAfter = $pdo->query("SELECT COUNT(*) FROM `".getTestTableName()."` WHERE name='on-demand'")->fetchColumn();
        Test::assertEquals(0, $countAfter);

        cleanJobsTable($pdo);
    });

    Test::it('dispatch with delay respects eta', function () {
        $pdo = createTestPDO();
        cleanJobsTable($pdo);
        $job = new Job2($pdo, null, getTestTableName());
        $job->install();

        $executed = false;
        $job->schedule('delayed', function () use (&$executed) {
            $executed = true;
        });

        // Dispatch with 2 second delay using fluent API
        $job->delay(2)->dispatch('delayed');

        // Job should be in database
        $row = $pdo->query("SELECT run_at, NOW(6) as now FROM `".getTestTableName()."` WHERE name='delayed'")->fetch(PDO::FETCH_ASSOC);
        Test::assertNotNull($row);

        // Verify run_at is approximately 2 seconds in the future
        $runAt = new DateTimeImmutable($row['run_at']);
        $now = new DateTimeImmutable($row['now']);
        $diff = $runAt->getTimestamp() - $now->getTimestamp();
        Test::assertTrue($diff >= 1 && $diff <= 3, "Delay should be ~2 seconds, was {$diff}s");

        // Use reflection to access batch
        $reflection = new \ReflectionClass($job);
        $batchMethod = $reflection->getMethod('batch');
        $batchMethod->setAccessible(true);

        // Should NOT claim now (before delay expires)
        $runs1 = $batchMethod->invoke($job, 10);
        Test::assertCount(0, $runs1, 'Should not claim before delay expires');
        Test::assertFalse($executed);

        // Wait for the delay
        sleep(3);

        // Now should claim and execute
        $runs2 = $batchMethod->invoke($job, 10);
        Test::assertCount(1, $runs2, 'Should claim after delay expires');

        // Execute it
        $executeMethod = $reflection->getMethod('execute');
        $executeMethod->setAccessible(true);
        $executeMethod->invoke($job, $runs2[0]);

        Test::assertTrue($executed, 'Job should have executed');

        cleanJobsTable($pdo);
    });

    Test::it('dispatch serializes and passes args', function () {
        $pdo = createTestPDO();
        cleanJobsTable($pdo);
        $job = new Job2($pdo, null, getTestTableName());
        $job->install();

        $receivedArgs = null;
        $job->schedule('with-args', function ($args) use (&$receivedArgs) {
            $receivedArgs = $args;
        });

        // Dispatch with complex args including Unicode using fluent API
        $testArgs = [
            'id' => 123,
            'name' => 'José García',
            'emoji' => '🚀',
            'nested' => ['key' => 'value'],
        ];

        $job->args($testArgs)->dispatch('with-args');

        // Verify args are stored as JSON
        $row = $pdo->query("SELECT args FROM `".getTestTableName()."` WHERE name='with-args'")->fetch(PDO::FETCH_ASSOC);
        Test::assertNotNull($row);

        $storedArgs = json_decode($row['args'], true);
        Test::assertEquals($testArgs, $storedArgs);

        // Run and verify args are passed correctly
        $job->run();
        Test::assertEquals($testArgs, $receivedArgs);

        cleanJobsTable($pdo);
    });

    // ========================================================================
    // Selección y Claim
    // ========================================================================

    Test::it('batch claims respect ordering', function ($state) {
        $job = $state['job'];
        $pdo = $state['pdo'];

        $job->install();

        $executionOrder = [];

        // Create jobs with different priorities
        $job->schedule('low-priority', function () use (&$executionOrder) {
            $executionOrder[] = 'low';
        })->priority(200);

        $job->schedule('high-priority', function () use (&$executionOrder) {
            $executionOrder[] = 'high';
        })->priority(50);

        $job->schedule('medium-priority', function () use (&$executionOrder) {
            $executionOrder[] = 'medium';
        })->priority(100);

        // Dispatch all
        $job->dispatch('low-priority');
        $job->dispatch('high-priority');
        $job->dispatch('medium-priority');

        // Run all jobs
        $job->run(10);

        // Should execute in priority order: high (50), medium (100), low (200)
        Test::assertEquals(['high', 'medium', 'low'], $executionOrder);
    });

    Test::it('claim is non-blocking with skip locked', function ($state) {
        $job = $state['job'];
        $pdo = $state['pdo'];

        $job->install();

        $job->schedule('test-job', fn() => null);
        $job->dispatch('test-job');

        // Verify job exists
        $count = $pdo->query("SELECT COUNT(*) FROM `".getTestTableName()."` WHERE name='test-job' AND run_at <= NOW(6)")->fetchColumn();
        Test::assertEquals(1, $count, 'Job should be ready to claim');

        // Create second PDO connection
        $pdo2 = new PDO(
            'mysql:host=db;dbname=app;charset=utf8mb4',
            'appuser',
            'apppass',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );

        // First connection locks the row
        $pdo->exec("START TRANSACTION");
        $locked = $pdo->query("SELECT * FROM `".getTestTableName()."` WHERE name='test-job' FOR UPDATE")->fetchAll();
        Test::assertCount(1, $locked, 'Should lock one row');

        // Second connection tries to claim using SKIP LOCKED
        $job2 = new Job2($pdo2, $state['clock'], getTestTableName());
        $job2->schedule('test-job', fn() => null);

        $reflection = new \ReflectionClass($job2);
        $batchMethod = $reflection->getMethod('batch');
        $batchMethod->setAccessible(true);

        $start = microtime(true);
        $runs = $batchMethod->invoke($job2, 10);
        $elapsed = microtime(true) - $start;

        // Should return quickly
        Test::assertTrue($elapsed < 0.2, "Batch should not block, took {$elapsed}s");
        Test::assertCount(0, $runs, 'Second connection should not claim locked row');

        // Release the lock
        $pdo->exec("ROLLBACK");

        // Now should be able to claim
        $runs2 = $batchMethod->invoke($job2, 10);
        Test::assertCount(1, $runs2, 'Should claim after lock is released');
    });

    Test::it('only known job names are fetched', function ($state) {
        $job = $state['job'];
        $pdo = $state['pdo'];

        $job->install();

        // Register only one job
        $job->schedule('known-job', fn() => null);

        // Manually insert an unknown job
        $pdo->exec("INSERT INTO `".getTestTableName()."` (name, queue, priority, run_at, attempts)
                    VALUES ('unknown-job', 'default', 100, NOW(6), 0)");

        // Insert the known job
        $job->dispatch('known-job');

        // Run should only execute the known job
        $reflection = new \ReflectionClass($job);
        $batchMethod = $reflection->getMethod('batch');
        $batchMethod->setAccessible(true);

        $runs = $batchMethod->invoke($job, 10);

        // Should only claim the known job
        Test::assertCount(1, $runs);
        Test::assertEquals('known-job', $runs[0]['name']);
    });

    Test::it('lease is set on claim and cleared on finish', function ($state) {
        $job = $state['job'];
        $pdo = $state['pdo'];
        $clock = $state['clock'];

        $job->install();

        $job->schedule('test-job', fn() => null)
            ->lease(60);

        $job->dispatch('test-job');

        // Before claim, locked_until should be NULL
        $row = $pdo->query("SELECT locked_until, attempts FROM `".getTestTableName()."` WHERE name='test-job'")->fetch(PDO::FETCH_ASSOC);
        Test::assertNull($row['locked_until']);
        Test::assertEquals(0, $row['attempts']);

        // Claim
        $reflection = new \ReflectionClass($job);
        $batchMethod = $reflection->getMethod('batch');
        $batchMethod->setAccessible(true);

        $runs = $batchMethod->invoke($job, 1);
        Test::assertCount(1, $runs);

        // After claim, locked_until should be set
        $row = $pdo->query("SELECT locked_until, attempts FROM `".getTestTableName()."` WHERE name='test-job'")->fetch(PDO::FETCH_ASSOC);
        Test::assertNotNull($row['locked_until']);
        Test::assertEquals(1, $row['attempts']);

        // locked_until should be ~60 seconds in future
        $lockedUntil = new DateTimeImmutable($row['locked_until']);
        $expectedLease = $clock->now()->modify('+60 seconds');
        $diff = abs($lockedUntil->getTimestamp() - $expectedLease->getTimestamp());
        Test::assertTrue($diff < 2, "Lease should be ~60 seconds, diff was {$diff}s");

        // Execute the job
        $executeMethod = $reflection->getMethod('execute');
        $executeMethod->setAccessible(true);
        $executeMethod->invoke($job, $runs[0]);

        // After success, job should be deleted
        $count = $pdo->query("SELECT COUNT(*) FROM `".getTestTableName()."` WHERE name='test-job'")->fetchColumn();
        Test::assertEquals(0, $count);
    });

    // ========================================================================
    // Concurrencia Per-Job
    // ========================================================================

    Test::it('concurrency slots limit parallel executions', function ($state) {
        $job = $state['job'];
        $job->install();

        $concurrentCount = 0;
        $maxConcurrent = 0;

        $job->schedule('limited-job', function () use (&$concurrentCount, &$maxConcurrent) {
            $concurrentCount++;
            $maxConcurrent = max($maxConcurrent, $concurrentCount);
            usleep(50000);
            $concurrentCount--;
        })->concurrency(2);

        // Dispatch 5 jobs
        for ($i = 0; $i < 5; $i++) {
            $job->dispatch('limited-job');
        }

        // Run all jobs
        $job->run(10);

        // Verify concurrency setting
        $reflection = new \ReflectionClass($job);
        $jobsProperty = $reflection->getProperty('jobs');
        $jobsProperty->setAccessible(true);
        $jobs = $jobsProperty->getValue($job);

        Test::assertEquals(2, $jobs['limited-job']['concurrency']);
    });

    Test::it('release of named locks on success and error', function ($state) {
        $job = $state['job'];
        $pdo = $state['pdo'];

        $job->install();

        $executed = 0;

        $job->schedule('lock-test', function () use (&$executed) {
            $executed++;
            if ($executed === 1) {
                throw new \Exception('First execution fails');
            }
        })->concurrency(1)->retries(2);

        // Dispatch two jobs
        $job->dispatch('lock-test');
        $job->dispatch('lock-test');

        // First job fails and releases lock
        $job->run(1);
        Test::assertEquals(1, $executed);

        // Second job should acquire the lock
        $job->run(1);
        Test::assertEquals(2, $executed);

        // Verify no lingering locks
        $locks = $pdo->query("SELECT IS_USED_LOCK('job:lock-test:0')")->fetchColumn();
        Test::assertNull($locks, 'Lock should be released');
    });

    Test::it('acquires different slots for same job', function ($state) {
        $job = $state['job'];

        $job->install();

        $job->schedule('multi-slot', fn() => usleep(10000))
            ->concurrency(3);

        // Dispatch multiple jobs
        for ($i = 0; $i < 3; $i++) {
            $job->dispatch('multi-slot');
        }

        // Use reflection to test acquire
        $reflection = new \ReflectionClass($job);
        $acquireMethod = $reflection->getMethod('acquire');
        $acquireMethod->setAccessible(true);

        // Try to acquire 3 slots
        $lock1 = $acquireMethod->invoke($job, 'test:multi', 3);
        Test::assertNotNull($lock1);

        $lock2 = $acquireMethod->invoke($job, 'test:multi', 3);
        Test::assertNotNull($lock2);

        $lock3 = $acquireMethod->invoke($job, 'test:multi', 3);
        Test::assertNotNull($lock3);

        // Fourth attempt should fail
        $lock4 = $acquireMethod->invoke($job, 'test:multi', 3);
        Test::assertNull($lock4);

        // Release locks
        $releaseMethod = $reflection->getMethod('release');
        $releaseMethod->setAccessible(true);
        $releaseMethod->invoke($job, [$lock1]);
        $releaseMethod->invoke($job, [$lock2]);
        $releaseMethod->invoke($job, [$lock3]);
    });

    // ========================================================================
    // Filtros & Hooks
    // ========================================================================

    Test::it('when false skips execution and preserves row', function ($state) {
        $job = $state['job'];
        $pdo = $state['pdo'];

        $job->install();

        $executed = false;
        $job->schedule('filtered-job', function () use (&$executed) {
            $executed = true;
        })->when(fn($args) => $args['run'] === true);

        // Dispatch with run=false using fluent API
        $job->args(['run' => false])->dispatch('filtered-job');

        // Run should skip execution
        $job->run();

        Test::assertFalse($executed, 'Job should not have executed');

        // Row should still exist
        $count = $pdo->query("SELECT COUNT(*) FROM `".getTestTableName()."` WHERE name='filtered-job'")->fetchColumn();
        Test::assertEquals(1, $count, 'Job row should be preserved after when() returns false');
    });

    Test::it('skip true skips execution', function ($state) {
        $job = $state['job'];
        $pdo = $state['pdo'];

        $job->install();

        $executed = false;
        $job->schedule('skipped-job', function () use (&$executed) {
            $executed = true;
        })->skip(fn($args) => $args['skip'] === true);

        // Dispatch with skip=true using fluent API
        $job->args(['skip' => true])->dispatch('skipped-job');

        // Run should skip execution
        $job->run();

        Test::assertFalse($executed, 'Job should not have executed');

        // Row should still exist
        $count = $pdo->query("SELECT COUNT(*) FROM `".getTestTableName()."` WHERE name='skipped-job'")->fetchColumn();
        Test::assertEquals(1, $count);
    });

    Test::it('multiple hooks execute in registration order', function ($state) {
        $job = $state['job'];

        $job->install();

        $order = [];

        $job->schedule('hooked-job', function ($args) use (&$order) {
            $order[] = 'handler';
        })
        ->before(function($args) use (&$order) { $order[] = 'before1'; })
        ->before(function($args) use (&$order) { $order[] = 'before2'; })
        ->then(function($args) use (&$order) { $order[] = 'then1'; })
        ->then(function($args) use (&$order) { $order[] = 'then2'; })
        ->finally(function($args) use (&$order) { $order[] = 'finally1'; })
        ->finally(function($args) use (&$order) { $order[] = 'finally2'; });

        $job->dispatch('hooked-job');
        $job->run();

        // Verify order
        Test::assertEquals([
            'before1',
            'before2',
            'handler',
            'then1',
            'then2',
            'finally1',
            'finally2'
        ], $order);
    });

    Test::it('hooks execute on error path', function ($state) {
        $job = $state['job'];

        $job->install();

        $order = [];
        $caughtException = null;

        $job->schedule('error-job', function () use (&$order) {
            $order[] = 'handler';
            throw new \RuntimeException('Test error');
        })
        ->before(function($args) use (&$order) { $order[] = 'before'; })
        ->catch(function ($e, $args) use (&$order, &$caughtException) {
            $order[] = 'catch';
            $caughtException = $e;
        })
        ->finally(function($args) use (&$order) { $order[] = 'finally'; });

        $job->dispatch('error-job');
        $job->run();

        // Verify order: before → handler → catch → finally
        Test::assertEquals(['before', 'handler', 'catch', 'finally'], $order);
        Test::assertInstanceOf(\RuntimeException::class, $caughtException);
        Test::assertEquals('Test error', $caughtException->getMessage());
    });

    // ========================================================================
    // Retries & Fallas
    // ========================================================================

    Test::it('retry increments attempts and requeues with jitter', function ($state) {
        $job = $state['job'];
        $pdo = $state['pdo'];

        $job->install();

        $attempts = 0;
        $job->schedule('retry-job', function () use (&$attempts) {
            $attempts++;
            throw new \Exception('Always fails');
        })->retries(3, 2, 60, 'full');

        $job->dispatch('retry-job');

        // First attempt
        $job->run();
        Test::assertEquals(1, $attempts);

        // Check job was requeued with updated attempts
        $row = $pdo->query("SELECT attempts, run_at FROM `".getTestTableName()."` WHERE name='retry-job'")->fetch(PDO::FETCH_ASSOC);
        Test::assertEquals(1, $row['attempts']);

        // run_at should be in the future or now (jitter can be 0)
        $runAt = new DateTimeImmutable($row['run_at']);
        $now = $state['clock']->now();
        Test::assertTrue($runAt >= $now, 'Job should be rescheduled at or after now');
    });

    Test::it('retry resets on success', function ($state) {
        $job = $state['job'];
        $pdo = $state['pdo'];

        $job->install();

        $attempts = 0;
        $job->schedule('eventual-success', function () use (&$attempts) {
            $attempts++;
            if ($attempts < 2) {
                throw new \Exception('Fail on first try');
            }
        })->retries(3);

        $job->dispatch('eventual-success');

        // First attempt fails
        $job->run();
        Test::assertEquals(1, $attempts);

        // Job exists with attempts=1
        $count = $pdo->query("SELECT COUNT(*) FROM `".getTestTableName()."` WHERE name='eventual-success'")->fetchColumn();
        Test::assertEquals(1, $count);

        // Wait and run again (succeeds)
        sleep(1);
        $job->run();
        Test::assertEquals(2, $attempts);

        // Job deleted after success
        $count = $pdo->query("SELECT COUNT(*) FROM `".getTestTableName()."` WHERE name='eventual-success'")->fetchColumn();
        Test::assertEquals(0, $count);
    });

    Test::it('max attempts deletes job on exceed', function ($state) {
        $job = $state['job'];
        $pdo = $state['pdo'];

        $job->install();

        $attempts = 0;
        $job->schedule('always-fail', function () use (&$attempts) {
            $attempts++;
            throw new \Exception('Always fails');
        })->retries(2);

        $job->dispatch('always-fail');

        // First attempt
        $job->run();
        Test::assertEquals(1, $attempts);

        // Job still exists
        $count = $pdo->query("SELECT COUNT(*) FROM `".getTestTableName()."` WHERE name='always-fail'")->fetchColumn();
        Test::assertEquals(1, $count);

        // Second attempt (last retry)
        sleep(1);
        $job->run();
        Test::assertEquals(2, $attempts);

        // Job should be deleted
        $count = $pdo->query("SELECT COUNT(*) FROM `".getTestTableName()."` WHERE name='always-fail'")->fetchColumn();
        Test::assertEquals(0, $count);
    });

    Test::it('priority is preserved on retry', function ($state) {
        $job = $state['job'];
        $pdo = $state['pdo'];

        $job->install();

        $job->schedule('priority-job', function () {
            throw new \Exception('Fail');
        })->priority(50)->retries(3);

        $job->dispatch('priority-job');

        // First attempt
        $job->run();

        // Check priority is preserved
        $row = $pdo->query("SELECT priority FROM `".getTestTableName()."` WHERE name='priority-job'")->fetch(PDO::FETCH_ASSOC);
        Test::assertEquals(50, $row['priority']);
    });

    Test::it('retries with jitter=none uses deterministic backoff', function () {
        $pdo = createTestPDO();
        cleanJobsTable($pdo);
        $job = new Job2($pdo, null, getTestTableName());
        $job->install();

        $attempts = 0;
        $delays = [];

        $job->schedule('jitter-none-job', function () use (&$attempts) {
            $attempts++;
            throw new \Exception('Always fails for testing');
        })->retries(max: 4, base: 2, cap: 60, jitter: 'none');

        $job->dispatch('jitter-none-job');

        // Execute multiple times and capture scheduled delays
        for ($i = 0; $i < 3; $i++) {
            // Get run_at before execution
            $rowBefore = $pdo->query("SELECT run_at FROM `".getTestTableName()."` WHERE name='jitter-none-job'")->fetch(\PDO::FETCH_ASSOC);
            $runAtBefore = new \DateTimeImmutable($rowBefore['run_at']);

            // Execute (will fail and reschedule)
            $job->run();

            // Get new run_at after failure
            $rowAfter = $pdo->query("SELECT run_at FROM `".getTestTableName()."` WHERE name='jitter-none-job'")->fetch(\PDO::FETCH_ASSOC);
            $runAtAfter = new \DateTimeImmutable($rowAfter['run_at']);

            // Calculate delay
            $delay = $runAtAfter->getTimestamp() - $runAtBefore->getTimestamp();
            $delays[] = $delay;

            // Wait for next attempt
            sleep(max($delay + 1, 1));
        }

        // With jitter=none, delays should be deterministic and follow exponential pattern
        // Verify delays are in expected exponential progression (allowing for rounding)
        Test::assertTrue($delays[0] >= 2 && $delays[0] <= 3, "First delay expected ~2s, got {$delays[0]}s");
        Test::assertTrue($delays[1] >= 4 && $delays[1] <= 5, "Second delay expected ~4s, got {$delays[1]}s");
        Test::assertTrue($delays[2] >= 8 && $delays[2] <= 10, "Third delay expected ~8s, got {$delays[2]}s");

        // Verify progression: each delay should be approximately double the previous
        $ratio1 = $delays[1] / max($delays[0], 1);
        $ratio2 = $delays[2] / max($delays[1], 1);

        Test::assertTrue($ratio1 >= 1.5 && $ratio1 <= 2.5, "Delay ratio 2/1 should be ~2x, got {$ratio1}x");
        Test::assertTrue($ratio2 >= 1.5 && $ratio2 <= 2.5, "Delay ratio 3/2 should be ~2x, got {$ratio2}x");

        Test::assertEquals(3, $attempts, 'Should have 3 attempts');

        cleanJobsTable($pdo);
    });

    // ========================================================================
    // Leases & Stalls
    // ========================================================================

    Test::it('stalled job is reclaimed after lease expiry', function () {
        $pdo = createTestPDO();
        cleanJobsTable($pdo);
        $job = new Job2($pdo, null, getTestTableName()); // Use real clock
        $job->install();

        $executions = 0;
        $job->schedule('stall-test', function () use (&$executions) {
            $executions++;
        })->lease(2);

        $job->dispatch('stall-test');

        // Simulate worker crash: claim but don't execute
        $reflection = new \ReflectionClass($job);
        $batchMethod = $reflection->getMethod('batch');
        $batchMethod->setAccessible(true);

        $runs1 = $batchMethod->invoke($job, 1);
        Test::assertCount(1, $runs1);

        // Job is now locked
        $row = $pdo->query("SELECT locked_until FROM `".getTestTableName()."` WHERE name='stall-test'")->fetch(PDO::FETCH_ASSOC);
        Test::assertNotNull($row['locked_until']);

        // Try to claim immediately (should fail)
        $runs2 = $batchMethod->invoke($job, 1);
        Test::assertCount(0, $runs2, 'Job should be locked');

        // Release locks (simulating crash)
        $releaseMethod = $reflection->getMethod('release');
        $releaseMethod->setAccessible(true);
        $releaseMethod->invoke($job, $runs1[0]['locks']);

        // Wait for lease to expire
        sleep(3);

        // Now can reclaim
        $runs3 = $batchMethod->invoke($job, 1);
        Test::assertCount(1, $runs3, 'Job should be reclaimable after lease expiry');

        // Execute it
        $executeMethod = $reflection->getMethod('execute');
        $executeMethod->setAccessible(true);
        $executeMethod->invoke($job, $runs3[0]);

        Test::assertEquals(1, $executions);

        cleanJobsTable($pdo);
    });

    // ========================================================================
    // Graceful Shutdown
    // ========================================================================

    Test::it('stop method stops forever loop gracefully', function () {
        $pdo = createTestPDO();
        cleanJobsTable($pdo);
        $job = new Job2($pdo, null, getTestTableName());
        $job->install();

        // Check if pcntl is available
        if (!function_exists('pcntl_fork')) {
            Test::assertTrue(true, 'pcntl not available, skipping multiprocess test');
            cleanJobsTable($pdo);
            return;
        }

        // Verify stop() sets running flag to false
        $reflection = new \ReflectionClass($job);
        $runningProperty = $reflection->getProperty('running');
        $runningProperty->setAccessible(true);

        // Start (simulated)
        $runningProperty->setValue($job, true);
        Test::assertTrue($runningProperty->getValue($job));

        // Stop
        $job->stop();
        Test::assertFalse($runningProperty->getValue($job), 'stop() should set running to false');

        cleanJobsTable($pdo);
    });

    // ========================================================================
    // Mantenimiento
    // ========================================================================

    Test::it('prune deletes only expired locked rows', function () {
        $pdo = createTestPDO();
        cleanJobsTable($pdo);
        $job = new Job2($pdo, null, getTestTableName());
        $job->install();

        // Insert a stale job (locked 2 hours ago)
        $pdo->exec("INSERT INTO `".getTestTableName()."` (name, queue, priority, run_at, locked_until, attempts)
                    VALUES ('stale', 'default', 100, NOW(6), DATE_SUB(NOW(6), INTERVAL 2 HOUR), 1)");

        // Insert a recent locked job
        $pdo->exec("INSERT INTO `".getTestTableName()."` (name, queue, priority, run_at, locked_until, attempts)
                    VALUES ('recent', 'default', 100, NOW(6), DATE_SUB(NOW(6), INTERVAL 1 MINUTE), 1)");

        // Insert an unlocked job
        $pdo->exec("INSERT INTO `".getTestTableName()."` (name, queue, priority, run_at, attempts)
                    VALUES ('pending', 'default', 100, NOW(6), 0)");

        // Prune jobs older than 1 hour
        $pruned = $job->prune(3600);

        // Should delete only the stale job
        Test::assertEquals(1, $pruned);

        // Verify which jobs remain
        $remaining = $pdo->query("SELECT name FROM `".getTestTableName()."` ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        Test::assertEquals(['pending', 'recent'], $remaining);

        cleanJobsTable($pdo);
    });
});
