<?php

declare(strict_types=1);

namespace Ajo;

use Ajo\Core\Console as CoreConsole;
use Ajo\Core\Facade;
use Ajo\Core\Job as CoreJob;

/**
 * @mixin CoreJob
 *
 * @method static CoreJob register(CoreConsole $cli)
 * @method static CoreJob schedule(string $cron, callable $handler)
 * @method static CoreJob name(string $name)
 * @method static CoreJob queue(?string $name)
 * @method static CoreJob concurrency(int $n)
 * @method static CoreJob priority(int $n)
 * @method static CoreJob lease(int $seconds)
 * @method static int run()
 * @method static void forever(int $sleep = 5)
 * @method static void stop()
 */
final class Job
{
    use Facade;

    protected static function root(): string
    {
        return CoreJob::class;
    }
}
