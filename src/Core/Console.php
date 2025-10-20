<?php

declare(strict_types=1);

namespace Ajo\Core;

use BadMethodCallException;
use InvalidArgumentException;
use LogicException;
use ReflectionMethod;
use Throwable;

/**
 * Minimalist CLI with middlewares and ANSI styles.
 *
 * Example:
 * ```php
 *   $cli = Console::create();
 *   $cli->command('ping', fn() => Console::success('pong'));
 *   exit($cli->dispatch());
 * ```
 */
final class Console extends Router
{
    /** @var array<string, array<string, mixed>> */
    private array $commands = [];
    private ?string $active = null;

    private string $bin = 'console';
    private string $command = '';

    /** @var array<int, string> */
    private array $arguments = [];

    /** @var resource|null */
    private $stdout = null;
    private bool $ownsStdout = false;

    /** @var resource|null */
    private $stderr = null;
    private bool $ownsStderr = false;

    private bool $supportsColors = false;

    /** @var array<string, array{0:string,1:string}> */
    private const STYLES = [
        'reset'         => ['0',  '0'],
        'bold'          => ['1', '22'],
        'dim'           => ['2', '22'],
        'italic'        => ['3', '23'],
        'underline'     => ['4', '24'],
        'inverse'       => ['7', '27'],
        'hidden'        => ['8', '28'],
        'strikethrough' => ['9', '29'],
        'black'         => ['30', '39'],
        'red'           => ['31', '39'],
        'green'         => ['32', '39'],
        'yellow'        => ['33', '39'],
        'blue'          => ['34', '39'],
        'magenta'       => ['35', '39'],
        'cyan'          => ['36', '39'],
        'white'         => ['37', '39'],
        'bgBlack'       => ['40', '49'],
        'bgRed'         => ['41', '49'],
        'bgGreen'       => ['42', '49'],
        'bgYellow'      => ['43', '49'],
        'bgBlue'        => ['44', '49'],
        'bgMagenta'     => ['45', '49'],
        'bgCyan'        => ['46', '49'],
        'bgWhite'       => ['47', '49'],
    ];

    public function __construct(
        ?callable $notFoundHandler = null,
        ?callable $exceptionHandler = null,
    ) {
        parent::__construct($notFoundHandler, $exceptionHandler);

        $this->command('help', function () {

            $target = $this->arguments()[0] ?? null;

            if ($target === null) {

                $commands = $this->commands;
                unset($commands['help']);

                if ($commands === []) {
                    $this->log('No registered commands.');
                    return 0;
                }

                $names = array_keys($commands);
                sort($names, SORT_STRING);
                $width = max(array_map('strlen', $names));

                $this->log('Available commands:'); $this->blank();

                foreach ($names as $name) {

                    $description = $commands[$name]['description'] ?? '';
                    $padding = max(1, $width - strlen($name) + 2);

                    $this->log($description === '' ? $name : $name . str_repeat(' ', $padding) . $description);
                }

                $this->blank(); $this->log(sprintf("Use '%s help <command>' for more details.", $this->bin()));

                return 0;
            }

            $command = $this->normalize($target);
            $description = $this->commands[$command]['description'] ?? null;

            if ($description === null) {

                $this->error(sprintf("Command '%s' does not exist.", $command));
                $this->log(sprintf("Use '%s help' to see the list of commands.", $this->bin()));

                return 1;
            }

            $this->log(sprintf('Command: %s', $command));

            if ($description !== '') {
                $this->blank();
                $this->log('Description:');
                $this->log('  ' . $description);
            }

            $this->blank();
            $this->log('Usage:');
            $this->log(sprintf('  %s %s', $this->bin(), $command));

            return 0;

        })->describe('Shows help for available commands.');
    }

    /** Registers a command in the console. */
    public function command(string $name, callable $handler)
    {
        $command = $this->normalize($name);

        $this->commands[$command] = [
            'description' => '',
            'handler' => $this->adapt($handler),
        ];

        $this->active = $command;

        return $this;
    }

    /** Sets the description of the active command. */
    public function describe(string $description)
    {
        if ($this->active === null) throw new LogicException('No active command. Call command() before describe().');

        $this->commands[$this->active]['description'] = trim($description);

        return $this;
    }

    /** Returns the list of registered commands. */
    public function commands()
    {
        return $this->commands;
    }

