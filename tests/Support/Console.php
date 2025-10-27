<?php

declare(strict_types=1);

namespace Ajo\Tests\Support\Console;

use Ajo\Console;
use Ajo\Core\Console as CoreConsole;
use ReflectionClass;

/**
 * Executes a command and returns captured exit code, stdout and stderr.
 *
 * @return array{0:int,1:string,2:string}
 */
function dispatch(
    CoreConsole $cli,
    string $command,
    array $arguments = [],
    bool $captureStreams = true
): array {
    [$stdoutProp, $stderrProp, $ownsStdout, $ownsStderr, $isInteractive, $withColors, $withTimestamps] = statics();

    $snapshot = snapshot($stdoutProp, $stderrProp, $ownsStdout, $ownsStderr, $isInteractive, $withColors, $withTimestamps, $cli);

    $hadArgv = array_key_exists('argv', $GLOBALS);
    $previousArgv = $hadArgv ? $GLOBALS['argv'] : null;
    $GLOBALS['argv'] = array_merge(['console', $command], $arguments);

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
            $ownsStdout->setValue($cli, false);
            $ownsStderr->setValue($cli, false);
            $isInteractive->setValue($cli, false);
            $withColors->setValue($cli, false);
            $withTimestamps->setValue($cli, true);

            $exitCode = $cli->dispatch($command, $arguments);
        }

        if (is_resource($stdout) && is_resource($stderr)) {
            $output = readAndClose([$stdout, $stderr]);
            $stdout = $stderr = null;
        }

        return [$exitCode, $output[0], $output[1]];
    } finally {
        Console::swap($previous);

        if (is_resource($stdout)) fclose($stdout);
        if (is_resource($stderr)) fclose($stderr);

        restore($stdoutProp, $stderrProp, $ownsStdout, $ownsStderr, $isInteractive, $withColors, $withTimestamps, $snapshot, $cli);

        if ($hadArgv) {
            $GLOBALS['argv'] = $previousArgv;
        } else {
            unset($GLOBALS['argv']);
        }
    }
}

/**
 * Executes a callback silencing Console's stdout/stderr.
 *
 * @return array{0:string,1:string} captured contents
 */
function silence(callable $callback): array
{
    $console = Console::instance();

    [$stdoutProp, $stderrProp, $ownsStdout, $ownsStderr, $isTTY, $withColors, $withTimestamps] = statics();
    $snapshot = snapshot($stdoutProp, $stderrProp, $ownsStdout, $ownsStderr, $isTTY, $withColors, $withTimestamps, $console);

    $stdout = fopen('php://temp', 'w+');
    $stderr = fopen('php://temp', 'w+');

    $stdoutProp->setValue($console, $stdout);
    $stderrProp->setValue($console, $stderr);
    $ownsStdout->setValue($console, false);
    $ownsStderr->setValue($console, false);
    $isTTY->setValue($console, false);
    $withColors->setValue($console, false);
    $withTimestamps->setValue($console, true);

    try {
        $callback();
    } finally {
        restore($stdoutProp, $stderrProp, $ownsStdout, $ownsStderr, $isTTY, $withColors, $withTimestamps, $snapshot, $console);
    }

    return readAndClose([$stdout, $stderr]);
}

/**
 * @return array{0:\ReflectionProperty,1:\ReflectionProperty,2:\ReflectionProperty,3:\ReflectionProperty,4:\ReflectionProperty,5:\ReflectionProperty,6:\ReflectionProperty}
 */
function statics(): array
{
    $reflection = new ReflectionClass(CoreConsole::class);

    $stdout = $reflection->getProperty('stdout'); $stdout->setAccessible(true);
    $stderr = $reflection->getProperty('stderr'); $stderr->setAccessible(true);
    $ownsStdout = $reflection->getProperty('ownsStdout'); $ownsStdout->setAccessible(true);
    $ownsStderr = $reflection->getProperty('ownsStderr'); $ownsStderr->setAccessible(true);
    $isInteractive = $reflection->getProperty('isInteractive'); $isInteractive->setAccessible(true);
    $withColors = $reflection->getProperty('withColors'); $withColors->setAccessible(true);
    $withTimestamps = $reflection->getProperty('withTimestamps'); $withTimestamps->setAccessible(true);

    return [$stdout, $stderr, $ownsStdout, $ownsStderr, $isInteractive, $withColors, $withTimestamps];
}

/**
 * @param \ReflectionProperty ...$properties
 * @return array<string,mixed>
 */
function snapshot(
    \ReflectionProperty $stdout,
    \ReflectionProperty $stderr,
    \ReflectionProperty $ownsStdout,
    \ReflectionProperty $ownsStderr,
    \ReflectionProperty $isTTY,
    \ReflectionProperty $withColors,
    \ReflectionProperty $withTimestamps,
    CoreConsole $console
): array {
    return [
        'stdout' => $stdout->getValue($console),
        'stderr' => $stderr->getValue($console),
        'ownsStdout' => $ownsStdout->getValue($console),
        'ownsStderr' => $ownsStderr->getValue($console),
        'isTTY' => $isTTY->getValue($console),
        'withColors' => $withColors->getValue($console),
        'withTimestamps' => $withTimestamps->getValue($console),
    ];
}

/**
 * @param array{stdout:mixed,stderr:mixed,ownsStdout:mixed,ownsStderr:mixed,isTTY:mixed,withColors:mixed,withTimestamps:mixed} $snapshot
 */
function restore(
    \ReflectionProperty $stdout,
    \ReflectionProperty $stderr,
    \ReflectionProperty $ownsStdout,
    \ReflectionProperty $ownsStderr,
    \ReflectionProperty $isTTY,
    \ReflectionProperty $withColors,
    \ReflectionProperty $withTimestamps,
    array $snapshot,
    CoreConsole $console
): void {
    $stdout->setValue($console, $snapshot['stdout']);
    $stderr->setValue($console, $snapshot['stderr']);
    $ownsStdout->setValue($console, $snapshot['ownsStdout']);
    $ownsStderr->setValue($console, $snapshot['ownsStderr']);
    $isTTY->setValue($console, $snapshot['isTTY']);
    $withColors->setValue($console, $snapshot['withColors']);
    $withTimestamps->setValue($console, $snapshot['withTimestamps']);
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
