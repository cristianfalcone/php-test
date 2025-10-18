<?php

declare(strict_types=1);

namespace Ajo\Tests\Unit\Jobs;

use Ajo\Console;
use Ajo\Container;
use Ajo\Core\Job as CoreJob;
use Ajo\Job;
use Ajo\Test;
use ArrayObject;
use DateTimeImmutable;
use PDO;
use PDOStatement;
use ReflectionClass;
use RuntimeException;
use function Ajo\Tests\Support\Console\dispatch;
use function Ajo\Tests\Support\Console\silence;

Test::suite('Jobs', function (Test $t) {

    $t->beforeEach(function (ArrayObject $state): void {
        Container::clear();

        $pdo = new MysqlForSqlitePDO();
        Container::set('db', $pdo);

        $state['pdo'] = $pdo;

        resetSingleton();
    });

    $t->afterEach(function (ArrayObject $state): void {
        Container::clear();
        resetSingleton();
        unset($state['pdo']);
    });

    $t->test('register adds job commands', function () {
        $cli = Console::create();
        $jobs = Job::register($cli);

        Test::assertInstanceOf(CoreJob::class, $jobs);

        $commands = $cli->commands();

        foreach (['jobs:install', 'jobs:status', 'jobs:collect', 'jobs:work', 'jobs:prune'] as $command) {
            Test::assertArrayHasKey($command, $commands, sprintf('Command %s was not registered.', $command));
        }
    });

    $t->test('schedule adds job with defaults', function () {
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
    });

    $t->test('fluent modifiers override defaults', function () {
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

    $t->test('run executes due jobs and updates state', function (ArrayObject $state) {
        $cli = Console::create();
        Job::register($cli);

        $executed = 0;

        Job::schedule('* * * * *', function () use (&$executed) {
            $executed++;
        })->name('alpha');

        [$exitCode, $stdout, $stderr] = dispatch($cli, 'jobs:collect');

        Test::assertSame(0, $exitCode);
        Test::assertStringContainsString('Ejecutados 1 job(s).', $stdout);
        Test::assertSame('', $stderr);
        Test::assertSame(1, $executed);

        $row = row($state, 'alpha');

        Test::assertNotNull($row['last_run']);
        Test::assertSame($row['last_run'], $row['last_tick']);
        Test::assertSame('00', (new DateTimeImmutable($row['last_run']))->format('s'));
        Test::assertSame(0, (int)$row['fail_count']);
        Test::assertNull($row['last_error']);
        Test::assertNull($row['lease_until']);
    });

    $t->test('run skips jobs when concurrency limit reached', function (ArrayObject $state) {
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
            Test::fail('La cola ya alcanzó el límite de concurrencia.');
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
        Test::assertSame('', $stdout);
        Test::assertSame('', $stderr);
        Test::assertFalse($alphaExecuted);

        $betaRow = row($state, 'beta');
        Test::assertNull($betaRow['last_run']);
        Test::assertNull($betaRow['last_tick']);
        Test::assertSame(0, (int)$betaRow['fail_count']);
    });

    $t->test('run records failures and leaves trace', function (ArrayObject $state) {
        $cli = Console::create();
        Job::register($cli);

        Job::schedule('* * * * *', function (): void {
            throw new RuntimeException('boom failure message');
        })->name('failing');

        [$exitCode, $stdout, $stderr] = dispatch($cli, 'jobs:collect');

        Test::assertSame(0, $exitCode);
        Test::assertStringContainsString('Ejecutados 1 job(s).', $stdout);
        Test::assertStringContainsString('boom failure message', $stderr);

        $row = row($state, 'failing');

        Test::assertNull($row['last_run']);
        Test::assertNotNull($row['last_tick']);
        Test::assertSame('00', (new DateTimeImmutable($row['last_tick']))->format('s'));
        Test::assertSame('boom failure message', $row['last_error']);
        Test::assertSame(1, (int)$row['fail_count']);
        Test::assertNull($row['lease_until']);
    });

    $t->test('due combines day of month and week correctly', function () {
        $cli = Console::create();
        $jobs = Job::register($cli);

        $check = fn(string $expr, string $date) => callPrivate($jobs, 'due', $expr, new DateTimeImmutable($date));

        Test::assertTrue($check('0 0 1 * 0', '2024-12-01 00:00:00')); // domingo y día 1
        Test::assertFalse($check('0 0 1 * 0', '2024-12-02 00:00:00')); // lunes 2

        Test::assertTrue($check('15 10 * * 3', '2024-05-08 10:15:00')); // miércoles
        Test::assertTrue($check('15 10 10 * 3', '2024-05-10 10:15:00')); // día del mes
        Test::assertFalse($check('15 10 10 * 3', '2024-05-09 10:15:00')); // ni día ni dow
    });

    $t->test('forever stops when stop called', function () {
        $cli = Console::create();
        /** @var CoreJob $jobs */
        $jobs = Job::register($cli);

        $executed = 0;

        Job::schedule('* * * * *', function () use (&$executed, $jobs) {
            $executed++;
            $jobs->stop();
        })->name('self.stop');

        silence(fn() => $jobs->forever(0));

        Test::assertSame(1, $executed);
    });
});

/**
 * @return array<int, array{name:string,cron:string,queue:string,concurrency:int,priority:int,lease:int,handler:callable}>
 */
function jobs(): array
{
    $instance = jobsInstance();

    $reflection = new ReflectionClass(CoreJob::class);
    $property = $reflection->getProperty('jobs');
    $property->setAccessible(true);

    /** @var array<int, array{name:string,cron:string,queue:string,concurrency:int,priority:int,lease:int,handler:callable}> $jobs */
    $jobs = $property->getValue($instance);

    return $jobs;
}

function jobsInstance(): CoreJob
{
    $instance = Job::instance();

    if (!$instance instanceof CoreJob) {
        Test::fail('La instancia de Jobs no está disponible.');
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
        Test::fail('No se pudo invocar el método privado.');
    }

    return $closure();
}

/**
 * @return array{name:?string,last_tick:?string,lease_until:?string,last_run:?string,last_error:?string,fail_count:int,seen_at:?string}
 */
function row(ArrayObject $state, string $name): array
{
    $statement = pdo($state)->prepare('SELECT * FROM jobs WHERE name = :n');
    $statement->execute([':n' => $name]);

    $row = $statement->fetch();
    $statement->closeCursor();

    if ($row === false) {
        return [
            'name' => $name,
            'last_tick' => null,
            'lease_until' => null,
            'last_run' => null,
            'last_error' => null,
            'fail_count' => 0,
            'seen_at' => null,
        ];
    }

    return $row;
}

function pdo(ArrayObject $state): PDO
{
    $pdo = $state['pdo'] ?? null;

    if (!$pdo instanceof PDO) {
        Test::fail('La conexión PDO no está disponible.');
    }

    return $pdo;
}

/**
 * Permite ejecutar SQL pensado para MySQL sobre SQLite durante los tests.
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
    last_tick TEXT NULL,
    lease_until TEXT NULL,
    last_run TEXT NULL,
    last_error TEXT NULL,
    fail_count INTEGER NOT NULL DEFAULT 0,
    seen_at TEXT NULL,
    retired_at TEXT NULL
);
SQL;
        }

        if (str_contains($upper, 'ON DUPLICATE KEY UPDATE')) {
            return <<<SQL
INSERT INTO jobs (name, seen_at) VALUES (:n, :s)
ON CONFLICT(name) DO UPDATE SET seen_at = excluded.seen_at, retired_at = NULL;
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