    /**
     * Detects and executes the corresponding command.
     *
     * @param array<int, string> $arguments
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public function dispatch(?string $command = null, array $arguments = [], $stdout = null, $stderr = null)
    {
        global $argv;

        $this->bin = basename($argv[0] ?? 'console');
        $this->command = $this->normalize($command ?? $argv[1] ?? 'help');
        $this->arguments = $arguments !== [] ? array_values($arguments) : (isset($argv) && is_array($argv) ? array_slice($argv, 2) : []);

        $stdout !== null ? $this->stream('stdout', $stdout, false) : $this->stream('stdout');
        $stderr !== null ? $this->stream('stderr', $stderr, false) : $this->stream('stderr');

        try {
            $handler = $this->commands[$this->command]['handler'] ?? null;
            $stack = [...$this->stack($this->command), $handler === null ? $this->notFoundHandler : $handler];
            $response = $this->run($stack);
        } catch (Throwable $throwable) {
            $response = ($this->exceptionHandler)($throwable);
        }

        if (is_string($response) && $response !== '') {

            $stream = $this->stream('stdout');

            if ($stream) {
                fwrite($stream, str_ends_with($response, PHP_EOL) ? $response : $response . PHP_EOL);
            }
        }

        $code = match (true) {
            $response === null => 0,
            is_int($response) => $response,
            $response === false => 1,
            default => 0,
        };

        $this->closeStream($this->stdout, $this->ownsStdout);
        $this->closeStream($this->stderr, $this->ownsStderr);
        $this->supportsColors = false;
        $this->command = '';
        $this->arguments = [];

        return $code;
    }

    /** Returns the command arguments. */
    public function arguments()
    {
        return $this->arguments;
    }

    /** Returns the binary name. */
    public function bin()
    {
        return $this->bin;
    }

    /** Generates a blank line. */
    public function blank(int $lines = 1)
    {
        if ($lines <= 0) return;
        for ($index = 0; $index < $lines; $index++) $this->log();
    }

    /** Logs a message to standard output. */
    public function log(string $message = '')
    {
        $this->write($message);
    }

    /**
     * Renders a formatted table with automatic column width calculation.
     *
     * Column alignment can be controlled by prefixing the header with:
     * - `>Header` for right alignment
     * - `<Header` for left alignment (default)
     *
     * Automatically strips ANSI codes when calculating widths.
     *
     * @param array<string, string> $columns Map of key => header (e.g., ['name' => 'Name', 'count' => '>Count'])
     * @param array<int, array<string, mixed>> $rows Rows with values indexed by column keys
     */
    public function table(array $columns, array $rows): void
    {
        if ($rows === []) return;

        // Parse columns: ['key' => 'Header'] or ['key' => '<Header'] or ['key' => '>Header']
        $keys = $headers = $aligns = [];
        foreach ($columns as $key => $header) {
            $keys[] = (string)$key;
            $align = $header[0] ?? '';
            $headers[] = match ($align) {
                '<', '>' => substr($header, 1),
                default => $header,
            };
            $aligns[] = match ($align) {
                '>' => 'right',
                default => 'left',
            };
        }

        // Calculate column widths (strip ANSI codes for accurate width)
        $stripAnsi = fn($s) => preg_replace('/\e\[[0-9;]*m/', '', $s);
        $widths = array_map(fn($h) => strlen($stripAnsi($h)), $headers);

        foreach ($rows as $row) {
            foreach ($keys as $i => $key) {
                $value = (string)($row[$key] ?? '-');
                $widths[$i] = max($widths[$i], strlen($stripAnsi($value)));
            }
        }

        // Render table with bold headers
        $renderRow = function($values) use ($widths, $aligns, $stripAnsi) {
            $parts = [];
            foreach ($values as $i => $value) {
                $cleanLength = strlen($stripAnsi($value));
                $padding = $widths[$i] - $cleanLength;
                $parts[] = $aligns[$i] === 'right'
                    ? str_repeat(' ', $padding) . $value
                    : $value . str_repeat(' ', $padding);
            }
            return implode('  ', $parts);
        };

        $this->bold()->log($renderRow($headers));
        foreach ($rows as $row) {
            $this->log($renderRow(array_map(fn($key) => (string)($row[$key] ?? '-'), $keys)));
        }
    }

    /** Logs a success message. */
    public function success(string $message)
    {
        $this->green()->log('[OK] ' . $message);
    }

    /** Logs an informational message. */
    public function info(string $message)
    {
        $this->cyan()->log('[INFO] ' . $message);
    }

    /** Logs a warning message. */
    public function warn(string $message)
    {
        $this->yellow()->write('[WARN] ' . $message, $this->stream('stderr'));
    }

    /** Logs an error message. */
    public function error(string $message)
    {
        $this->red()->write('[ERROR] ' . $message, $this->stream('stderr'));
    }

    public function write(string $message, $stream = null, bool $inPlace = false)
    {
        $stream = $this->validStream($stream) ?? $this->stream('stdout');

        if (!$stream) return;

        if ($inPlace) {
            fwrite($stream, "\r\033[2K" . $message);
            fflush($stream);
            return;
        }

        if ($message === '') {
            fwrite($stream, PHP_EOL);
            return;
        }

        $lines = preg_split('/\r?\n/', $message);

        if ($lines === false || $lines === []) {
            fwrite($stream, PHP_EOL);
            return;
        }

        foreach ($lines as $line) fwrite($stream, $line . PHP_EOL);
    }

    public function progress(int $current, int $total, string $label = '', array $breakdown = [])
    {
        if ($total === 0) return;

        $percent = (int)(($current / $total) * 100);
        $line = sprintf('%s %d/%d (%d%%)', $label, $current, $total, $percent);

        if ($breakdown !== []) {
            $parts = [];
            foreach ($breakdown as $name => $counts) {
                if (!is_array($counts) || count($counts) < 2) continue;
                $parts[] = sprintf('%s: %d/%d', $name, $counts[0], $counts[1]);
            }
            if ($parts !== []) {
                $line .= ' │ ' . implode(' │ ', array_slice($parts, 0, 4));
            }
        }

        $this->write($line, null, true);
    }

