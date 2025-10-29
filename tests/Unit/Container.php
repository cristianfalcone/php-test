<?php

declare(strict_types=1);

namespace Ajo\Tests\Unit\Container;

use Ajo\Container;
use Ajo\Test;

Test::suite('Container', function () {

    Test::beforeEach(function () {
        Container::clear();
    });

    Test::case('stores values', function () {
        Container::set('foo', 'bar');
        Test::assertSame('bar', Container::get('foo'));
    });

    Test::case('returns default when missing', function () {
        Test::assertSame('fallback', Container::get('missing', 'fallback'));
    });

    Test::case('reflects stored keys', function () {
        Container::set('present', 123);
        Test::assertTrue(Container::has('present'));
        Test::assertFalse(Container::has('absent'));
    });

    Test::case('removes stored value', function () {
        Container::set('temp', 'value');
        Container::forget('temp');
        Test::assertFalse(Container::has('temp'));
    });

    Test::case('removes all entries', function () {
        Container::set('one', 1);
        Container::set('two', 2);
        Container::clear();
        Test::assertFalse(Container::has('one'));
        Test::assertFalse(Container::has('two'));
    });

    Test::case('resolves once', function () {
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

    Test::case('resolves new instance every time', function () {
        Container::factory('nonce', fn() => new class { } );

        $a = Container::get('nonce');
        $b = Container::get('nonce');

        Test::assertNotSame($a, $b);
    });

    Test::case('receives container when requested', function () {
        Container::set('config.name', 'demo');
        Container::factory('greeter', fn() => 'hello ' . Container::get('config.name'));

        Test::assertSame('hello demo', Container::get('greeter'));
    });

    Test::case('throws when missing and no default provided', function () {
        Test::expectException(\RuntimeException::class, function () {
            Container::get('missing.service');
        });
    });

    Test::case('returns shared core container', function () {
        $first = Container::instance();
        $second = Container::instance();

        Test::assertSame($first, $second);
        // instance() returns ContainerCore, not the facade
        $coreClass = Container::create()::class;
        Test::assertInstanceOf($coreClass, $first);
    });
});
