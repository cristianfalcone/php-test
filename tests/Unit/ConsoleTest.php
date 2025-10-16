<?php

declare(strict_types=1);

namespace Ajo;

use Ajo\Tests\Unit\Console\Harness;

function stream_isatty($stream): bool
{
    return Harness::$isatty;
}

function sapi_windows_vt100_support($stream, bool $enable): bool
{
    Harness::$vt100 = true;
    return true;
}

namespace Ajo\Tests\Unit\Console;

use Ajo\Console;
use Ajo\Test;
use ReflectionClass;
use RuntimeException;

final class Harness
{
    public static bool $isatty = false;
    public static bool $vt100 = false;

    public static function reset(): void
    {
        self::$isatty = false;
        self::$vt100 = false;
    }
}

Test::suite('Console', function (Test $t) {
    $t->beforeEach(function () {
        Harness::reset();
    });

    $t->test('command definitions include help', function () {
        $cli = Console::create();
        $definitions = $cli->commands();

        Test::assertArrayHasKey('help', $definitions);
        Test::assertSame('Muestra la ayuda de los comandos disponibles.', $definitions['help']['description']);
    });

    $t->test('command registration stores description', function () {
        $cli = Console::create();

        $cli->command('demo', fn() => 0)->describe('Demostracion');
        $definitions = $cli->commands();

        Test::assertArrayHasKey('demo', $definitions);
        Test::assertSame('Demostracion', $definitions['demo']['description']);
    });

    $t->test('dispatch runs command and returns exit code', function () {
        $cli = Console::create();
        $executed = false;

        $cli->command('demo', function () use (&$executed) {
            $executed = true;
            Console::log('demo ejecutado');
            return 5;
        })->describe('Demo');

        [$exitCode, $stdout, $stderr] = dispatch($cli, 'demo');

        Test::assertTrue($executed);
        Test::assertSame(5, $exitCode);
        Test::assertStringContainsString('demo ejecutado', $stdout);
        Test::assertSame('', $stderr);
    });

    $t->test('middleware runs before command', function () {
        $cli = Console::create();
        $events = [];

        $cli->use(function (callable $next) use (&$events) {
            $events[] = 'mw';
            return $next();
        });

        $cli->command('demo', function () use (&$events) {
            $events[] = 'cmd';
            return 0;
        })->describe('Demo');

        dispatch($cli, 'demo');
        Test::assertSame(['mw', 'cmd'], $events);
    });

    $t->test('prefixed middleware runs only for matching commands', function () {
        $cli = Console::create();
        $events = [];

        $cli->use('jobs', function (callable $next) use (&$events) {
            $events[] = 'jobs';
            return $next();
        });

        $cli->command('jobs:run', function () use (&$events) {
            $events[] = 'run';
            return 0;
        })->describe('Run');

        $cli->command('status', function () use (&$events) {
            $events[] = 'status';
            return 0;
        })->describe('Status');

        dispatch($cli, 'jobs:run');
        Test::assertSame(['jobs', 'run'], $events);

        $events = [];
        dispatch($cli, 'status');
        Test::assertSame(['status'], $events);
    });

    $t->test('command receives arguments via static helper', function () {
        $cli = Console::create();

        $cli->command('demo', function () {
            Console::log(json_encode(Console::arguments()));
            return 0;
        })->describe('Demo');

        [, $stdout] = dispatch($cli, 'demo', ['foo', 'bar']);

        Test::assertStringContainsString('["foo","bar"]', $stdout);
    });

    $t->test('help command lists registered commands', function () {
        $cli = Console::create();

        $cli->command('sample', fn() => 0)->describe('Sample command');

        [$exitCode, $stdout] = dispatch($cli, 'help');

        Test::assertSame(0, $exitCode);
        Test::assertStringContainsString('Comandos disponibles', $stdout);
        Test::assertStringContainsString('sample', $stdout);
    });

    $t->test('help for unknown command emits error', function () {
        $cli = Console::create();

        [$exitCode, $stdout, $stderr] = dispatch($cli, 'help', ['missing']);

        Test::assertSame(1, $exitCode);
        Test::assertStringContainsString("Usa 'console help' para ver la lista de comandos.", $stdout);
        Test::assertStringContainsString('no existe', $stderr);
    });

    $t->test('dispatching unknown command emits not found message', function () {
        $cli = Console::create();

        [$exitCode, $stdout, $stderr] = dispatch($cli, 'unknown');

        Test::assertSame(1, $exitCode);
        Test::assertStringContainsString("Usa 'console help' para ver la lista de comandos.", $stdout);
        Test::assertStringContainsString('no está definido', $stderr);
    });

    $t->test('success uses ansi colors when supported', function () {
        Harness::$isatty = true;
        $cli = Console::create();

        $cli->command('color', function () {
            Console::success('coloreado');
            return 0;
        })->describe('Color');

        [, $stdout] = dispatch($cli, 'color');

        Test::assertStringContainsString("\033[32m[OK] coloreado\033[39m", $stdout);
    });

    $t->test('error writes to stderr with prefix', function () {
        $cli = Console::create();

        $cli->command('fail', function () {
            Console::error('fallo');
            return 0;
        })->describe('Fail');

        [,, $stderr] = dispatch($cli, 'fail');

        Test::assertStringContainsString('[ERROR] fallo', $stderr);
    });

    $t->test('command returning string is emitted', function () {
        $cli = Console::create();

        $cli->command('echo', fn() => "hola mundo\n")->describe('Echo');

        [$exitCode, $stdout, $stderr] = dispatch($cli, 'echo');

        Test::assertSame(0, $exitCode);
        Test::assertSame("hola mundo\n", $stdout);
        Test::assertSame('', $stderr);
    });

    $t->test('command returning false produces exit code one', function () {
        $cli = Console::create();

        $cli->command('fail', fn() => false)->describe('Fail');

        [$exitCode] = dispatch($cli, 'fail');

        Test::assertSame(1, $exitCode);
    });

    $t->test('command returning null defaults to success', function () {
        $cli = Console::create();

        $cli->command('null', fn() => null)->describe('Null');

        [$exitCode, $stdout, $stderr] = dispatch($cli, 'null');

        Test::assertSame(0, $exitCode);
        Test::assertSame('', $stdout);
        Test::assertSame('', $stderr);
    });

    $t->test('blank does not write when zero lines', function () {
        $cli = Console::create();

        $cli->command('blank', function () {
            Console::blank(0);
            return 0;
        })->describe('Blank');

        [, $stdout] = dispatch($cli, 'blank');

        Test::assertSame('', $stdout);
    });

    $t->test('log handles empty message', function () {
        $cli = Console::create();

        $cli->command('empty-line', function () {
            Console::log('');
            return 0;
        })->describe('Empty');

        [, $stdout, $stderr] = dispatch($cli, 'empty-line');

        Test::assertSame(PHP_EOL, $stdout);
        Test::assertSame('', $stderr);
    });

    $t->test('style builder applies styles before logging', function () {
        $cli = Console::create();

        $cli->command('styled', function () {
            Console::bold()->red()->log('styled message');
            return 0;
        })->describe('Styled');

        [, $stdout, $stderr] = dispatch($cli, 'styled');

        Test::assertStringContainsString('styled message', $stdout);
        Test::assertSame('', $stderr);
    });

    $t->test('style builder delegates to helper', function () {
        $cli = Console::create();

        $cli->command('styled-info', function () {
            Console::bold()->info('informativo');
            return 0;
        })->describe('Styled info');

        [, $stdout, $stderr] = dispatch($cli, 'styled-info');

        Test::assertStringContainsString('[INFO] informativo', $stdout);
        Test::assertSame('', $stderr);
    });

    $t->test('style helpers return styled strings', function () {
        $cli = Console::create();

        $cli->command('styled-inline', function () {
            $segment = Console::bold('fuerte');
            $combined = $segment . ' ' . Console::red('alerta');
            Console::log($combined);
            return 0;
        })->describe('Styled inline');

        [, $stdout] = dispatch($cli, 'styled-inline');

        Test::assertStringContainsString('fuerte', $stdout);
        Test::assertStringContainsString('alerta', $stdout);
    });

    $t->test('style builder requires message', function () {
        Test::expectException(\InvalidArgumentException::class, function () {
            Console::bold()->log();
        });
    });

    $t->test('help shows fallback when no commands registered', function () {
        $cli = Console::create();
        $reflection = new ReflectionClass(Console::class);
        $commands = $reflection->getProperty('commands');
        $commands->setAccessible(true);
        $commands->setValue($cli, []);

        $init = $reflection->getMethod('init');
        $init->setAccessible(true);
        $help = $reflection->getMethod('help');
        $help->setAccessible(true);
        $cleanup = $reflection->getMethod('cleanup');
        $cleanup->setAccessible(true);

        $stdout = fopen('php://temp', 'w+');
        $stderr = fopen('php://temp', 'w+');

        $init->invoke($cli, $stdout, $stderr);

        try {
            $exitCode = $help->invoke($cli);
        } finally {
            $cleanup->invoke($cli);
        }

        rewind($stdout);
        rewind($stderr);

        $out = stream_get_contents($stdout) ?: '';
        $err = stream_get_contents($stderr) ?: '';

        fclose($stdout);
        fclose($stderr);

        Test::assertSame(0, $exitCode);
        Test::assertSame('', $err);
        Test::assertStringContainsString('No hay comandos registrados.', $out);
    });

    $t->test('help for known command displays details', function () {
        $cli = Console::create();
        $cli->command('info', fn() => 0)->describe('Detalle completo');

        [$exitCode, $stdout, $stderr] = dispatch($cli, 'help', ['info']);

        Test::assertSame(0, $exitCode);
        Test::assertSame('', $stderr);
        Test::assertStringContainsString('Comando: info', $stdout);
        Test::assertStringContainsString('Descripción:', $stdout);
        Test::assertStringContainsString('Detalle completo', $stdout);
        Test::assertStringContainsString('Uso:', $stdout);
    });

    $t->test('dispatch handles exception with default handler', function () {
        $cli = Console::create();
        $cli->command('boom', function () {
            throw new RuntimeException('fallo');
        })->describe('Boom');

        [$exitCode, $stdout, $stderr] = dispatch($cli, 'boom');

        Test::assertSame(1, $exitCode);
        Test::assertSame('', $stdout);
        Test::assertStringContainsString('fallo', $stderr);
    });

    $t->test('dispatch without custom streams uses standard output', function () {
        $cli = Console::create();
        $cli->command('ping', fn() => 'pong')->describe('Ping');

        [$exitCode] = dispatch($cli, 'ping', [], false);

        Test::assertSame(0, $exitCode);
        Test::assertSame('console', Console::bin());
    });

    $t->test('dispatching empty command emits error', function () {
        $cli = Console::create();

        [$exitCode, $stdout, $stderr] = dispatch($cli, '', []);

        Test::assertSame(1, $exitCode);
        Test::assertStringContainsString('No se recibió ningún comando.', $stderr);
        Test::assertStringContainsString("Usa 'console help'", $stdout);
    });

    $t->test('log without initialization is no op', function () {
        Console::log('pre-init');

        $cli = Console::create();
        $cli->command('noop', fn() => 0)->describe('Noop');

        [$exitCode] = dispatch($cli, 'noop');

        Test::assertSame(0, $exitCode);
    });

    $t->test('cleanup closes owned streams', function () {
        $cli = Console::create();
        $reflection = new ReflectionClass(Console::class);

        $stdoutProp = $reflection->getProperty('stdout');
        $stdoutProp->setAccessible(true);
        $stderrProp = $reflection->getProperty('stderr');
        $stderrProp->setAccessible(true);
        $ownsStdout = $reflection->getProperty('ownsStdout');
        $ownsStdout->setAccessible(true);
        $ownsStderr = $reflection->getProperty('ownsStderr');
        $ownsStderr->setAccessible(true);
        $cleanup = $reflection->getMethod('cleanup');
        $cleanup->setAccessible(true);

        $stdout = fopen('php://temp', 'w+');
        $stderr = fopen('php://temp', 'w+');

        $stdoutProp->setValue(null, $stdout);
        $stderrProp->setValue(null, $stderr);
        $ownsStdout->setValue(null, true);
        $ownsStderr->setValue(null, true);

        $cleanup->invoke($cli);

        Test::assertFalse(is_resource($stdout));
        Test::assertFalse(is_resource($stderr));
    });
});