    public function __call(string $name, array $arguments)
    {
        if (!isset(self::STYLES[$name])) {
            throw new BadMethodCallException(sprintf("Method '%s' is not defined.", $name));
        }

        if ($arguments !== [] && is_string($arguments[0])) {
            return $this->styled($arguments[0], [self::STYLES[$name]]);
        }

        $formatter = fn(string $text, array $codes): string => $this->styled($text, $codes);

        return new class([self::STYLES[$name]], self::STYLES, $formatter, $this) {

            /** @var array<int, array{0:string, 1:string}> */
            private array $codes;

            /** @var array<string, array{0:string, 1:string}> */
            private array $styles;

            /** @var callable */
            private $formatter;

            private Console $console;

            public function __construct(array $codes, array $styles, callable $formatter, Console $console)
            {
                $this->codes = $codes;
                $this->styles = $styles;
                $this->formatter = $formatter;
                $this->console = $console;
            }

            public function __call(string $name, array $arguments)
            {
                if (isset($this->styles[$name])) {

                    $codes = [...$this->codes, $this->styles[$name]];

                    if ($arguments !== [] && is_string($arguments[0])) {
                        return ($this->formatter)($arguments[0], $codes);
                    }

                    return new self($codes, $this->styles, $this->formatter, $this->console);
                }

                return $this->dispatch($name, $arguments);
            }

            private function dispatch(string $method, array $arguments)
            {
                if (!method_exists($this->console, $method)) {
                    throw new BadMethodCallException(sprintf("Method '%s' is not available for styling.", $method));
                }

                $message = $arguments[0] ?? null;

                if (!is_string($message)) {
                    throw new InvalidArgumentException(sprintf("Method '%s' requires a message.", $method));
                }

                $reflection = new ReflectionMethod($this->console, $method);
                $reflection->setAccessible(true);
                $reflection->invoke($this->console, ($this->formatter)($message, $this->codes), ...array_slice($arguments, 1));
            }
        };
    }

    private function detect($stream)
    {
        if (PHP_OS_FAMILY === 'Windows' && function_exists('sapi_windows_vt100_support')) {
            @sapi_windows_vt100_support($stream, true);
        }

        return function_exists('stream_isatty') && @stream_isatty($stream);
    }

    private function validStream($value)
    {
        return is_resource($value) ? $value : null;
    }

    private function stream(string $channel, $resource = null, bool $owns = false)
    {
        $isStdout = $channel === 'stdout';

        if ($isStdout) {
            $handle =& $this->stdout;
            $flag =& $this->ownsStdout;
        } else {
            $handle =& $this->stderr;
            $flag =& $this->ownsStderr;
        }

        if ($resource !== null) {
            $this->closeStream($handle, $flag);
            $handle = $this->validStream($resource);
            $flag = $handle !== null && $owns;
        }

        if (!$this->validStream($handle)) {

            $builtin = $isStdout ? (defined('STDOUT') ? STDOUT : null) : (defined('STDERR') ? STDERR : null);

            if ($this->validStream($builtin)) {
                $handle = $builtin;
                $flag = false;
            } else {
                $fallback = $this->validStream(@fopen($isStdout ? 'php://stdout' : 'php://stderr', 'w'));
                $handle = $fallback;
                $flag = $fallback !== null;
            }
        }

        if ($isStdout) $this->supportsColors = $this->validStream($handle) && $this->detect($handle);

        return $this->validStream($handle) ? $handle : null;
    }

    private function closeStream(&$stream, bool &$owns): void
    {
        if ($owns && is_resource($stream)) fclose($stream);

        $stream = null;
        $owns = false;
    }

    private function styled(string $message, array $codes)
    {
        if ($codes === []) return $message;
        if (!$this->supportsColors) $this->stream('stdout');
        if (!$this->supportsColors) return $message;

        $open = '';
        $close = '';

        foreach ($codes as $code) {
            $open .= "\033[" . $code[0] . 'm';
            $close = "\033[" . $code[1] . 'm' . $close;
        }

        return $open . $message . $close;
    }

    protected function defaultNotFound(): callable
    {
        return function () {

            $this->error($this->command === '' ? 'No command received.' : sprintf("Command '%s' is not defined.", $this->command));
            $this->log(sprintf("Use '%s help' to see the list of commands.", $this->bin()));

            return 1;
        };
    }

    protected function defaultException(): callable
    {
        return function (Throwable $exception) {
            $this->error($exception->getMessage());
            return 1;
        };
    }

    protected function matches(string $prefix, string $target): bool
    {
        return $prefix === '' || $target === $prefix || str_starts_with($target, $prefix . ':');
    }

    protected function normalize(string $command): string
    {
        return trim(preg_replace('/:+/', ':', $command) ?? $command, ':');
    }
}
