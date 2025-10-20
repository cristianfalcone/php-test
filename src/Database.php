<?php

declare(strict_types=1);

namespace Ajo;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function get(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host = self::env('DB_HOST', 'db');
        $port = (string)self::env('DB_PORT', '3306');
        $database = self::env('DB_DATABASE', 'app');
        $user = self::env('DB_USER', 'appuser');
        $password = self::env('DB_PASSWORD', 'apppass');
        $charset = self::env('DB_CHARSET', 'utf8mb4');

        if ($database === '' || $user === '') {
            throw new RuntimeException('Incomplete database configuration: DB_DATABASE and DB_USER are required.');
        }

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $database, $charset);

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            self::$pdo = new PDO($dsn, $user, $password, $options);
        } catch (PDOException $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        return self::$pdo;
    }

    public static function set(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function disconnect(): void
    {
        self::$pdo = null;
    }

    private static function env(string $key, ?string $default = null): string
    {
        $value = getenv($key);

        if ($value === false || $value === null) {
            return $default ?? '';
        }

        return (string)$value;
    }
}
