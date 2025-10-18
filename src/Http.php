<?php

declare(strict_types=1);

namespace Ajo;

use Ajo\Core\Facade;
use Ajo\Core\Http as Root;

/**
 * @mixin Root
 *
 * @method static Root create(?callable $notFoundHandler = null, ?callable $exceptionHandler = null)
 * @method static Root use(string|callable $path, callable ...$handlers)
 * @method static Root map(array|string $methods, string $path, callable ...$handlers)
 * @method static void dispatch(?string $method = null, ?string $target = null)
 */
final class Http
{
    use Facade;

    protected static function root(): string
    {
        return Root::class;
    }
}
