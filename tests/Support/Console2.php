<?php

declare(strict_types=1);

namespace Ajo\Tests\Support\Console2;

use Ajo\Console2;
use Ajo\Core\Console2 as CoreConsole2;
use ReflectionClass;

/**
 * Executes a command and returns captured exit code, stdout and stderr.
 *
 * @return array{0:int,1:string,2:string}
 */
function dispatch(
    CoreConsole2 $cli,
    string $command,
    array $arguments = [],
    bool $captureStreams = true
): array {
    $hadArgv = array_key_exists('argv', $_SERVER);
    $previousArgv = $hadArgv ? $_SERVER['argv'] : null;
    // Preserve custom binary name if already set, otherwise use 'console'
    $bin = $hadArgv && isset($_SERVER['argv'][0]) ? $_SERVER['argv'][0] : 'console';
    $_SERVER['argv'] = array_merge([$bin, $command], $arguments);

    $previous = Console2::instance();
    Console2::swap($cli);

    $stdout = fopen('php://temp', 'w+');
    $stderr = fopen('php://temp', 'w+');
    $output = ['', ''];
    $exitCode = 0;

    try {
        $exitCode = $cli->dispatch($command, $arguments, $stdout, $stderr);

        if (is_resource($stdout) && is_resource($stderr)) {
            rewind($stdout);
            rewind($stderr);
            $output = [
                stream_get_contents($stdout) ?: '',
                stream_get_contents($stderr) ?: ''
            ];
        }

        return [$exitCode, $output[0], $output[1]];
    } finally {
        Console2::swap($previous);

        if (is_resource($stdout)) fclose($stdout);
        if (is_resource($stderr)) fclose($stderr);

        if ($hadArgv) {
            $_SERVER['argv'] = $previousArgv;
        } else {
            unset($_SERVER['argv']);
        }
    }
}

/**
 * Since we can't override stream_isatty (already declared in Console tests),
 * we work with actual stream detection. Console2 uses stream_isatty() which
 * returns true for STDOUT/STDERR and false for file streams.
 *
 * We'll use the colors() and timestamps() methods to manually control behavior
 * in tests instead of trying to mock TTY detection.
 */
