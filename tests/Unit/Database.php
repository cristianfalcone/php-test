<?php

declare(strict_types=1);

namespace Ajo\Tests\Unit\Database;

use Ajo\Database;
use Ajo\Test;
use RuntimeException;

class FakePDO extends \PDO
{
    public function __construct()
    {
    }
}

Test::suite('Database', function () {

    Test::beforeEach(function ($state) {
        $state['env'] = snapshot();
        Database::disconnect();
    });

    Test::afterEach(function ($state) {
        restore($state['env']);
        Database::disconnect();
    });

    Test::case('returns previously set connection', function () {
        $pdo = new FakePDO();

        Database::set($pdo);

        Test::assertSame($pdo, Database::get());
    });

    Test::case('clears cached instance', function () {
        $first = new FakePDO();
        $second = new FakePDO();

        Database::set($first);
        Database::disconnect();
        Database::set($second);

        Test::assertSame($second, Database::get());
    });

    Test::case('fails when configuration is incomplete', function () {
        setEnv([
            'DB_DATABASE' => '',
            'DB_USER' => '',
        ]);

        Database::disconnect();

        Test::expectException(RuntimeException::class, function () {
            Database::get();
        });
    });

    Test::case('wraps pdo exception into runtime exception', function () {
        
        setEnv([
            'DB_HOST' => 'invalid-host',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'missing_db',
            'DB_USER' => 'unknown',
            'DB_PASSWORD' => 'secret',
            'DB_CHARSET' => 'utf8mb4',
        ]);

        Database::disconnect();

        Test::expectException(RuntimeException::class, function () {
            Database::get();
        });

    })->skip();
});

function snapshot()
{
    $keys = ['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USER', 'DB_PASSWORD', 'DB_CHARSET'];
    $values = [];

    foreach ($keys as $key) {
        $values[$key] = getenv($key) !== false ? (string)getenv($key) : null;
    }

    return $values;
}

function restore(array $values)
{
    foreach ($values as $key => $value) {
        if ($value === null) {
            putenv($key);
        } else {
            putenv($key . '=' . $value);
        }
    }
}

function setEnv(array $variables)
{
    foreach ($variables as $key => $value) {
        putenv($key . '=' . $value);
    }
}
