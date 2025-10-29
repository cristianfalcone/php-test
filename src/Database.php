<?php

declare(strict_types=1);

namespace Ajo;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class Database
{
    private static ?PDO $pdo = null;
    private static ?int $pid = null;
    private static bool $provided = false;

    public static function get(): PDO
    {
        $pid = getmypid();

        if (self::$pdo instanceof PDO) {
            if (self::$pid !== $pid) {
                self::disconnect();
            } elseif (self::$provided || self::ping(self::$pdo)) {
                return self::$pdo;
            } else {
                self::disconnect();
            }
        }

        self::$pdo = self::connect();
        self::$pid = $pid;
        self::$provided = false;

        return self::$pdo;
    }

    public static function set(PDO $pdo): void
    {
        self::$pdo = $pdo;
        self::$pid = getmypid();
        self::$provided = true;
    }

    public static function disconnect(): void
    {
        self::$pdo = null;
        self::$pid = null;
        self::$provided = false;
    }

    private static function env(string $key, ?string $default = null): string
    {
        $value = getenv($key);

        if ($value === false || $value === null) {
            return $default ?? '';
        }

        return (string)$value;
    }

    private static function connect(): PDO
    {
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
            return new PDO($dsn, $user, $password, $options);
        } catch (PDOException $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    private static function ping(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT 1');
            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
