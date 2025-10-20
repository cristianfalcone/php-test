<?php

declare(strict_types=1);

namespace Ajo\Tests\Unit\Container;

use Ajo\Container;
use Ajo\Test;

Test::suite('Container', function () {

    Test::beforeEach(function () {
        Container::clear();
    });

    Test::it('should store values', function () {
        Container::set('foo', 'bar');
        Test::assertSame('bar', Container::get('foo'));
    });

    Test::it('should return default when missing', function () {
        Test::assertSame('fallback', Container::get('missing', 'fallback'));
    });

    Test::it('should reflect stored keys', function () {
        Container::set('present', 123);
        Test::assertTrue(Container::has('present'));
        Test::assertFalse(Container::has('absent'));
    });

    Test::it('should remove stored value', function () {
        Container::set('temp', 'value');
        Container::forget('temp');
        Test::assertFalse(Container::has('temp'));
    });

    Test::it('should remove all entries', function () {
        Container::set('one', 1);
        Container::set('two', 2);
        Container::clear();
        Test::assertFalse(Container::has('one'));
        Test::assertFalse(Container::has('two'));
    });

    Test::it('should resolve once', function () {
        $counter = 0;
        Container::singleton('clock', function () use (&$counter) {
            $counter++;
            return new class { };
        });

        $first = Container::get('clock');
        $second = Container::get('clock');

        Test::assertSame($first, $second);
        Test::assertSame(1, $counter);
    });

    Test::it('should resolve new instance every time', function () {
        Container::factory('nonce', fn() => new class { } );

        $a = Container::get('nonce');
        $b = Container::get('nonce');

        Test::assertNotSame($a, $b);
    });

    Test::it('should receive container when requested', function () {
        Container::set('config.name', 'demo');
        Container::factory('greeter', fn() => 'hello ' . Container::get('config.name'));

        Test::assertSame('hello demo', Container::get('greeter'));
    });

    Test::it('should throw when missing and no default provided', function () {
        Test::expectException(\RuntimeException::class, function () {
            Container::get('missing.service');
        });
    });

    Test::it('should return shared core container', function () {
        $first = Container::instance();
        $second = Container::instance();

        Test::assertSame($first, $second);
        Test::assertInstanceOf(\Ajo\Core\Container::class, $first);
    });
});
