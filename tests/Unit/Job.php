<?php

declare(strict_types=1);

namespace Ajo\Tests\Unit\Jobs;

use Ajo\Console;
use Ajo\Container;
use Ajo\Core\Job as CoreJob;
use Ajo\Job;
use Ajo\Test;
use DateTimeImmutable;
use PDO;
use PDOStatement;
use ReflectionClass;
use RuntimeException;
use Throwable;
use function Ajo\Tests\Support\Console\dispatch;
use function Ajo\Tests\Support\Console\silence;

Test::suite('Job', function () {

    Test::beforeEach(function ($state): void {
        Container::clear();

        $pdo = new MysqlForSqlitePDO();
        Container::set('db', $pdo);

        $state['pdo'] = $pdo;

        resetSingleton();
    });

    Test::afterEach(function ($state): void {
        Container::clear();
        resetSingleton();
        unset($state['pdo']);
    });

    Test::it('should add job commands on register', function () {
        $cli = Console::create();
        $jobs = Job::register($cli);

        Test::assertInstanceOf(CoreJob::class, $jobs);

        $commands = $cli->commands();

        foreach (['jobs:install', 'jobs:status', 'jobs:collect', 'jobs:work', 'jobs:prune'] as $command) {
            Test::assertArrayHasKey($command, $commands, sprintf('Command %s was not registered.', $command));
        }
    });

    Test::it('should add job with defaults on schedule', function () {
        $handler = fn() => null;

        Job::schedule('my-job', $handler)->everyFiveMinutes();

        $jobs = jobs();

        Test::assertCount(1, $jobs);
        Test::assertArrayHasKey('my-job', $jobs);

        $job = $jobs['my-job'];

        Test::assertSame('0 */5 * * * *', $job['cron']);
        Test::assertSame('default', $job['queue']);
        Test::assertSame(1, $job['concurrency']);
        Test::assertSame(100, $job['priority']);
        Test::assertSame(3600, $job['lease']);
        Test::assertSame($handler, $job['handler']);
        // hasSeconds field removed - all cron expressions are 6-field now
    });

    Test::it('should use frequency helpers via __call', function () {
        Job::schedule('daily-job', fn() => null)->daily();
        Job::schedule('hourly-job', fn() => null)->hourly();
        Job::schedule('weekly-job', fn() => null)->weekly();

        $jobs = jobs();

        Test::assertSame('0 0 0 * * *', $jobs['daily-job']['cron']);
        Test::assertSame('0 0 * * * *', $jobs['hourly-job']['cron']);
        Test::assertSame('0 0 0 * * 0', $jobs['weekly-job']['cron']);
    });

    Test::it('should modify time with hour() and minute() methods', function () {
        Job::schedule('daily-at-9am', fn() => null)->daily()->hour(9);

        $job = jobs()['daily-at-9am'];

        Test::assertSame('0 0 9 * * *', $job['cron']);
    });

    Test::it('should allow explicit cron via cron() method', function () {
        Job::schedule('custom-job', fn() => null)->cron('*/15 * * * *');

        $job = jobs()['custom-job'];

        Test::assertSame('0 */15 * * * *', $job['cron']);
    });

    Test::it('should throw exception on duplicate job names', function () {
        Job::schedule('duplicate', fn() => null)->daily();

        Test::expectException(RuntimeException::class, function () {
            Job::schedule('duplicate', fn() => null)->hourly();
        }, "Job 'duplicate' is already defined.");
    });

    Test::it('should support second-based frequency helpers', function () {
        Job::schedule('every-second', fn() => null)->everySecond();
        Job::schedule('every-five-sec', fn() => null)->everyFiveSeconds();
        Job::schedule('every-thirty-sec', fn() => null)->everyThirtySeconds();

        $jobs = jobs();

        Test::assertSame('* * * * * *', $jobs['every-second']['cron']);
        Test::assertSame('*/5 * * * * *', $jobs['every-five-sec']['cron']);
        Test::assertSame('*/30 * * * * *', $jobs['every-thirty-sec']['cron']);
    });

    Test::it('should modify days of month with day() method', function () {
        Job::schedule('on-specific-days', fn() => null)
            ->daily()
            ->day([1, 15]);

        $job = jobs()['on-specific-days'];
        Test::assertSame('0 0 0 1,15 * *', $job['cron']);
    });

    Test::it('should modify months with month() method', function () {
        Job::schedule('seasonal', fn() => null)
            ->daily()
            ->month([3, 6, 9, 12]);  // Quarterly

        $job = jobs()['seasonal'];
        Test::assertSame('0 0 0 * 3,6,9,12 *', $job['cron']);
    });

    Test::it('should modify seconds with second() method', function () {
        Job::schedule('on-specific-seconds', fn() => null)
            ->everyMinute()
            ->second([0, 15, 30, 45]);

        $job = jobs()['on-specific-seconds'];
        Test::assertSame('0,15,30,45 * * * * *', $job['cron']);
    });

    Test::it('should modify minutes with minute() method', function () {
        Job::schedule('on-specific-minutes', fn() => null)
            ->hourly()
            ->minute([0, 15, 30, 45]);

        $job = jobs()['on-specific-minutes'];
        Test::assertSame('0 0,15,30,45 * * * *', $job['cron']);
    });

    Test::it('should modify hours with hour() method', function () {
        Job::schedule('on-specific-hours', fn() => null)
            ->daily()
            ->hour([9, 12, 15, 18]);

        $job = jobs()['on-specific-hours'];
        Test::assertSame('0 0 9,12,15,18 * * *', $job['cron']);
    });

    Test::it('should support single values in time constraint methods', function () {
        Job::schedule('single-second', fn() => null)->everyMinute()->second(30);
        Job::schedule('single-minute', fn() => null)->hourly()->minute(15);
        Job::schedule('single-hour', fn() => null)->daily()->hour(9);
        Job::schedule('single-day', fn() => null)->daily()->day(15);
        Job::schedule('single-month', fn() => null)->daily()->month(6);

        $jobs = jobs();

        Test::assertSame('30 * * * * *', $jobs['single-second']['cron']);
        Test::assertSame('0 15 * * * *', $jobs['single-minute']['cron']);
        Test::assertSame('0 0 9 * * *', $jobs['single-hour']['cron']);
        Test::assertSame('0 0 0 15 * *', $jobs['single-day']['cron']);
        Test::assertSame('0 0 0 * 6 *', $jobs['single-month']['cron']);
    });

    Test::it('should skip execution when skip() returns true', function ($state) {
        $cli = Console::create();
        Job::register($cli);

        $executed = false;

        Job::schedule('skippable', function () use (&$executed) {
            $executed = true;
        })
            ->everyMinute()
            ->skip(fn() => true);  // Always skip

        dispatch($cli, 'jobs:install');
        dispatch($cli, 'jobs:collect');

        Test::assertFalse($executed);
    });

    Test::it('should execute only when when() returns true', function ($state) {
        $cli = Console::create();
        Job::register($cli);

        $executedAllow = false;
        $executedDeny = false;

        Job::schedule('allowed', function () use (&$executedAllow) {
            $executedAllow = true;
        })
            ->everySecond()  // Use everySecond() to ensure job is due
            ->when(fn() => true);

        Job::schedule('denied', function () use (&$executedDeny) {
            $executedDeny = true;
        })
            ->everySecond()  // Use everySecond() to ensure job is due
            ->when(fn() => false);

        dispatch($cli, 'jobs:install');
        dispatch($cli, 'jobs:collect');

        Test::assertTrue($executedAllow);
        Test::assertFalse($executedDeny);
    });

    Test::it('should fluent modifiers override defaults', function () {
        $handler = fn() => null;

        Job::schedule('report.generate', $handler)
            ->hourly()
            ->queue('reports')
            ->concurrency(3)
            ->priority(5)
            ->lease(180);

        $job = jobs()['report.generate'];

        Test::assertSame('reports', $job['queue']);
        Test::assertSame(3, $job['concurrency']);
        Test::assertSame(5, $job['priority']);
        Test::assertSame(180, $job['lease']);
    });

    Test::it('should execute due jobs and update state on run', function ($state) {
        $cli = Console::create();
        Job::register($cli);

        $executed = 0;

        Job::schedule('alpha', function () use (&$executed) {
            $executed++;
        })->everySecond();  // Use everySecond() to ensure job is due

        dispatch($cli, 'jobs:install');
        [$exitCode, $stdout, $stderr] = dispatch($cli, 'jobs:collect');

        Test::assertSame(0, $exitCode);
        Test::assertStringContainsString('Executed 1 job(s).', $stdout);
        Test::assertSame('', $stderr);
        Test::assertSame(1, $executed);

        $row = row($state, 'alpha');

        Test::assertNotNull($row['last_run']);
        Test::assertSame(0, (int)$row['fail_count']);
        Test::assertNull($row['last_error']);
        Test::assertNull($row['lease_until']);
        Test::assertNotNull($row['seen_at']);
    });

    Test::it('should skip jobs when concurrency limit reached on run', function ($state) {
        $cli = Console::create();
        Job::register($cli);

        $alphaExecuted = false;

        Job::schedule('alpha', function () use (&$alphaExecuted) {
            $alphaExecuted = true;
        })
            ->everyMinute()
            ->queue('emails')
            ->concurrency(1);

        Job::schedule('beta', function (): void {
            Test::fail('The queue already reached the concurrency limit.');
        })
            ->everyMinute()
            ->queue('emails')
            ->concurrency(1);

        dispatch($cli, 'jobs:install');

        $now = new DateTimeImmutable('now');

        $statement = pdo($state)->prepare('UPDATE jobs SET lease_until = :l WHERE name = :n');
        $statement->execute([
            ':l' => $now->modify('+5 minutes')->format('Y-m-d H:i:s'),
            ':n' => 'alpha',
        ]);

        [$exitCode, $stdout, $stderr] = dispatch($cli, 'jobs:collect');

        Test::assertSame(0, $exitCode);
        Test::assertStringContainsString('No due jobs.', $stdout);
        Test::assertSame('', $stderr);
        Test::assertFalse($alphaExecuted);

        $betaRow = row($state, 'beta');
        Test::assertNull($betaRow['last_run']);
        Test::assertSame(0, (int)$betaRow['fail_count']);
    });

    Test::it('should record failures and leave trace on run', function ($state) {
        $cli = Console::create();
        Job::register($cli);

        Job::schedule('failing', function (): void {
            throw new RuntimeException('boom failure message');
        })->everySecond();  // Use everySecond() to ensure job is due

        dispatch($cli, 'jobs:install');
        [$exitCode, $stdout, $stderr] = dispatch($cli, 'jobs:collect');

        Test::assertSame(0, $exitCode);
        Test::assertStringContainsString('Executed 1 job(s).', $stdout);
        Test::assertStringContainsString('boom failure message', $stderr);

        $row = row($state, 'failing');

        Test::assertNotNull($row['last_run']);
        Test::assertSame('boom failure message', $row['last_error']);
        Test::assertSame(1, (int)$row['fail_count']);
        Test::assertNull($row['lease_until']);
    });

    Test::it('should match cron day combinations correctly', function () {
        $match = function (string $expr, string $date): bool {
            $parsed = \Ajo\Core\CronParser::parse($expr);

            return \Ajo\Core\CronEvaluator::matches(
                $parsed,
                new DateTimeImmutable($date),
            );
        };

        Test::assertTrue($match('0 0 1 * 0', '2024-12-01 00:00:00')); // domingo y día 1
        Test::assertFalse($match('0 0 1 * 0', '2024-12-02 00:00:00')); // lunes 2
        Test::assertTrue($match('15 10 * * 3', '2024-05-08 10:15:00'));
        Test::assertTrue($match('15 10 10 * 3', '2024-05-10 10:15:00'));
        Test::assertFalse($match('15 10 10 * 3', '2024-05-09 10:15:00'));
    });

    Test::it('should evaluate five-field cron once per minute', function () {
        // 5-field cron is converted to 6-field with second 0
        $parsed = \Ajo\Core\CronParser::parse('* * * * *'); // becomes '0 * * * * *'
        $moment = new DateTimeImmutable('2024-01-01 12:07:00'); // second 0

        $first = \Ajo\Core\CronEvaluator::evaluate($parsed, $moment, null);
        Test::assertTrue($first['due']);
        Test::assertSame('2024-01-01 12:08:00', $first['next']->format('Y-m-d H:i:s'));

        // Same minute but different second should not be due (second precision)
        $sameMinute = \Ajo\Core\CronEvaluator::evaluate(
            $parsed,
            $moment,
            new DateTimeImmutable('2024-01-01 12:07:00'),
        );

        Test::assertFalse($sameMinute['due']);
    });

    Test::it('should evaluate six-field cron once per second', function () {
        $parsed = \Ajo\Core\CronParser::parse('*/5 * * * * *');
        $moment = new DateTimeImmutable('2024-01-01 00:00:10');

        $first = \Ajo\Core\CronEvaluator::evaluate($parsed, $moment, null);
        Test::assertTrue($first['due']);
        Test::assertSame('2024-01-01 00:00:15', $first['next']->format('Y-m-d H:i:s'));

        $sameSecond = \Ajo\Core\CronEvaluator::evaluate(
            $parsed,
            $moment,
            new DateTimeImmutable('2024-01-01 00:00:10'),
        );

        Test::assertFalse($sameSecond['due']);
    });

    Test::it('should stop when stop called in forever', function () {
        $cli = Console::create();
        /** @var CoreJob $jobs */
        $jobs = Job::register($cli);

        $executed = 0;

        Job::schedule('self.stop', function () use (&$executed, $jobs) {
            $executed++;
            $jobs->stop();
        })->everySecond();  // Use everySecond() to avoid waiting for second 0

        dispatch($cli, 'jobs:install');
        silence(fn() => $jobs->forever());

        Test::assertSame(1, $executed);
    });

    Test::it('should reset queue to default when null provided', function () {
        Job::schedule('test-job', fn() => null)
            ->everyMinute()
            ->queue(null);

        $job = jobs()['test-job'];
        Test::assertSame('default', $job['queue']);
    });

    Test::it('should enforce minimum duration in lease', function () {
        Job::schedule('test-job-lease', fn() => null)
            ->everyMinute()
            ->lease(10);

        $job = jobs()['test-job-lease'];
        Test::assertSame(60, $job['lease']);
    });

    Test::it('should render registered jobs in status command', function () {
        $cli = Console::create();
        Job::register($cli);

        Job::schedule('status.job', fn() => null)->everyMinute();

        dispatch($cli, 'jobs:install');
        [$exitCode, $stdout, $stderr] = dispatch($cli, 'jobs:status');

        Test::assertSame(0, $exitCode);
        Test::assertSame('', $stderr);
        Test::assertStringContainsString('Defined: 1 | Running: 0 | Idle: 1', $stdout);
        Test::assertStringContainsString('status.job', $stdout);
    });

    Test::it('should prune unseen jobs', function ($state) {
        $cli = Console::create();
        Job::register($cli);

        Job::schedule('stale.job', fn() => null)->everyMinute();

        dispatch($cli, 'jobs:install');
        dispatch($cli, 'jobs:collect');

        $pdo = pdo($state);
        $old = '2000-01-01 00:00:00';

        $update = $pdo->prepare('UPDATE jobs SET seen_at = :old WHERE name = :name');
        $update->execute([':old' => $old, ':name' => 'stale.job']);

        $pdo->exec("
            INSERT INTO jobs (name, last_run, lease_until, last_error, fail_count, seen_at, created_at, updated_at)
            VALUES ('legacy.job', NULL, NULL, NULL, 0, '1990-01-01 00:00:00', '1990-01-01 00:00:00', '1990-01-01 00:00:00')
        ");

        [$exitCode, $stdout, $stderr] = dispatch($cli, 'jobs:prune');

        Test::assertSame(0, $exitCode);
        Test::assertSame('', $stderr);
        Test::assertStringContainsString('Pruned', $stdout);

        $staleCount = $pdo->query("SELECT COUNT(*) FROM jobs WHERE name = 'stale.job'");
        Test::assertNotFalse($staleCount);
        Test::assertSame(0, (int)$staleCount->fetchColumn());
        $staleCount->closeCursor();

        $legacyCount = $pdo->query("SELECT COUNT(*) FROM jobs WHERE name = 'legacy.job'");
        Test::assertNotFalse($legacyCount);
        Test::assertSame(0, (int)$legacyCount->fetchColumn());
        $legacyCount->closeCursor();
    });

    Test::it('should assume daily when day() or month() used without frequency', function () {
        Job::schedule('days-only', fn() => null)->day([1, 15]);
        Job::schedule('months-only', fn() => null)->month([6, 12]);
        Job::schedule('both', fn() => null)->day(5)->month(3);

        $jobs = jobs();

        // day() should set default daily (0 0 0 * * *) then modify day field
        Test::assertSame('0 0 0 1,15 * *', $jobs['days-only']['cron']);

        // month() should set default daily then modify month field
        Test::assertSame('0 0 0 * 6,12 *', $jobs['months-only']['cron']);

        // both should work: first day() sets daily + modifies day, then month() modifies month
        Test::assertSame('0 0 0 5 3 *', $jobs['both']['cron']);
    });

    Test::it('should accumulate multiple when() and skip() conditions', function ($state) {
        $cli = Console::create();
        Job::register($cli);

        $executed1 = false;
        $executed2 = false;
        $executed3 = false;
        $executed4 = false;

        // Multiple when() - all must return true
        Job::schedule('multiple-when', function () use (&$executed1) {
            $executed1 = true;
        })
            ->everySecond()  // Use everySecond() to ensure job is due
            ->queue('q1')
            ->when(fn() => true)
            ->when(fn() => true)
            ->when(fn() => true);

        // Multiple when() - one returns false, should not execute
        Job::schedule('when-fail', function () use (&$executed2) {
            $executed2 = true;
        })
            ->everySecond()  // Use everySecond() to ensure job is due
            ->queue('q2')
            ->when(fn() => true)
            ->when(fn() => false)  // This one fails
            ->when(fn() => true);

        // Multiple skip() - any can skip
        Job::schedule('multiple-skip', function () use (&$executed3) {
            $executed3 = true;
        })
            ->everySecond()  // Use everySecond() to ensure job is due
            ->queue('q3')
            ->skip(fn() => false)
            ->skip(fn() => true)   // This one skips
            ->skip(fn() => false);

        // Mixing when() and skip()
        Job::schedule('mixed', function () use (&$executed4) {
            $executed4 = true;
        })
            ->everySecond()  // Use everySecond() to ensure job is due
            ->queue('q4')
            ->when(fn() => true)
            ->skip(fn() => false)
            ->when(fn() => true);

        dispatch($cli, 'jobs:install');
        dispatch($cli, 'jobs:collect');

        Test::assertTrue($executed1, 'All when() conditions pass');
        Test::assertFalse($executed2, 'One when() fails');
        Test::assertFalse($executed3, 'One skip() returns true');
        Test::assertTrue($executed4, 'Mixed when/skip, all pass');
    });

    Test::it('should execute dispatched job immediately', function ($state) {
        $cli = Console::create();
        Job::register($cli);

        $executed = false;

        Job::schedule('test-dispatch', function () use (&$executed) {
            $executed = true;
        })->everyMinute();

        dispatch($cli, 'jobs:install');

        // Dispatch the job (enqueue it)
        Job::dispatch('test-dispatch');

        // Run should execute it immediately
        dispatch($cli, 'jobs:collect');

        Test::assertTrue($executed, 'Dispatched job should execute immediately');
    });

    Test::it('should execute ad-hoc dispatched job with handler', function ($state) {
        $cli = Console::create();
        Job::register($cli);

        $executed = false;

        dispatch($cli, 'jobs:install');

        // Dispatch ad-hoc job (not previously scheduled)
        Job::dispatch('ad-hoc-job', function () use (&$executed) {
            $executed = true;
        });

        // Run should execute it
        dispatch($cli, 'jobs:collect');

        Test::assertTrue($executed, 'Ad-hoc dispatched job should execute');
    });

    Test::it('should throw exception when dispatching non-existent job without handler', function ($state) {
        $cli = Console::create();
        Job::register($cli);

        dispatch($cli, 'jobs:install');

        $thrown = false;

        try {
            Job::dispatch('non-existent');
        } catch (RuntimeException $e) {
            $thrown = true;
            Test::assertContains('not found', $e->getMessage());
        }

        Test::assertTrue($thrown, 'Should throw exception for non-existent job');
    });

    Test::it('should reset enqueued_at after execution', function ($state) {
        $cli = Console::create();
        Job::register($cli);

        $count = 0;

        Job::schedule('test-reset', function () use (&$count) {
            $count++;
        })->everyMinute();

        dispatch($cli, 'jobs:install');

        // Dispatch and execute
        Job::dispatch('test-reset');
        dispatch($cli, 'jobs:collect');

        Test::assertEquals(1, $count, 'Should execute once');

        // Dispatch again - if enqueued_at was properly reset, it should execute again
        Job::dispatch('test-reset');
        dispatch($cli, 'jobs:collect');

        Test::assertEquals(2, $count, 'Should execute again after re-dispatch (enqueued_at was reset)');
    });

    Test::it('should allow multiple dispatches of same job', function ($state) {
        $cli = Console::create();
        Job::register($cli);

        $count = 0;

        Job::schedule('multi-dispatch', function () use (&$count) {
            $count++;
        })->everyMinute();

        dispatch($cli, 'jobs:install');

        // Dispatch 3 times (only last one will execute since they override enqueued_at)
        Job::dispatch('multi-dispatch');
        Job::dispatch('multi-dispatch');
        Job::dispatch('multi-dispatch');

        // Execute
        dispatch($cli, 'jobs:collect');

        // Should execute only once (latest dispatch)
        Test::assertEquals(1, $count, 'Should execute once per run');

        // Dispatch again after execution
        Job::dispatch('multi-dispatch');
        dispatch($cli, 'jobs:collect');

        // Should execute again
        Test::assertEquals(2, $count, 'Should execute again after re-dispatch');
    });

    Test::it('should execute onBefore hook before handler', function ($state) {
        $cli = Console::create();
        Job::register($cli);

        $order = [];

        Job::schedule('test-before', function () use (&$order) {
            $order[] = 'handler';
        })
            ->everyMinute()
            ->onBefore(function () use (&$order) {
                $order[] = 'before';
            });

        dispatch($cli, 'jobs:install');

        // Dispatch to execute immediately
        Job::dispatch('test-before');
        dispatch($cli, 'jobs:collect');

        Test::assertEquals(['before', 'handler'], $order, 'onBefore should execute before handler');
    });

    Test::it('should execute onSuccess hook after successful handler', function ($state) {
        $cli = Console::create();
        Job::register($cli);

        $order = [];

        Job::schedule('test-success', function () use (&$order) {
            $order[] = 'handler';
        })
            ->everyMinute()
            ->onSuccess(function () use (&$order) {
                $order[] = 'success';
            });

        dispatch($cli, 'jobs:install');
        Job::dispatch('test-success');
        dispatch($cli, 'jobs:collect');

        Test::assertEquals(['handler', 'success'], $order, 'onSuccess should execute after handler');
    });

    Test::it('should execute onError hook when handler throws exception', function ($state) {
        $cli = Console::create();
        Job::register($cli);

        $errorCaught = null;

        Job::schedule('test-error', function () {
            throw new RuntimeException('Test error');
        })
            ->everyMinute()
            ->onError(function (Throwable $e) use (&$errorCaught) {
                $errorCaught = $e->getMessage();
            });

        dispatch($cli, 'jobs:install');
        Job::dispatch('test-error');
        dispatch($cli, 'jobs:collect');

        Test::assertEquals('Test error', $errorCaught, 'onError should receive exception');
    });

    Test::it('should execute onAfter hook always (success case)', function ($state) {
        $cli = Console::create();
        Job::register($cli);

        $afterExecuted = false;

        Job::schedule('test-after-success', fn() => null)
            ->everyMinute()
            ->onAfter(function () use (&$afterExecuted) {
                $afterExecuted = true;
            });

        dispatch($cli, 'jobs:install');
        Job::dispatch('test-after-success');
        dispatch($cli, 'jobs:collect');

        Test::assertTrue($afterExecuted, 'onAfter should execute on success');
    });

    Test::it('should execute onAfter hook always (error case)', function ($state) {
        $cli = Console::create();
        Job::register($cli);

        $afterExecuted = false;

        Job::schedule('test-after-error', function () {
            throw new RuntimeException('Error');
        })
            ->everyMinute()
            ->onAfter(function () use (&$afterExecuted) {
                $afterExecuted = true;
            });

        dispatch($cli, 'jobs:install');
        Job::dispatch('test-after-error');
        dispatch($cli, 'jobs:collect');

        Test::assertTrue($afterExecuted, 'onAfter should execute even on error');
    });

    Test::it('should execute all hooks in correct order', function ($state) {
        $cli = Console::create();
        Job::register($cli);

        $order = [];

        Job::schedule('test-hooks-order', function () use (&$order) {
            $order[] = 'handler';
        })
            ->everyMinute()
            ->onBefore(function () use (&$order) {
                $order[] = 'before';
            })
            ->onSuccess(function () use (&$order) {
                $order[] = 'success';
            })
            ->onAfter(function () use (&$order) {
                $order[] = 'after';
            });

        dispatch($cli, 'jobs:install');
        Job::dispatch('test-hooks-order');
        dispatch($cli, 'jobs:collect');

        Test::assertEquals(['before', 'handler', 'success', 'after'], $order, 'Hooks should execute in correct order');
    });

    Test::it('should allow multiple hooks of same type', function ($state) {
        $cli = Console::create();
        Job::register($cli);

        $count = 0;

        Job::schedule('test-multiple-hooks', fn() => null)
            ->everyMinute()
            ->onBefore(function () use (&$count) {
                $count++;
            })
            ->onBefore(function () use (&$count) {
                $count++;
            })
            ->onBefore(function () use (&$count) {
                $count++;
            });

        dispatch($cli, 'jobs:install');
        Job::dispatch('test-multiple-hooks');
        dispatch($cli, 'jobs:collect');

        Test::assertEquals(3, $count, 'All hooks of same type should execute');
    });
});

/**
 * @return array<string, array{
 *     cron: ?string,
 *     queue: string,
 *     concurrency: int,
 *     priority: int,
 *     lease: int,
 *     handler: callable,
 *     parsed: ?array
 * }>
 */
function jobs(): array
{
    $instance = jobsInstance();

    $reflection = new ReflectionClass(CoreJob::class);
    $property = $reflection->getProperty('jobs');
    $property->setAccessible(true);

    /** @var array<string, array{cron:?string,queue:string,concurrency:int,priority:int,lease:int,handler:callable,parsed:?array}> $jobs */
    $jobs = $property->getValue($instance);

    return $jobs;
}

function jobsInstance(): CoreJob
{
    $instance = Job::instance();

    if (!$instance instanceof CoreJob) {
        Test::fail('Jobs instance is not available.');
    }

    return $instance;
}

function resetSingleton(): void
{
    Job::swap(new CoreJob());
}

function callPrivate(object $instance, string $method, mixed ...$args): mixed
{
    $closure = \Closure::bind(function () use ($method, $args) {
        /** @phpstan-ignore-next-line */
        return $this->{$method}(...$args);
    }, $instance, $instance);

    if (!$closure instanceof \Closure) {
        Test::fail('Could not invoke private method.');
    }

    return $closure();
}

/**
 * @return array{name:?string,lease_until:?string,last_run:?string,last_error:?string,fail_count:int,seen_at:?string,created_at:?string,updated_at:?string}
 */
function row($state, string $name): array
{
    $statement = pdo($state)->prepare('SELECT * FROM jobs WHERE name = :n');
    $statement->execute([':n' => $name]);

    $row = $statement->fetch();
    $statement->closeCursor();

    if ($row === false) {
        return [
            'name' => $name,
            'lease_until' => null,
            'last_run' => null,
            'last_error' => null,
            'fail_count' => 0,
            'seen_at' => null,
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    return $row;
}

function pdo($state): PDO
{
    $pdo = $state['pdo'] ?? null;

    if (!$pdo instanceof PDO) {
        Test::fail('PDO connection is not available.');
    }

    return $pdo;
}

/**
 * Allows executing MySQL-intended SQL over SQLite during tests.
 */
final class MysqlForSqlitePDO extends PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
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

        if (str_contains($upper, 'ON DUPLICATE KEY UPDATE')) {
            return <<<SQL
INSERT INTO jobs (name, seen_at, priority) VALUES (:name, :seen, :priority)
ON CONFLICT(name) DO UPDATE SET seen_at = excluded.seen_at, priority = excluded.priority;
SQL;
        }

        if (str_starts_with($upper, 'SELECT GET_LOCK')) {
            return 'SELECT :name';
        }

        if (str_starts_with($upper, 'SELECT RELEASE_LOCK')) {
            return 'SELECT :name';
        }

        if (str_contains($upper, 'NOW()')) {
            $sql = str_ireplace('NOW()', "datetime('now')", $sql);
        }

        return $sql;
    }
}