/**
 * @param array<int, string> $arguments
 * @return array{0:int,1:string,2:string}
 */
function dispatch(Console $cli, string $command, array $arguments = [], bool $captureStreams = true): array
{
    $hadArgv = array_key_exists('argv', $GLOBALS);
    $previousArgv = $hadArgv ? $GLOBALS['argv'] : null;
    $GLOBALS['argv'] = array_merge(['console', $command], $arguments);

    if ($captureStreams) {
        $stdout = fopen('php://temp', 'w+');
        $stderr = fopen('php://temp', 'w+');

        try {
            $exitCode = $cli->dispatch($command, $arguments, $stdout, $stderr);
        } finally {
            if ($hadArgv) {
                $GLOBALS['argv'] = $previousArgv;
            } else {
                unset($GLOBALS['argv']);
            }
        }

        foreach ([$stdout, $stderr] as $stream) {
            rewind($stream);
        }

        $out = stream_get_contents($stdout) ?: '';
        $err = stream_get_contents($stderr) ?: '';

        fclose($stdout);
        fclose($stderr);

        return [$exitCode, $out, $err];
    }

    ob_start();

    try {
        $exitCode = $cli->dispatch($command, $arguments);
    } finally {
        if ($hadArgv) {
            $GLOBALS['argv'] = $previousArgv;
        } else {
            unset($GLOBALS['argv']);
        }
    }

    $out = ob_get_clean() ?: '';

    return [$exitCode, $out, ''];
}
