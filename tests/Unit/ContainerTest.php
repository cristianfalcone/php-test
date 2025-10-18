<?php

declare(strict_types=1);

namespace Ajo\Tests\Unit\Container;

use Ajo\Container;
use Ajo\Test;

Test::suite('Container', function (Test $t) {

    $t->beforeEach(function () {
        Container::clear();
    });

    $t->test('set stores values', function () {
        Container::set('foo', 'bar');
        Test::assertSame('bar', Container::get('foo'));
    });

    $t->test('get returns default when missing', function () {
        Test::assertSame('fallback', Container::get('missing', 'fallback'));
    });

    $t->test('has reflects stored keys', function () {
        Container::set('present', 123);
        Test::assertTrue(Container::has('present'));
        Test::assertFalse(Container::has('absent'));
    });

    $t->test('forget removes stored value', function () {
        Container::set('temp', 'value');
        Container::forget('temp');
        Test::assertFalse(Container::has('temp'));
    });

    $t->test('clear removes all entries', function () {
        Container::set('one', 1);
        Container::set('two', 2);
        Container::clear();
        Test::assertFalse(Container::has('one'));
        Test::assertFalse(Container::has('two'));
    });

    $t->test('singleton resolves once', function () {
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

    $t->test('factory resolves new instance every time', function () {
        Container::factory('nonce', fn() => new class { } );

        $a = Container::get('nonce');
        $b = Container::get('nonce');

        Test::assertNotSame($a, $b);
    });

    $t->test('factory receives container when requested', function () {
        Container::set('config.name', 'demo');
        Container::factory('greeter', fn() => 'hello ' . Container::get('config.name'));

        Test::assertSame('hello demo', Container::get('greeter'));
    });

    $t->test('get throws when missing and no default provided', function () {
        Test::expectException(\RuntimeException::class, function () {
            Container::get('missing.service');
        });
    });
});
