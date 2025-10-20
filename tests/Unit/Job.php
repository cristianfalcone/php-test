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

        Job::schedule('*/5 * * * *', $handler);

        $jobs = jobs();

        Test::assertCount(1, $jobs);

        $job = $jobs[0];

        Test::assertSame('*/5 * * * *', $job['cron']);
        Test::assertSame('default', $job['queue']);
        Test::assertSame(1, $job['concurrency']);
        Test::assertSame(100, $job['priority']);
        Test::assertSame(3600, $job['lease']);
        Test::assertSame($handler, $job['handler']);
        Test::assertNotSame('', $job['name']);
        Test::assertFalse($job['parsed']['hasSeconds']);
    });

    Test::it('should incorporate key without overriding explicit name', function () {
        Job::schedule('* * * * *', fn() => null)
            ->key('alpha');

        $job = jobs()[0];

        Test::assertSame(substr(sha1('* * * * *|alpha'), 0, 12), $job['name']);
        Test::assertSame('alpha', $job['hash']);
        Test::assertFalse($job['custom']);

        Job::schedule('* * * * *', fn() => null)
            ->name('explicit')
            ->key('beta');

        $second = jobs()[1];

        Test::assertSame('explicit', $second['name']);
        Test::assertSame('beta', $second['hash']);
        Test::assertTrue($second['custom']);
    });

    Test::it('should fluent modifiers override defaults', function () {
        $handler = fn() => null;

        Job::schedule('0 * * * *', $handler)
            ->name('report.generate')
            ->queue('reports')
            ->concurrency(3)
            ->priority(5)
            ->lease(180);

        $job = jobs()[0];

        Test::assertSame('report.generate', $job['name']);
        Test::assertSame('reports', $job['queue']);
        Test::assertSame(3, $job['concurrency']);
        Test::assertSame(5, $job['priority']);
        Test::assertSame(180, $job['lease']);
    });

    Test::it('should execute due jobs and update state on run', function ($state) {
        $cli = Console::create();
        Job::register($cli);

        $executed = 0;

        Job::schedule('* * * * *', function () use (&$executed) {
            $executed++;
        })->name('alpha');

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

        Job::schedule('* * * * *', function () use (&$alphaExecuted) {
            $alphaExecuted = true;
        })
            ->name('alpha')
            ->queue('emails')
            ->concurrency(1);

        Job::schedule('* * * * *', function (): void {
            Test::fail('The queue already reached the concurrency limit.');
        })
            ->name('beta')
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

        Job::schedule('* * * * *', function (): void {
            throw new RuntimeException('boom failure message');
        })->name('failing');

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
        $cli = Console::create();
        $jobs = Job::register($cli);

        $match = function (string $expr, string $date) use ($jobs): bool {
            $parsed = callPrivate($jobs, 'parse', $expr);

            return callPrivate(
                $jobs,
                'matches',
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
        $jobs = jobsInstance();

        $parsed = callPrivate($jobs, 'parse', '* * * * *');
        $moment = new DateTimeImmutable('2024-01-01 12:07:25');

        $first = callPrivate($jobs, 'evaluate', $parsed, $moment, null);
        Test::assertTrue($first['due']);
        Test::assertSame('2024-01-01 12:08:00', $first['next']->format('Y-m-d H:i:s'));

        $sameMinute = callPrivate(
            $jobs,
            'evaluate',
            $parsed,
            $moment,
            new DateTimeImmutable('2024-01-01 12:07:01'),
        );

        Test::assertFalse($sameMinute['due']);
    });

    Test::it('should evaluate six-field cron once per second', function () {
        $jobs = jobsInstance();

        $parsed = callPrivate($jobs, 'parse', '*/5 * * * * *');
        $moment = new DateTimeImmutable('2024-01-01 00:00:10');

        $first = callPrivate($jobs, 'evaluate', $parsed, $moment, null);
        Test::assertTrue($first['due']);
        Test::assertSame('2024-01-01 00:00:15', $first['next']->format('Y-m-d H:i:s'));

        $sameSecond = callPrivate(
            $jobs,
            'evaluate',
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

        Job::schedule('* * * * *', function () use (&$executed, $jobs) {
            $executed++;
            $jobs->stop();
        })->name('self.stop');

        silence(fn() => $jobs->forever());

        Test::assertSame(1, $executed);
    });

    Test::it('should reset queue to default when null provided', function () {
        Job::schedule('* * * * *', fn() => null)
            ->queue(null);

        $job = jobs()[0];
        Test::assertSame('default', $job['queue']);
    });

    Test::it('should enforce minimum duration in lease', function () {
        Job::schedule('* * * * *', fn() => null)
            ->lease(10);

        $job = jobs()[0];
        Test::assertSame(60, $job['lease']);
    });

    Test::it('should render registered jobs in status command', function ($state) {
        $cli = Console::create();
        Job::register($cli);

        Job::schedule('* * * * *', fn() => null)->name('status.job');

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

        Job::schedule('* * * * *', fn() => null)->name('stale.job');

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
});

/**
 * @return array<int, array{
 *     name: string,
 *     cron: string,
 *     queue: string,
 *     concurrency: int,
 *     priority: int,
 *     lease: int,
 *     handler: callable,
 *     parsed: array,
 *     hash: ?string,
 *     custom: bool
 * }>
 */
function jobs(): array
{
    $instance = jobsInstance();

    $reflection = new ReflectionClass(CoreJob::class);
    $property = $reflection->getProperty('jobs');
    $property->setAccessible(true);

    /** @var array<int, array{name:string,cron:string,queue:string,concurrency:int,priority:int,lease:int,handler:callable,parsed:array,hash:?string,custom:bool}> $jobs */
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
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
SQL;
        }

        if (str_contains($upper, 'ON DUPLICATE KEY UPDATE')) {
            return <<<SQL
INSERT INTO jobs (name, seen_at) VALUES (:name, :seen)
ON CONFLICT(name) DO UPDATE SET seen_at = excluded.seen_at;
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

