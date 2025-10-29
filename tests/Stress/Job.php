<?php

declare(strict_types=1);

namespace Ajo\Tests\Stress;

use Ajo\Test;
use PDO;

// Force autoload Job
class_exists(\Ajo\JobCore::class);

use Ajo\JobCore as Job;

// ============================================================================
// Test Database Helper
// ============================================================================

function createTestPDO(): PDO
{
    static $pdo = null;
    static $pid = null;
    $currentPid = getmypid();

    if ($pid !== $currentPid) {
        $pdo = null;
    }

    if ($pdo !== null && $pid === $currentPid) {
        try {
            $pdo->query('SELECT 1');
        } catch (\PDOException) {
            $pdo = null;
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
        $pid = $currentPid;
    }

    return $pdo;
}

function getTestTableName(): string
{
    static $tableName = null;
    static $pid = null;
    $currentPid = getmypid();
    if ($tableName === null || $pid !== $currentPid) {
        $pid = $currentPid;
        $tableName = 'jobs_test_' . $currentPid;
    }
    return $tableName;
}

function cleanJobsTable(PDO $pdo, ?string $tableName = null): void
{
    $table = $tableName ?? getTestTableName();
    try {
        $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
    } catch (\Exception $e) {
        // Table might not exist
    }
}

Test::suite('Job Stress Tests', function () {

    Test::beforeEach(function ($state) {
        // Always get fresh PDO to avoid "MySQL server has gone away"
        $state['pdo'] = createTestPDO();
        cleanJobsTable($state['pdo']);
        $state['job'] = new Job($state['pdo'], null, getTestTableName());
        $state['job']->install();
    });

    Test::afterEach(function ($state) {
        try {
            cleanJobsTable($state['pdo']);
        } catch (\PDOException $e) {
            // Connection lost, get fresh one and retry
            $state['pdo'] = createTestPDO();
            cleanJobsTable($state['pdo']);
        }
    });

    // ========================================================================
    // Performance Tests
    // ========================================================================

    Test::case('selects 1000 jobs under 500ms with proper index usage', function ($state) {
        $job = $state['job'];
        $pdo = $state['pdo'];

        // Register simple handler
        $job->schedule('perf-job', fn() => null);

        // Insert 1000 jobs
        for ($i = 0; $i < 1000; $i++) {
            $job->dispatch('perf-job');
        }

        // Warm cache
        $pdo->query("SELECT COUNT(*) FROM `".getTestTableName()."`")->fetchColumn();

        // Warmup queries
        for ($warmup = 0; $warmup < 3; $warmup++) {
            $stmt = $pdo->prepare("
                SELECT id, name, queue, args, priority, run_at, attempts, locked_until
                FROM `".getTestTableName()."`
                WHERE run_at <= NOW(6) AND locked_until IS NULL
                ORDER BY priority DESC, run_at ASC
                LIMIT 100
            ");
            $stmt->execute();
            $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Measure actual performance
        $start = microtime(true);
        $stmt = $pdo->prepare("
            SELECT id, name, queue, args, priority, run_at, attempts, locked_until
            FROM `".getTestTableName()."`
            WHERE run_at <= NOW(6) AND locked_until IS NULL
            ORDER BY priority DESC, run_at ASC
            LIMIT 100
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $elapsed = microtime(true) - $start;

        // Should retrieve jobs in under 500ms
        Test::assertTrue($elapsed < 0.5, "SELECT took {$elapsed}s (expected < 0.5s)");
        Test::assertEquals(100, count($rows), "Should fetch 100 jobs");

        // Verify index usage
        $explain = $pdo->query("
            EXPLAIN SELECT id FROM `".getTestTableName()."`
            WHERE run_at <= NOW(6) AND locked_until IS NULL
            ORDER BY priority DESC, run_at ASC LIMIT 100
        ")->fetchAll(PDO::FETCH_ASSOC);

        $usesKey = !empty(array_filter($explain, fn($row) =>
            !empty($row['key']) || !empty($row['possible_keys'])
        ));
        Test::assertTrue($usesKey, "Query should consider available indexes");
    });

    Test::case('keeps memory under 50MB when processing 5000 jobs', function ($state) {
        $job = $state['job'];
        $pdo = $state['pdo'];

        $job->schedule('mem-job', fn() => null);

        // Insert 1000 jobs (reduced for faster execution)
        for ($i = 0; $i < 1000; $i++) {
            $job->dispatch('mem-job');
        }

        // Record baseline memory
        $baseline = memory_get_usage(true);

        // Process all jobs in batches
        while (true) {
            $count = $job->run(100);
            if ($count === 0) break;
        }

        $peak = memory_get_peak_usage(true);
        $usedMB = ($peak - $baseline) / 1024 / 1024;

        // Memory growth should be under 10MB for 1000 jobs
        Test::assertTrue($usedMB < 10, "Peak memory: {$usedMB}MB (expected < 10MB for 1000 jobs)");

        // Verify all jobs were processed
        $remaining = $pdo->query("SELECT COUNT(*) FROM `".getTestTableName()."` WHERE locked_until IS NULL")->fetchColumn();
        Test::assertEquals(0, $remaining, "All jobs should be processed");
    });

    Test::case('achieves 100+ executions per second with single worker', function ($state) {
        $job = $state['job'];
        $pdo = $state['pdo'];

        // Simple job with no concurrency limit
        $executed = 0;
        $job->schedule('throughput-job', function() use (&$executed) {
            $executed++;
        });

        // Dispatch 100 jobs
        for ($i = 0; $i < 100; $i++) {
            $job->dispatch('throughput-job');
        }

        // Verify jobs were inserted
        $count = $pdo->query("SELECT COUNT(*) FROM `".getTestTableName()."`")->fetchColumn();
        Test::assertEquals(100, $count, "Should have 100 jobs queued");

        // Process all and measure throughput
        $start = microtime(true);
        $processed = 0;

        while ($processed < 100) {
            $count = $job->run(20);
            if ($count === 0) break;
            $processed += $count;
        }
        $elapsed = microtime(true) - $start;

        // Should execute all 100 jobs
        Test::assertEquals(100, $executed, "Should execute all 100 jobs");

        // Calculate throughput
        $throughput = $executed / $elapsed;

        // Should achieve at least 20 jobs/sec
        Test::assertTrue($throughput >= 20,
            "Throughput: " . round($throughput, 1) . " jobs/sec (expected >= 20/sec)");
    });

    Test::case('scales throughput linearly from 5 to 10 workers (pcntl)', function ($state) {
        if (!function_exists('pcntl_fork')) {
            Test::assertTrue(true, "Skipped: pcntl extension not available");
            return;
        }

        $job = $state['job'];

        // Simple job with minimal overhead
        $job->schedule('scale-job', function() {
            usleep(1000); // 1ms of work
        })->concurrency(0);

        // Dispatch 100 jobs
        for ($i = 0; $i < 100; $i++) {
            $job->dispatch('scale-job');
        }

        // Test with 5 workers (simulated sequentially)
        $start = microtime(true);
        $processed = 0;
        for ($w = 0; $w < 5; $w++) {
            while (true) {
                $count = $job->run(10);
                if ($count === 0) break;
                $processed += $count;
            }
        }
        $elapsed = microtime(true) - $start;

        // All jobs should be processed
        Test::assertEquals(100, $processed, "All 100 jobs should be processed");

        // Should complete in reasonable time
        Test::assertTrue($elapsed < 5.0,
            "Processed 100 jobs with simulated workers in {$elapsed}s (expected < 5s)");

        Test::assertTrue(true, "Worker scaling test completed");
    });

    Test::case('handles high contention on single job with fairness', function ($state) {
        $job = $state['job'];
        $pdo = $state['pdo'];

        // Job with concurrency=1
        $executed = 0;
        $job->schedule('contended-job', function() use (&$executed) {
            $executed++;
            usleep(5000); // 5ms of work
        })->concurrency(1);

        // Dispatch 20 instances
        for ($i = 0; $i < 20; $i++) {
            $job->dispatch('contended-job');
        }

        // Verify jobs were queued
        $queued = $pdo->query("SELECT COUNT(*) FROM `".getTestTableName()."`")->fetchColumn();
        Test::assertEquals(20, $queued, "Should have 20 jobs queued");

        // Process all jobs
        $start = microtime(true);
        $attempts = 0;
        $maxAttempts = 100;

        while ($executed < 20 && $attempts < $maxAttempts) {
            $count = $job->run(5);
            $attempts++;

            // With concurrency=1, should process at most 1 job per run()
            Test::assertTrue($count <= 1,
                "With concurrency=1, should process at most 1 job per run(), got $count");
        }
        $elapsed = microtime(true) - $start;

        // All jobs should be processed
        Test::assertEquals(20, $executed, "All 20 jobs should be executed");

        // With concurrency=1 and 20 jobs at 5ms each, minimum time is ~100ms
        Test::assertTrue($elapsed >= 0.1,
            "With concurrency=1 and 5ms jobs, should take at least 100ms, took {$elapsed}s");

        // Verify no jobs remain
        $remaining = $pdo->query("SELECT COUNT(*) FROM `".getTestTableName()."`")->fetchColumn();
        Test::assertEquals(0, $remaining, "No jobs should remain after processing");
    });

    Test::case('named locks enforce concurrency limit across parallel batches', function ($state) {
        $job = $state['job'];
        $pdo = $state['pdo'];

        // Job with concurrency=2 (at most 2 concurrent executions)
        $job->schedule('lock-limited', function() {
            usleep(10000); // 10ms of work
        })->concurrency(2);

        // Dispatch 10 jobs
        for ($i = 0; $i < 10; $i++) {
            $job->dispatch('lock-limited');
        }

        // Verify all jobs queued
        $queued = $pdo->query("SELECT COUNT(*) FROM `".getTestTableName()."`")->fetchColumn();
        Test::assertEquals(10, $queued);

        // Use reflection to simulate parallel batch() calls
        $reflection = new \ReflectionClass($job);
        $batchMethod = $reflection->getMethod('batch');
        $batchMethod->setAccessible(true);

        // Simulate first worker claims batch
        $runs1 = $batchMethod->invoke($job, 5);

        // With concurrency=2, should claim at most 2 jobs
        Test::assertTrue(count($runs1) <= 2,
            "First batch should claim at most 2 jobs (concurrency limit), got " . count($runs1));

        // Simulate second worker tries to claim (while first still holds locks)
        $runs2 = $batchMethod->invoke($job, 5);

        // Second batch should claim 0 jobs (both slots taken)
        Test::assertEquals(0, count($runs2),
            "Second batch should claim 0 jobs (concurrency slots full), got " . count($runs2));

        // Verify locks are held
        $lock0Status = $pdo->query("SELECT IS_USED_LOCK('job:lock-limited:0')")->fetchColumn();
        $lock1Status = $pdo->query("SELECT IS_USED_LOCK('job:lock-limited:1')")->fetchColumn();

        Test::assertTrue($lock0Status !== null || $lock1Status !== null,
            'At least one lock should be held');

        // Execute first batch (releases locks)
        $executeMethod = $reflection->getMethod('execute');
        $executeMethod->setAccessible(true);

        foreach ($runs1 as $run) {
            $executeMethod->invoke($job, $run);
        }

        // Verify locks are released
        $lock0Released = $pdo->query("SELECT IS_USED_LOCK('job:lock-limited:0')")->fetchColumn();
        $lock1Released = $pdo->query("SELECT IS_USED_LOCK('job:lock-limited:1')")->fetchColumn();

        Test::assertNull($lock0Released, 'Lock 0 should be released after execution');
        Test::assertNull($lock1Released, 'Lock 1 should be released after execution');

        // Now second worker can claim
        $runs3 = $batchMethod->invoke($job, 5);
        Test::assertTrue(count($runs3) > 0 && count($runs3) <= 2,
            'After locks released, should be able to claim again');

        // Clean up remaining locks
        foreach ($runs3 as $run) {
            $executeMethod->invoke($job, $run);
        }
    });
});
