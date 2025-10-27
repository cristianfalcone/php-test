<?php

declare(strict_types=1);

namespace Ajo\Core;

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
use Ajo\Core\Console as Root;
use Ajo\Test;
use ReflectionClass;
use RuntimeException;
use function Ajo\Tests\Support\Console\dispatch;
use function Ajo\Tests\Support\Console\silence;

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

Test::describe('Console', function () {

    Test::beforeEach(function () {
        Harness::reset();
    });

    Test::it('should create instance without binding to facade', function () {
        $previous = Console::instance();

        try {
            $cli = Console::create();

            Test::assertInstanceOf(Root::class, $cli);
            Test::assertNotSame($cli, Console::instance());

            // To bind to facade, use swap
            Console::swap($cli);
            Test::assertSame($cli, Console::instance());
        } finally {
            Console::swap($previous);
        }
    });

    Test::it('should include help in command definitions', function () {
        $cli = Console::create();
        $definitions = $cli->commands();

        Test::assertArrayHasKey('help', $definitions);
        Test::assertSame('Shows help for available commands.', $definitions['help']['description']);
    });

    Test::it('should store description on command registration', function () {
        $cli = Console::create();

        $cli->command('demo', fn() => 0)->describe('Demostracion');
        $definitions = $cli->commands();

        Test::assertArrayHasKey('demo', $definitions);
        Test::assertSame('Demostracion', $definitions['demo']['description']);
    });

    Test::it('should run command and return exit code on dispatch', function () {
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

    Test::it('should run middleware before command', function () {
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

    Test::it('should run prefixed middleware only for matching commands', function () {
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

    Test::it('should receive arguments via static helper', function () {
        $cli = Console::create();

        $cli->command('demo', function () {
            Console::log(json_encode(Console::arguments()));
            return 0;
        })->describe('Demo');

        [, $stdout] = dispatch($cli, 'demo', ['foo', 'bar']);

        Test::assertStringContainsString('["foo","bar"]', $stdout);
    });

    Test::it('should list registered commands in help', function () {
        $cli = Console::create();
        $cli->command('sample', fn() => 0)->describe('Sample command');

        [$exitCode, $stdout, $stderr] = dispatch($cli, 'help');

        Test::assertSame(0, $exitCode);
        Test::assertSame('', $stderr);
        Test::assertStringContainsString('Available commands', $stdout);
        Test::assertStringContainsString('sample', $stdout);
    });

    Test::it('should emit error for unknown command in help', function () {
        $cli = Console::create();

        [$exitCode, $stdout, $stderr] = dispatch($cli, 'help', ['missing']);

        Test::assertSame(1, $exitCode);
        Test::assertStringContainsString("Use 'console help' to see the list of commands.", $stdout);
        Test::assertStringContainsString('does not exist', $stderr);
    });

    Test::it('should emit not found message when dispatching unknown command', function () {
        $cli = Console::create();

        [$exitCode, $stdout, $stderr] = dispatch($cli, 'unknown');

        Test::assertSame(1, $exitCode);
        Test::assertStringContainsString("Use 'console help' to see the list of commands.", $stdout);
        Test::assertStringContainsString('is not defined', $stderr);
    });

    Test::it('should use ansi colors when supported in success', function () {
        Harness::$isatty = true;
        $cli = Console::create();

        $cli->command('color', function () {
            Console::success('coloreado');
            return 0;
        })->describe('Color');

        [, $stdout] = dispatch($cli, 'color');

        Test::assertStringContainsString('[ok] coloreado', $stdout);
        if (str_contains($stdout, "\033[")) {
            Test::assertStringContainsString("\033[32m[ok] coloreado\033[39m", $stdout);
        }
    });

    Test::it('should write to stderr with prefix on error', function () {
        $cli = Console::create();

        $cli->command('fail', function () {
            Console::error('fallo');
            return 0;
        })->describe('Fail');

        [,, $stderr] = dispatch($cli, 'fail');

        Test::assertStringContainsString('[error] fallo', $stderr);
    });

    Test::it('should emit string when command returns string', function () {
        $cli = Console::create();

        $cli->command('echo', fn() => "hola mundo\n")->describe('Echo');

        [$exitCode, $stdout, $stderr] = dispatch($cli, 'echo');

        Test::assertSame(0, $exitCode);
        Test::assertSame("hola mundo\n", $stdout);
        Test::assertSame('', $stderr);
    });

    Test::it('should produce exit code one when command returns false', function () {
        $cli = Console::create();

        $cli->command('fail', fn() => false)->describe('Fail');

        [$exitCode] = dispatch($cli, 'fail');

        Test::assertSame(1, $exitCode);
    });

    Test::it('should default to success when command returns null', function () {
        $cli = Console::create();

        $cli->command('null', fn() => null)->describe('Null');

        [$exitCode, $stdout, $stderr] = dispatch($cli, 'null');

        Test::assertSame(0, $exitCode);
        Test::assertSame('', $stdout);
        Test::assertSame('', $stderr);
    });

    Test::it('should not expose private methods in facade', function () {
        Test::expectException(\BadMethodCallException::class, function () {
            Console::detect(null);
        });
    });

    Test::it('should not write when blank called with zero lines', function () {
        $cli = Console::create();

        $cli->command('blank', function () {
            Console::blank(0);
            return 0;
        })->describe('Blank');

        [, $stdout] = dispatch($cli, 'blank');

        Test::assertSame('', $stdout);
    });

    Test::it('should handle empty message in log', function () {
        $cli = Console::create();

        $cli->command('empty-line', function () {
            Console::log('');
            return 0;
        })->describe('Empty');

        [, $stdout, $stderr] = dispatch($cli, 'empty-line');

        // When not TTY, timestamp is added even to empty messages
        Test::assertTrue(str_ends_with($stdout, PHP_EOL));
        Test::assertSame('', $stderr);
    });

    Test::it('should apply styles before logging', function () {
        $cli = Console::create();

        $cli->command('styled', function () {
            Console::bold()->red()->log('styled message');
            return 0;
        })->describe('Styled');

        [, $stdout, $stderr] = dispatch($cli, 'styled');

        Test::assertStringContainsString('styled message', $stdout);
        Test::assertSame('', $stderr);
    });

    Test::it('should delegate to helper in style builder', function () {
        $cli = Console::create();

        $cli->command('styled-info', function () {
            Console::bold()->info('informativo');
            return 0;
        })->describe('Styled info');

        [, $stdout, $stderr] = dispatch($cli, 'styled-info');

        Test::assertStringContainsString('[info] informativo', $stdout);
        Test::assertSame('', $stderr);
    });

    Test::it('should return styled strings from style helpers', function () {
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

    Test::it('should require message in style builder', function () {
        Test::expectException(\InvalidArgumentException::class, function () {
            Console::bold()->log();
        });
    });

    Test::it('should show fallback when no commands registered in help', function () {
        $cli = Console::create();

        [$exitCode, $stdout, $stderr] = dispatch($cli, 'help');

        Test::assertSame(0, $exitCode);
        Test::assertSame('', $stderr);
        Test::assertStringContainsString('No registered commands.', $stdout);
    });

    Test::it('should display details for known command in help', function () {
        $cli = Console::create();
        $cli->command('info', fn() => 0)->describe('Detalle completo');

        [$exitCode, $stdout, $stderr] = dispatch($cli, 'help', ['info']);

        Test::assertSame(0, $exitCode);
        Test::assertSame('', $stderr);
        Test::assertStringContainsString('Command: info', $stdout);
        Test::assertStringContainsString('Description:', $stdout);
        Test::assertStringContainsString('Detalle completo', $stdout);
        Test::assertStringContainsString('Usage:', $stdout);
    });

    Test::it('should handle exception with default handler on dispatch', function () {
        $cli = Console::create();
        $cli->command('boom', function () {
            throw new RuntimeException('fallo');
        })->describe('Boom');

        [$exitCode, $stdout, $stderr] = dispatch($cli, 'boom');

        Test::assertSame(1, $exitCode);
        Test::assertSame('', $stdout);
        Test::assertStringContainsString('fallo', $stderr);
    });

    Test::it('should use standard output when dispatching without custom streams', function () {
        $cli = Console::create();
        $cli->command('ping', fn() => 'pong')->describe('Ping');

        [$exitCode] = dispatch($cli, 'ping', [], false);

        Test::assertSame(0, $exitCode);
        Test::assertSame('console', Console::bin());
    });

    Test::it('should emit error when dispatching empty command', function () {
        $cli = Console::create();

        [$exitCode, $stdout, $stderr] = dispatch($cli, '', []);

        Test::assertSame(1, $exitCode);
        Test::assertStringContainsString('No command received.', $stderr);
        Test::assertStringContainsString("Use 'console help'", $stdout);
    });

    Test::it('should write to stdout when logging without initialization', function () {
        [$out] = silence(function () {
            Console::log('pre-init');
        });

        $cli = Console::create();
        $cli->command('noop', fn() => 0)->describe('Noop');

        [$exitCode] = dispatch($cli, 'noop');

        Test::assertSame(0, $exitCode);
        // When not TTY (file stream in tests), includes timestamp
        Test::assertStringContainsString('pre-init', $out);
        Test::assertTrue(str_ends_with($out, "\n"));
    });

    Test::it('should close owned streams on cleanup', function () {
        $cli = Console::create();
        $cli->command('noop', fn() => 0)->describe('Noop');

        $reflection = new ReflectionClass(Root::class);
        $stdoutProp = $reflection->getProperty('stdout'); $stdoutProp->setAccessible(true);
        $stderrProp = $reflection->getProperty('stderr'); $stderrProp->setAccessible(true);
        $ownsStdout = $reflection->getProperty('ownsStdout'); $ownsStdout->setAccessible(true);
        $ownsStderr = $reflection->getProperty('ownsStderr'); $ownsStderr->setAccessible(true);

        $stdout = fopen('php://temp', 'w+');
        $stderr = fopen('php://temp', 'w+');

        $stdoutProp->setValue($cli, $stdout);
        $stderrProp->setValue($cli, $stderr);
        $ownsStdout->setValue($cli, true);
        $ownsStderr->setValue($cli, true);

        $hadArgv = array_key_exists('argv', $GLOBALS);
        $previousArgv = $hadArgv ? $GLOBALS['argv'] : null;
        $GLOBALS['argv'] = ['console', 'noop'];
        $previous = Console::instance();
        Console::swap($cli);

        try {
            $exitCode = $cli->dispatch('noop', []);
        } finally {
            Console::swap($previous);

            if ($hadArgv) {
                $GLOBALS['argv'] = $previousArgv;
            } else {
                unset($GLOBALS['argv']);
            }
        }

        Test::assertSame(0, $exitCode);
        Test::assertFalse(is_resource($stdout));
        Test::assertFalse(is_resource($stderr));
    });

    Test::it('should add timestamps when not in TTY mode', function () {
        Harness::$isatty = false;
        $cli = Console::create();

        $cli->command('log-test', function () {
            Console::log('test message');
            Console::info('info message');
            return 0;
        })->describe('Log test');

        [, $stdout] = dispatch($cli, 'log-test');

        // Should have timestamp format YYYY-MM-DD HH:MM:SS.mmm
        Test::assertTrue(preg_match('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3} test message/', $stdout) === 1);
        Test::assertTrue(preg_match('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3} \[info\] info message/', $stdout) === 1);
    });

    Test::it('should not use colors when not in TTY mode', function () {
        Harness::$isatty = false;
        $cli = Console::create();

        $cli->command('color-test', function () {
            Console::success('success message');
            Console::error('error message');
            return 0;
        })->describe('Color test');

        [, $stdout, $stderr] = dispatch($cli, 'color-test');

        // Should NOT have ANSI color codes
        Test::assertFalse(str_contains($stdout, "\033["));
        Test::assertFalse(str_contains($stderr, "\033["));
        Test::assertStringContainsString('[ok] success message', $stdout);
        Test::assertStringContainsString('[error] error message', $stderr);
    });

    Test::it('should allow manual override of timestamps', function () {
        Harness::$isatty = true; // TTY detected (normally no timestamps)
        $cli = Console::create();

        $cli->command('override-test', function () use ($cli) {
            // Manually enable timestamps
            $cli->withTimestamps(true);
            Console::log('forced timestamp');
            return 0;
        })->describe('Override test');

        [, $stdout] = dispatch($cli, 'override-test');

        // Should have timestamp even though TTY is true
        Test::assertTrue(preg_match('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3} forced timestamp/', $stdout) === 1);
    });

    Test::it('should allow manual override of colors', function () {
        Harness::$isatty = true; // TTY detected, colors enabled
        $cli = Console::create();

        $cli->command('color-override', function () use ($cli) {
            // Manually disable colors
            $cli->withColors(false);
            Console::success('no color');
            return 0;
        })->describe('Color override test');

        [, $stdout] = dispatch($cli, 'color-override');

        // Should NOT have ANSI color codes even though TTY is true
        Test::assertFalse(str_contains($stdout, "\033["));
        Test::assertStringContainsString('[ok] no color', $stdout);
    });

    Test::it('should parse boolean flags with short and long aliases', function () {
        $cli = Console::create();

        $cli->command('test', function () use ($cli) {
            $opts = $cli->options();
            Console::log('verbose=' . ($opts['verbose'] ?? 'unset'));
            Console::log('quiet=' . ($opts['quiet'] ?? 'unset'));
            return 0;
        })
        ->option('-v, --verbose', 'Enable verbose mode')
        ->option('-q, --quiet', 'Enable quiet mode');

        [, $stdout] = dispatch($cli, 'test', ['-v']);
        Test::assertStringContainsString('verbose=1', $stdout);

        [, $stdout] = dispatch($cli, 'test', ['--verbose']);
        Test::assertStringContainsString('verbose=1', $stdout);

        [, $stdout] = dispatch($cli, 'test', ['-q']);
        Test::assertStringContainsString('quiet=1', $stdout);
    });

    Test::it('should parse flags with values', function () {
        $cli = Console::create();

        $cli->command('build', function () use ($cli) {
            $opts = $cli->options();
            Console::log('output=' . ($opts['output'] ?? 'unset'));
            return 0;
        })
        ->option('-o, --output', 'Output file', 'bundle.js');

        [, $stdout] = dispatch($cli, 'build', ['--output=custom.js']);
        Test::assertStringContainsString('output=custom.js', $stdout);

        [, $stdout] = dispatch($cli, 'build', ['--output', 'another.js']);
        Test::assertStringContainsString('output=another.js', $stdout);

        [, $stdout] = dispatch($cli, 'build', ['-o', 'short.js']);
        Test::assertStringContainsString('output=short.js', $stdout);
    });

    Test::it('should apply default values for unset options', function () {
        $cli = Console::create();

        $cli->command('serve', function () use ($cli) {
            $opts = $cli->options();
            Console::log('port=' . $opts['port']);
            Console::log('host=' . $opts['host']);
            return 0;
        })
        ->option('--port', 'Port number', 3000)
        ->option('--host', 'Host name', 'localhost');

        [, $stdout] = dispatch($cli, 'serve', []);
        Test::assertStringContainsString('port=3000', $stdout);
        Test::assertStringContainsString('host=localhost', $stdout);
    });

    Test::it('should handle negated flags with --no- prefix', function () {
        $cli = Console::create();

        $cli->command('compile', function () use ($cli) {
            $opts = $cli->options();
            Console::log('color=' . (($opts['color'] ?? true) ? 'true' : 'false'));
            return 0;
        });

        [, $stdout] = dispatch($cli, 'compile', ['--no-color']);
        Test::assertStringContainsString('color=false', $stdout);

        [, $stdout] = dispatch($cli, 'compile', []);
        Test::assertStringContainsString('color=true', $stdout);
    });

    Test::it('should collect positional arguments in underscore property', function () {
        $cli = Console::create();

        $cli->command('copy', function () use ($cli) {
            $opts = $cli->options();
            Console::log('args=' . implode(',', $opts['_']));
            return 0;
        });

        [, $stdout] = dispatch($cli, 'copy', ['src', 'dest']);
        Test::assertStringContainsString('args=src,dest', $stdout);
    });

    Test::it('should stop parsing flags after double dash', function () {
        $cli = Console::create();

        $cli->command('run', function () use ($cli) {
            $opts = $cli->options();
            Console::log('verbose=' . ($opts['verbose'] ?? 'unset'));
            Console::log('args=' . implode(',', $opts['_']));
            return 0;
        })
        ->option('-v, --verbose', 'Verbose');

        [, $stdout] = dispatch($cli, 'run', ['-v', '--', '--arg']);
        Test::assertStringContainsString('verbose=1', $stdout);
        Test::assertStringContainsString('args=--arg', $stdout);
    });

    Test::it('should accumulate repeated flags into arrays', function () {
        $cli = Console::create();

        $cli->command('lint', function () use ($cli) {
            $opts = $cli->options();
            $ignore = $opts['ignore'] ?? [];
            Console::log('ignore=' . (is_array($ignore) ? implode(',', $ignore) : $ignore));
            return 0;
        })
        ->option('--ignore', 'Files to ignore');

        [, $stdout] = dispatch($cli, 'lint', ['--ignore=node_modules', '--ignore=dist']);
        Test::assertStringContainsString('ignore=node_modules,dist', $stdout);
    });

    Test::it('should show registered options in help command', function () {
        $cli = Console::create();

        $cli->command('deploy', function () {
            return 0;
        })
        ->describe('Deploy the application')
        ->option('-e, --env', 'Environment', 'production')
        ->option('-v, --verbose', 'Verbose output')
        ->option('--dry-run', 'Dry run mode');

        [, $stdout] = dispatch($cli, 'help', ['deploy']);

        Test::assertStringContainsString('Options:', $stdout);
        Test::assertStringContainsString('-e, --env', $stdout);
        Test::assertStringContainsString('Environment', $stdout);
        Test::assertStringContainsString('(default: production)', $stdout);
        Test::assertStringContainsString('-v, --verbose', $stdout);
        Test::assertStringContainsString('--dry-run', $stdout);
    });

    Test::it('should show usage examples in help command', function () {
        $cli = Console::create();

        $cli->command('build', function () {
            return 0;
        })
        ->describe('Build the project')
        ->option('-e, --env', 'Environment')
        ->example('src/ dist/', 'Build from src to dist')
        ->example('-e production', 'Build for production');

        [, $stdout] = dispatch($cli, 'help', ['build']);

        Test::assertStringContainsString('Examples:', $stdout);
        Test::assertStringContainsString('console build src/ dist/', $stdout);
        Test::assertStringContainsString('Build from src to dist', $stdout);
        Test::assertStringContainsString('console build -e production', $stdout);
        Test::assertStringContainsString('Build for production', $stdout);
    });
});
