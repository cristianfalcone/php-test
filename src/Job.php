<?php

declare(strict_types=1);

namespace Ajo;

use Ajo\Core\Console as Console;
use Ajo\Core\Facade;
use Ajo\Core\Job as Root;

/**
 * @mixin Root
 *
 * @method static Root register(Console $cli)
 * @method static Root schedule(string $cron, callable $handler)
 * @method static Root name(string $name)
 * @method static Root queue(?string $name)
 * @method static Root concurrency(int $n)
 * @method static Root priority(int $n)
 * @method static Root lease(int $seconds)
 * @method static int run()
 * @method static void forever(int $sleep = 5)
 * @method static void stop()
 */
final class Job
{
    use Facade;

    protected static function root(): string
    {
        return Root::class;
    }
}
