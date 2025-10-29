<?php

declare(strict_types=1);

namespace Ajo\Tests\Support\Console;

use Ajo\Console;
use ReflectionClass;

/**
 * Executes a command and returns captured exit code, stdout and stderr.
 *
 * Note: $cli should be created via Console::create() or be the result of Console::instance()
 *
 * @param object $cli Instance of ConsoleCore
 * @return array{0:int,1:string,2:string}
 */
function dispatch(
    object $cli,
    string $command,
    array $arguments = [],
    bool $captureStreams = true
): array {
    [$stdoutProp, $stderrProp, $interactive, $colorsEnabled, $timestampsEnabled] = statics();

    $snapshot = snapshot($stdoutProp, $stderrProp, $interactive, $colorsEnabled, $timestampsEnabled, $cli);

    // Use $_SERVER['argv'] for better compatibility
    $hadArgv = array_key_exists('argv', $_SERVER);
    $previousArgv = $hadArgv ? $_SERVER['argv'] : null;
    // Preserve custom binary name if already set, otherwise use 'console'
    $bin = $hadArgv && isset($_SERVER['argv'][0]) ? $_SERVER['argv'][0] : 'console';
    $_SERVER['argv'] = array_merge([$bin, $command], $arguments);

    $previous = Console::instance();
    Console::swap($cli);

    $stdout = null;
    $stderr = null;
    $output = ['', ''];
    $exitCode = 0;

    try {
        if ($captureStreams) {
            $stdout = fopen('php://temp', 'w+');
            $stderr = fopen('php://temp', 'w+');

            $exitCode = $cli->dispatch($command, $arguments, $stdout, $stderr);
        } else {
            $stdout = fopen('php://temp', 'w+');
            $stderr = fopen('php://temp', 'w+');

            $stdoutProp->setValue($cli, $stdout);
            $stderrProp->setValue($cli, $stderr);
            $interactive->setValue($cli, false);
            $colorsEnabled->setValue($cli, false);
            $timestampsEnabled->setValue($cli, true);

            $exitCode = $cli->dispatch($command, $arguments);
        }

        if (is_resource($stdout) && is_resource($stderr)) {
            $output = readAndClose([$stdout, $stderr]);
            $stdout = $stderr = null;
        }

        return [$exitCode, $output[0], $output[1]];
    } finally {
        if (is_resource($stdout)) fclose($stdout);
        if (is_resource($stderr)) fclose($stderr);

        restore($stdoutProp, $stderrProp, $interactive, $colorsEnabled, $timestampsEnabled, $snapshot, $cli);

        // Swap back AFTER restoring $cli, to ensure singleton state is not corrupted
        Console::swap($previous);

        if ($hadArgv) {
            $_SERVER['argv'] = $previousArgv;
        } else {
            unset($_SERVER['argv']);
        }
    }
}

/**
 * Executes a callback silencing Console's stdout/stderr.
 *
 * @param callable $callback Callback to execute
 * @return array{0:string,1:string} captured contents [stdout, stderr]
 */
function silence(callable $callback): array
{
    $console = Console::instance();

    [$stdoutProp, $stderrProp, $interactive, $colorsEnabled, $timestampsEnabled] = statics();
    $snapshot = snapshot($stdoutProp, $stderrProp, $interactive, $colorsEnabled, $timestampsEnabled, $console);

    $stdout = fopen('php://temp', 'w+');
    $stderr = fopen('php://temp', 'w+');

    $stdoutProp->setValue($console, $stdout);
    $stderrProp->setValue($console, $stderr);
    $interactive->setValue($console, false);
    $colorsEnabled->setValue($console, false);
    $timestampsEnabled->setValue($console, true);

    try {
        $callback();
    } finally {
        restore($stdoutProp, $stderrProp, $interactive, $colorsEnabled, $timestampsEnabled, $snapshot, $console);
    }

    return readAndClose([$stdout, $stderr]);
}

/**
 * @return array{0:\ReflectionProperty,1:\ReflectionProperty,2:\ReflectionProperty,3:\ReflectionProperty,4:\ReflectionProperty}
 */
function statics(): array
{
    // Get ConsoleCore class name from Console facade
    $coreClass = Console::create()::class;
    $reflection = new ReflectionClass($coreClass);

    $stdout = $reflection->getProperty('stdout'); $stdout->setAccessible(true);
    $stderr = $reflection->getProperty('stderr'); $stderr->setAccessible(true);
    $interactive = $reflection->getProperty('interactive'); $interactive->setAccessible(true);
    $colorsEnabled = $reflection->getProperty('colorsEnabled'); $colorsEnabled->setAccessible(true);
    $timestampsEnabled = $reflection->getProperty('timestampsEnabled'); $timestampsEnabled->setAccessible(true);

    return [$stdout, $stderr, $interactive, $colorsEnabled, $timestampsEnabled];
}

/**
 * @param \ReflectionProperty ...$properties
 * @return array<string,mixed>
 */
function snapshot(
    \ReflectionProperty $stdout,
    \ReflectionProperty $stderr,
    \ReflectionProperty $interactive,
    \ReflectionProperty $colorsEnabled,
    \ReflectionProperty $timestampsEnabled,
    object $console
): array {
    return [
        'stdout' => $stdout->getValue($console),
        'stderr' => $stderr->getValue($console),
        'interactive' => $interactive->getValue($console),
        'colorsEnabled' => $colorsEnabled->getValue($console),
        'timestampsEnabled' => $timestampsEnabled->getValue($console),
    ];
}

/**
 * @param array{stdout:mixed,stderr:mixed,interactive:mixed,colorsEnabled:mixed,timestampsEnabled:mixed} $snapshot
 */
function restore(
    \ReflectionProperty $stdout,
    \ReflectionProperty $stderr,
    \ReflectionProperty $interactive,
    \ReflectionProperty $colorsEnabled,
    \ReflectionProperty $timestampsEnabled,
    array $snapshot,
    object $console
): void {
    $stdout->setValue($console, $snapshot['stdout']);
    $stderr->setValue($console, $snapshot['stderr']);
    $interactive->setValue($console, $snapshot['interactive']);
    $colorsEnabled->setValue($console, $snapshot['colorsEnabled']);
    $timestampsEnabled->setValue($console, $snapshot['timestampsEnabled']);
}

/**
 * @param array<int, resource> $streams
 * @return array{0:string,1:string}
 */
function readAndClose(array $streams): array
{
    $contents = [];

    foreach ($streams as $stream) {
        rewind($stream);
        $contents[] = stream_get_contents($stream) ?: '';
        fclose($stream);
    }

    return [$contents[0] ?? '', $contents[1] ?? ''];
}
