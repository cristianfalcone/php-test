<?php

declare(strict_types=1);

namespace Ajo\Tests\Unit;

use DateTimeImmutable;
use RuntimeException;
use ReflectionClass;
use PDO;

// Force autoload JobCore to make Cron and Clock available
class_exists(\Ajo\JobCore::class);

use Ajo\Test;
use Ajo\JobCore as Job;
use Ajo\Cron;
use Ajo\Clock;
use Ajo\Database;

// ============================================================================
// Test Clock for deterministic time control
// ============================================================================

final class FakeClock implements Clock
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

Test::suite('Job Unit Tests', function () {

    Test::beforeEach(function ($state) {
        $pdo = Database::get();
        // Use PID to ensure unique table names across parallel processes
        $table = 'jobs_test_' . getmypid() . '_' . bin2hex(random_bytes(4));
        $job = new Job($pdo, new FakeClock(), $table);
        $job->install();

        $state['pdo'] = $pdo;
        $state['table'] = $table;
    });

    Test::afterEach(function ($state) {
        $state['pdo']->exec("DROP TABLE IF EXISTS `{$state['table']}`");
    });

    // ========================================================================
    // Cron Parsing
    // ========================================================================

    Test::case('parses 5 and 6 field cron expressions', function () {
        // 5 fields normalized to 6 (prepend 0) - this is done by Job::cron()
        // but we test Cron2::parse() directly with 6 fields
        $cron5Normalized = Cron::parse('0 */15 * * * *'); // Simulating what Job::cron() does
        Test::assertEquals([0], $cron5Normalized['sec']['list']);
        Test::assertEquals(range(0, 59, 15), $cron5Normalized['min']['list']);

        // 6 fields should parse directly
        $cron6 = Cron::parse('*/10 */15 * * * *');
        Test::assertEquals(range(0, 59, 10), $cron6['sec']['list']);
        Test::assertEquals(range(0, 59, 15), $cron6['min']['list']);

        // DOW normalization: 7 -> 0 (Sunday)
        $cronDow7 = Cron::parse('0 0 0 * * 7');
        Test::assertContains(0, $cronDow7['dow']['list']);
        Test::assertFalse(in_array(7, $cronDow7['dow']['list'], true), 'DOW 7 should be normalized to 0');
    });

    Test::case('supports ranges, steps, and lists in cron expressions', function () {
        // Range with step: 1-10/2 -> 1,3,5,7,9
        $cron = Cron::parse('0 1-10/2 * * * *');
        Test::assertEquals([1, 3, 5, 7, 9], $cron['min']['list']);

        // List: 1,2,40
        $cron = Cron::parse('0 1,2,40 * * * *');
        Test::assertEquals([1, 2, 40], $cron['min']['list']);

        // Step from *: */5 -> 0,5,10,...55
        $cron = Cron::parse('*/5 * * * * *');
        Test::assertEquals(range(0, 59, 5), $cron['sec']['list']);

        // No duplicates and sorted
        $cron = Cron::parse('0 5,1,5,3,1 * * * *');
        Test::assertEquals([1, 3, 5], $cron['min']['list']);
    });

    Test::case('handles DOM vs DOW semantics correctly', function () {
        // Both * -> always match (when sec/min/hour/mon match)
        $cronBothStar = Cron::parse('0 0 0 * * *');
        Test::assertTrue($cronBothStar['domStar']);
        Test::assertTrue($cronBothStar['dowStar']);

        $timestamp = new DateTimeImmutable('2025-01-15 00:00:00'); // Wednesday
        Test::assertTrue(Cron::matches($cronBothStar, $timestamp));

        // Only DOM specified (DOW is *) -> match by DOM only
        $cronDom15 = Cron::parse('0 0 0 15 * *');
        Test::assertFalse($cronDom15['domStar']);
        Test::assertTrue($cronDom15['dowStar']);
        Test::assertTrue(Cron::matches($cronDom15, new DateTimeImmutable('2025-01-15 00:00:00')));
        Test::assertFalse(Cron::matches($cronDom15, new DateTimeImmutable('2025-01-16 00:00:00')));

        // Only DOW specified (DOM is *) -> match by DOW only
        $cronWed = Cron::parse('0 0 0 * * 3'); // Wednesday
        Test::assertTrue($cronWed['domStar']);
        Test::assertFalse($cronWed['dowStar']);
        Test::assertTrue(Cron::matches($cronWed, new DateTimeImmutable('2025-01-15 00:00:00'))); // Wed
        Test::assertFalse(Cron::matches($cronWed, new DateTimeImmutable('2025-01-16 00:00:00'))); // Thu

        // Both specified -> DOM OR DOW
        $cronBoth = Cron::parse('0 0 0 1 * 1'); // 1st of month OR Monday
        Test::assertFalse($cronBoth['domStar']);
        Test::assertFalse($cronBoth['dowStar']);
        Test::assertTrue(Cron::matches($cronBoth, new DateTimeImmutable('2025-01-01 00:00:00'))); // 1st (Thu)
        Test::assertTrue(Cron::matches($cronBoth, new DateTimeImmutable('2025-01-06 00:00:00'))); // Monday (6th)
        Test::assertFalse(Cron::matches($cronBoth, new DateTimeImmutable('2025-01-07 00:00:00'))); // 7th (Tue)
    });

    Test::case('advances nextMatch across month boundaries', function () {
        // Monthly: 0 0 0 1 * * (first day of each month at midnight)
        $cronMonthly = Cron::parse('0 0 0 1 * *');

        $from = new DateTimeImmutable('2025-01-31 23:59:59');
        $next = Cron::nextMatch($cronMonthly, $from);

        Test::assertEquals('2025-02-01 00:00:00', $next->format('Y-m-d H:i:s'));

        // From February (28 days) to March
        $from = new DateTimeImmutable('2025-02-28 23:59:59');
        $next = Cron::nextMatch($cronMonthly, $from);

        Test::assertEquals('2025-03-01 00:00:00', $next->format('Y-m-d H:i:s'));

        // Test 5-year overflow protection
        $cronNever = Cron::parse('0 0 0 31 2 *'); // Feb 31 (never exists)

        try {
            Cron::nextMatch($cronNever, new DateTimeImmutable('2025-01-01 00:00:00'));
            Test::assertTrue(false, 'Should have thrown RuntimeException');
        } catch (RuntimeException $e) {
            Test::assertStringContainsString('overflow', $e->getMessage());
        }
    });

    // ========================================================================
    // Cron Matching
    // ========================================================================

    Test::case('matches with second precision', function () {
        $cron = Cron::parse('*/5 * * * * *'); // Every 5 seconds

        Test::assertTrue(Cron::matches($cron, new DateTimeImmutable('2025-01-15 10:30:00')));
        Test::assertTrue(Cron::matches($cron, new DateTimeImmutable('2025-01-15 10:30:05')));
        Test::assertTrue(Cron::matches($cron, new DateTimeImmutable('2025-01-15 10:30:10')));

        Test::assertFalse(Cron::matches($cron, new DateTimeImmutable('2025-01-15 10:30:01')));
        Test::assertFalse(Cron::matches($cron, new DateTimeImmutable('2025-01-15 10:30:03')));
    });

    Test::case('uses nextMatch to find next occurrence', function () {
        $cronEvery10Sec = Cron::parse('*/10 * * * * *');

        $from = new DateTimeImmutable('2025-01-15 10:30:07');
        $next = Cron::nextMatch($cronEvery10Sec, $from);

        Test::assertEquals('2025-01-15 10:30:10', $next->format('Y-m-d H:i:s'));

        // Next after that
        $next2 = Cron::nextMatch($cronEvery10Sec, $next);
        Test::assertEquals('2025-01-15 10:30:20', $next2->format('Y-m-d H:i:s'));
    });

    Test::case('matches complex time patterns', function () {
        // Weekdays at 9am
        $cronWeekdays9am = Cron::parse('0 0 9 * * 1-5');

        Test::assertTrue(Cron::matches($cronWeekdays9am, new DateTimeImmutable('2025-01-13 09:00:00'))); // Mon
        Test::assertTrue(Cron::matches($cronWeekdays9am, new DateTimeImmutable('2025-01-17 09:00:00'))); // Fri
        Test::assertFalse(Cron::matches($cronWeekdays9am, new DateTimeImmutable('2025-01-18 09:00:00'))); // Sat
        Test::assertFalse(Cron::matches($cronWeekdays9am, new DateTimeImmutable('2025-01-13 10:00:00'))); // Mon 10am
    });

    Test::case('handles edge cases in matching', function () {
        // Last second of the year
        $cronEverySecond = Cron::parse('* * * * * *');
        Test::assertTrue(Cron::matches($cronEverySecond, new DateTimeImmutable('2024-12-31 23:59:59')));

        // First second of the year
        Test::assertTrue(Cron::matches($cronEverySecond, new DateTimeImmutable('2025-01-01 00:00:00')));

        // Leap year Feb 29
        $cronFeb29 = Cron::parse('0 0 0 29 2 *');
        Test::assertTrue(Cron::matches($cronFeb29, new DateTimeImmutable('2024-02-29 00:00:00'))); // 2024 is leap
        Test::assertFalse(Cron::matches($cronFeb29, new DateTimeImmutable('2025-02-28 00:00:00'))); // 2025 not leap
    });

    // ========================================================================
    // DSL & Job Definition
    // ========================================================================

    Test::case('schedule same name twice switches focus', function ($state) {
        $job = new Job($state['pdo'], new FakeClock(new DateTimeImmutable('2025-01-15 10:30:45')), $state['table']);

        // First schedule
        $job->schedule('test-job', fn() => 'first');

        // Second schedule with same name - should switch focus, not error
        $job->schedule('test-job', fn() => 'second');

        // Use reflection to verify the job exists and task was updated
        $reflection = new ReflectionClass($job);
        $jobsProperty = $reflection->getProperty('jobs');
        $jobsProperty->setAccessible(true);
        $jobs = $jobsProperty->getValue($job);

        Test::assertArrayHasKey('test-job', $jobs);
        Test::assertNotNull($jobs['test-job']['task']);

        // Task should be updated to the second one
        Test::assertEquals('second', ($jobs['test-job']['task'])());
    });

    Test::case('fluent modifiers override defaults', function ($state) {
        $job = new Job($state['pdo'], new FakeClock(), $state['table']);

        $handler = fn() => null;
        $job->schedule('custom-job', $handler)
            ->queue('high-priority')
            ->priority(50)
            ->lease(120)
            ->concurrency(5)
            ->retries(3, 2, 120, 'full');

        // Use reflection to access private $jobs property
        $reflection = new ReflectionClass($job);
        $jobsProperty = $reflection->getProperty('jobs');
        $jobsProperty->setAccessible(true);
        $jobs = $jobsProperty->getValue($job);

        Test::assertArrayHasKey('custom-job', $jobs);
        $jobDef = $jobs['custom-job'];

        Test::assertEquals('high-priority', $jobDef['queue']);
        Test::assertEquals(50, $jobDef['priority']);
        Test::assertEquals(120, $jobDef['lease']);
        Test::assertEquals(5, $jobDef['concurrency']);
        Test::assertEquals(3, $jobDef['maxAttempts']);
        Test::assertEquals(2, $jobDef['backoffBase']);
        Test::assertEquals(120, $jobDef['backoffCap']);
        Test::assertEquals('full', $jobDef['jitter']);
    });

    Test::case('cron helpers map to correct expressions', function ($state) {
        $job = new Job($state['pdo'], new FakeClock(), $state['table']);

        $job->schedule('every-second', fn() => null)->everySecond();
        $job->schedule('every-five-seconds', fn() => null)->everyFiveSeconds();
        $job->schedule('every-minute', fn() => null)->everyMinute();
        $job->schedule('hourly', fn() => null)->hourly();
        $job->schedule('daily', fn() => null)->daily();
        $job->schedule('weekly', fn() => null)->weekly();
        $job->schedule('weekdays', fn() => null)->weekdays();
        $job->schedule('mondays', fn() => null)->mondays();

        $reflection = new ReflectionClass($job);
        $jobsProperty = $reflection->getProperty('jobs');
        $jobsProperty->setAccessible(true);
        $jobs = $jobsProperty->getValue($job);

        Test::assertEquals('* * * * * *', $jobs['every-second']['cron']);
        Test::assertEquals('*/5 * * * * *', $jobs['every-five-seconds']['cron']);
        Test::assertEquals('0 * * * * *', $jobs['every-minute']['cron']);
        Test::assertEquals('0 0 * * * *', $jobs['hourly']['cron']);
        Test::assertEquals('0 0 0 * * *', $jobs['daily']['cron']);
        Test::assertEquals('0 0 0 * * 0', $jobs['weekly']['cron']);
        Test::assertEquals('0 0 0 * * 1-5', $jobs['weekdays']['cron']);
        Test::assertEquals('0 0 0 * * 1', $jobs['mondays']['cron']);
    });

    Test::case('time limiters modify cron fields', function ($state) {
        $job = new Job($state['pdo'], new FakeClock(), $state['table']);

        $job->schedule('daily-at-9am', fn() => null)
            ->daily()
            ->hours(9)
            ->minutes(30)
            ->seconds(15);

        $reflection = new ReflectionClass($job);
        $jobsProperty = $reflection->getProperty('jobs');
        $jobsProperty->setAccessible(true);
        $jobs = $jobsProperty->getValue($job);

        Test::assertEquals('15 30 9 * * *', $jobs['daily-at-9am']['cron']);

        // Test with arrays
        $job->schedule('multi-times', fn() => null)
            ->everyMinute()
            ->seconds([0, 15, 30, 45]);

        $jobs = $jobsProperty->getValue($job);
        Test::assertEquals('0,15,30,45 * * * * *', $jobs['multi-times']['cron']);
    });

    // ========================================================================
    // Backoff Calculation
    // ========================================================================

    Test::case('backoff uses full jitter within bounds', function ($state) {
        $job = new Job($state['pdo'], new FakeClock(), $state['table']);

        // Use reflection to access backoffDelay
        $reflection = new ReflectionClass($job);
        $backoffMethod = $reflection->getMethod('backoffDelay');
        $backoffMethod->setAccessible(true);

        $config = [
            'backoffBase' => 2,
            'backoffCap' => 60,
            'jitter' => 'full'
        ];

        // Test multiple attempts
        $results = [];
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            // Run multiple times to verify it's within bounds
            for ($i = 0; $i < 20; $i++) {
                $delay = $backoffMethod->invoke($job, $config, $attempt);
                $max = min($config['backoffCap'], $config['backoffBase'] * (1 << max(0, $attempt - 1)));

                Test::assertTrue($delay >= 0, "Delay should be >= 0, got $delay");
                Test::assertTrue($delay <= $max, "Delay should be <= $max, got $delay for attempt $attempt");

                $results[$attempt][] = $delay;
            }
        }

        // Verify progression: attempt 1 (0-2), attempt 2 (0-4), attempt 3 (0-8), etc.
        Test::assertTrue(max($results[1]) <= 2);
        Test::assertTrue(max($results[2]) <= 4);
        Test::assertTrue(max($results[3]) <= 8);
        Test::assertTrue(max($results[4]) <= 16);
        Test::assertTrue(max($results[5]) <= 32);
    });

    Test::case('backoff respects cap limit', function ($state) {
        $job = new Job($state['pdo'], new FakeClock(), $state['table']);

        $reflection = new ReflectionClass($job);
        $backoffMethod = $reflection->getMethod('backoffDelay');
        $backoffMethod->setAccessible(true);

        $config = [
            'backoffBase' => 10,
            'backoffCap' => 30,
            'jitter' => 'full'
        ];

        // Attempt 10: base * 2^9 = 10 * 512 = 5120, but cap is 30
        for ($i = 0; $i < 50; $i++) {
            $delay = $backoffMethod->invoke($job, $config, 10);
            Test::assertTrue($delay <= 30, "Delay should not exceed cap of 30, got $delay");
            Test::assertTrue($delay >= 0, "Delay should be >= 0, got $delay");
        }
    });

    Test::case('backoff with jitter=none returns exact exponential delay', function ($state) {
        $job = new Job($state['pdo'], new FakeClock(), $state['table']);

        $reflection = new ReflectionClass($job);
        $backoffMethod = $reflection->getMethod('backoffDelay');
        $backoffMethod->setAccessible(true);

        $config = [
            'backoffBase' => 2,
            'backoffCap' => 60,
            'jitter' => 'none'
        ];

        // Test multiple attempts to verify deterministic behavior (no randomness)
        $expectedDelays = [
            1 => 2,   // 2 * 2^0 = 2
            2 => 4,   // 2 * 2^1 = 4
            3 => 8,   // 2 * 2^2 = 8
            4 => 16,  // 2 * 2^3 = 16
            5 => 32,  // 2 * 2^4 = 32
            6 => 60,  // 2 * 2^5 = 64, but capped at 60
            7 => 60,  // Still capped
            10 => 60, // Still capped
        ];

        foreach ($expectedDelays as $attempt => $expectedDelay) {
            // Call multiple times to verify it's always the same (no jitter)
            for ($i = 0; $i < 5; $i++) {
                $delay = $backoffMethod->invoke($job, $config, $attempt);
                Test::assertEquals($expectedDelay, $delay,
                    "Attempt $attempt should have delay of {$expectedDelay}s (no jitter), got {$delay}s");
            }
        }
    });
});
