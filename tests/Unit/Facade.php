<?php

declare(strict_types=1);

namespace Ajo\Tests\Unit\Facade;

use Ajo\Container;
use Ajo\Core\Facade;
use Ajo\Test;
use BadMethodCallException;
use InvalidArgumentException;

class StubService
{
    public static function greet(): string
    {
        return 'hola';
    }

    private static function hidden(): void
    {
    }

    public function ping(): string
    {
        return 'pong';
    }

    private function secret(): void
    {
    }

    public function __call(string $name, array $arguments): mixed
    {
        if ($name === 'dynamic') {
            return $arguments[0] ?? 'dynamic';
        }

        return 'called:' . $name;
    }
}

final class CustomStubService extends StubService
{
    public function ping(): string
    {
        return 'custom';
    }
}

final class StubFacade
{
    use Facade;

    protected static function root(): string
    {
        return StubService::class;
    }
}

final class PlainService
{
    public function say(): string
    {
        return 'plain';
    }
}

final class PlainFacade
{
    use Facade;

    protected static function root(): string
    {
        return PlainService::class;
    }
}

Test::suite('Facade', function () {

    Test::beforeEach(function () {
        Container::clear();
    });

    Test::it('should proxy static methods to root statics', function () {
        Test::assertSame('hola', StubFacade::greet());
    });

    Test::it('should reject inaccessible statics', function () {
        Test::expectException(BadMethodCallException::class, function () {
            StubFacade::hidden();
        });
    });

    Test::it('should proxy instance methods to underlying instance', function () {
        Test::assertSame('pong', StubFacade::ping());
    });

    Test::it('should not expose private instance methods', function () {
        Test::expectException(BadMethodCallException::class, function () {
            StubFacade::secret();
        });
    });

    Test::it('should fallback missing methods to __call when available', function () {
        Test::assertSame('custom', StubFacade::dynamic('custom'));
    });

    Test::it('should throw exception when missing methods without __call throw exception', function () {
        Test::expectException(BadMethodCallException::class, function () {
            PlainFacade::missing();
        });
    });

    Test::it('should replace underlying instance on swap', function () {
        $custom = new CustomStubService();
        StubFacade::swap($custom);

        Test::assertSame('custom', StubFacade::ping());
    });

    Test::it('should reject invalid instances', function () {
        Test::expectException(InvalidArgumentException::class, function () {
            /** @phpstan-ignore-next-line deliberately wrong type */
            StubFacade::swap(new PlainService());
        });
    });
});
