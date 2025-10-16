<?php

declare(strict_types=1);

namespace Ajo\Tests\Unit\Context;

use Ajo\Test;
use Ajo\Context;

Test::suite('Context', function (Test $t) {

    $t->beforeEach(function () {
        Context::clear();
    });

    $t->test('set stores values', function () {
        Context::set('foo', 'bar');
        Test::assertSame('bar', Context::get('foo'));
    });

    $t->test('get returns default when missing', function () {
        Test::assertSame('fallback', Context::get('missing', 'fallback'));
    });

    $t->test('has reflects stored keys', function () {
        Context::set('present', 123);
        Test::assertTrue(Context::has('present'));
        Test::assertFalse(Context::has('absent'));
    });

    $t->test('forget removes stored value', function () {
        Context::set('temp', 'value');
        Context::forget('temp');
        Test::assertFalse(Context::has('temp'));
    });

    $t->test('clear removes all entries', function () {
        Context::set('one', 1);
        Context::set('two', 2);
        Context::clear();
        Test::assertFalse(Context::has('one'));
        Test::assertFalse(Context::has('two'));
    });
});
