<?php

declare(strict_types=1);

namespace Ajo;

use BadMethodCallException;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * CLI minimalista con middlewares, stack y estilos ANSI.
 *
 * Características extras:
 * - `->use($prefix, ...) ` registra middlewares antes de los comandos (globales si se usa sin prefijo)
 * - `Console::dim|bold|italic|underline|yellow|bgRed|...` aplican estilos ANSI a un texto.
 *
 * ```php
 * $cli = Console::create();
 *
 * $cli->use(fn () => Context::set('db', Database::get()));
 *
 * $cli->command('report:run', function () {
 *     Console::info('Generando reporte...');
 *     sleep(1);
 *     Console::success('Reporte listo.');
 * })->describe('Genera un reporte.');
 *
 * $cli->use('jobs', function (callable $next) {
 *     $start = microtime(true);
 *     try {
 *         return $next();
 *     } finally {
 *         Console::dim()->log(sprintf('jobs terminó en %.2f ms', (microtime(true) - $start) * 1000));
 *     }
 * });
 *
 * $cli->command('jobs:collect', function () {
 *     Console::log('[jobs] ' . (new DateTimeImmutable())->format(DateTimeInterface::ATOM));
 *     return 0;
 * })->describe('Recoge trabajos en segundo plano.');
 *
 * $cli->command('migrate:make', fn(string $name = null) => Console::success("Stub creado: {$name}"));
 *
 * exit($cli->dispatch());
 * ```
 */
final class Console extends Router
{
    /** @var array<string, array<string, mixed>> */
    private array $commands = [];
    private ?string $active = null;

    private static string $bin = 'console';
    private static string $command = '';

    /** @var array<int, string> */
    private static array $arguments = [];

    /** @var resource|null */
    private static $stdout = null;
    private static bool $ownsStdout = false;

    /** @var resource|null */
    private static $stderr = null;
    private static bool $ownsStderr = false;

    private static bool $supportsColors = false;

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

        $this->command('help', fn() => $this->help())
            ->describe('Muestra la ayuda de los comandos disponibles.');
    }

    /**
     * Registra un comando en la consola.
     */
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

    /**
     * Establece la descripción del comando activo.
     */
    public function describe(string $description)
    {
        if ($this->active === null) {
            throw new LogicException('No hay comando activo. Llama a command() antes de describe().');
        }

        $this->commands[$this->active]['description'] = trim($description);

        return $this;
    }

    /**
     * Devuelve la lista de comandos registrados.
     */
    public function commands()
    {
        return $this->commands;
    }

    /**
     * Detecta y ejecuta el comando correspondiente.
     * 
     * @param array<int, string> $arguments
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public function dispatch(?string $command = null, array $arguments = [], $stdout = null, $stderr = null)
    {
        global $argv;

        self::$bin = basename($argv[0] ?? 'console');
        self::$command = $this->normalize($command ?? $argv[1] ?? 'help');
        self::$arguments = $arguments !== [] ? array_values($arguments) : (isset($argv) && is_array($argv) ? array_slice($argv, 2) : []);

        $this->init($stdout, $stderr);

        try {
            $response = $this->run($this->build(self::$command));
        } catch (Throwable $throwable) {
            $response = ($this->exceptionHandler)($throwable);
        }

        $code = $this->emit($response);

        $this->cleanup();

        return $code;
    }

    /**
     * Devuelve los argumentos del comando.
     */
    public static function arguments()
    {
        return self::$arguments;
    }

    /**
     * Devuelve el nombre del binario.
     */
    public static function bin()
    {
        return self::$bin;
    }

    /**
     * Genera una línea en blanco.
     */
    public static function blank(int $lines = 1)
    {
        if ($lines <= 0) {
            return;
        }

        for ($index = 0; $index < $lines; $index++) {
            self::log();
        }
    }

    /**
     * Registra un mensaje en la salida estándar.
     */
    public static function log(string $message = '')
    {
        self::write($message);
    }

    /**
     * Renderiza una tabla con columnas alineadas.
     *
     * @param array<int|string, string> $columns Columnas como key => encabezado o lista de claves.
     * @param array<int, array<string, mixed>> $rows Filas con valores indexados por las claves.
     */
    public static function table(array $columns, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        if (array_is_list($columns)) {
            $keys = array_map('strval', $columns);
            $headers = array_map('strval', $columns);
        } else {
            $keys = array_map('strval', array_keys($columns));
            $headers = array_map('strval', array_values($columns));
        }

        $widths = [];

        foreach ($headers as $index => $label) {
            $widths[$index] = strlen($label);
        }

        foreach ($rows as $row) {
            foreach ($keys as $index => $key) {
                $value = (string)($row[$key] ?? '-');
                $widths[$index] = max($widths[$index], strlen($value));
            }
        }

        $format = implode('  ', array_map(
            static fn(int $width): string => '%-' . $width . 's',
            $widths,
        ));

        self::log(vsprintf($format, $headers));

        foreach ($rows as $row) {

            $values = [];

            foreach ($keys as $key) {
                $values[] = (string)($row[$key] ?? '-');
            }

            self::log(vsprintf($format, $values));
        }
    }

    /**
     * Registra un mensaje de éxito en la salida estándar.
     */
    public static function success(string $message)
    {
        self::green()->log('[OK] ' . $message);
    }

    /**
     * Registra un mensaje de información en la salida estándar.
     */
    public static function info(string $message)
    {
        self::cyan()->log('[INFO] ' . $message);
    }

    /**
     * Registra un mensaje de advertencia en la salida de error estándar.
     */
    public static function warn(string $message)
    {
        self::yellow()->write('[WARN] ' . $message, self::$stderr);
    }

    /**
     * Registra un mensaje de error en la salida de error estándar.
     */
    public static function error(string $message)
    {
        self::red()->write('[ERROR] ' . $message, self::$stderr);
    }

    public static function write(string $message, $stream = null)
    {
        $stream = is_resource($stream) ? $stream : (is_resource(self::$stdout) ? self::$stdout : null);

        if (!is_resource($stream)) {
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

        foreach ($lines as $line) {
            fwrite($stream, $line . PHP_EOL);
        }
    }

    public static function __callStatic(string $name, array $arguments)
    {
        if (!isset(self::STYLES[$name])) {
            throw new BadMethodCallException(sprintf("El método '%s' no está definido.", $name));
        }

        if ($arguments !== [] && is_string($arguments[0])) {
            return self::styled($arguments[0], [self::STYLES[$name]]);
        }

        return new class([self::STYLES[$name]], self::STYLES, fn(...$args) => self::styled(...$args)) {

            /** @var array<int, array{0:string, 1:string}> */
            private array $codes;

            /** @var array<string, array{0:string, 1:string}> */
            private array $styles;

            /** @var callable */
            private $formatter;

            public function __construct(array $codes, array $styles, callable $formatter)
            {
                $this->codes = $codes;
                $this->styles = $styles;
                $this->formatter = $formatter;
            }

            public function __call(string $name, array $arguments)
            {
                if (isset($this->styles[$name])) {

                    $codes = [...$this->codes, $this->styles[$name]];

                    if ($arguments !== [] && is_string($arguments[0])) {
                        return ($this->formatter)($arguments[0], $codes);
                    }

                    return new self($codes, $this->styles, $this->formatter);
                }

                return $this->dispatch($name, $arguments);
            }

            private function dispatch(string $method, array $arguments)
            {
                if (!method_exists(Console::class, $method)) {
                    throw new BadMethodCallException(sprintf("Método '%s' no está disponible para estilos.", $method));
                }

                $message = $arguments[0] ?? null;

                if (!is_string($message)) {
                    throw new InvalidArgumentException(sprintf("Método '%s' requiere un mensaje.", $method));
                }

                Console::$method(($this->formatter)($message, $this->codes), ...array_slice($arguments, 1));
            }
        };
    }

    private function build(string $command)
    {
        $handler = $this->commands[$command]['handler'] ?? null;
        return [...$this->stack($command), $handler === null ? $this->notFoundHandler : $handler];
    }

    private function help()
    {
        $target = self::arguments()[0] ?? null;

        if ($target === null) {

            if ($this->commands === []) {
                self::log('No hay comandos registrados.');
                return 0;
            }

            $names = array_keys($this->commands);
            sort($names, SORT_STRING);
            $width = max(array_map('strlen', $names));

            self::log('Comandos disponibles:');
            self::blank();

            foreach ($names as $name) {
                $description = $this->commands[$name]['description'] ?? '';
                $padding = max(1, $width - strlen($name) + 2);
                self::log($description === '' ? $name : $name . str_repeat(' ', $padding) . $description);
            }

            self::blank();
            self::log(sprintf("Usa '%s help <comando>' para más detalles.", self::bin()));

            return 0;
        }

        $command = $this->normalize($target);
        $description = $this->commands[$command]['description'] ?? null;

        if ($description === null) {

            self::error(sprintf("El comando '%s' no existe.", $command));
            self::log(sprintf("Usa '%s help' para ver la lista de comandos.", self::bin()));

            return 1;
        }

        self::log(sprintf('Comando: %s', $command));

        if ($description !== '') {
            self::blank();
            self::log('Descripción:');
            self::log('  ' . $description);
        }

        self::blank();
        self::log('Uso:');
        self::log(sprintf('  %s %s', self::bin(), $command));

        return 0;
    }

    private function emit(mixed $response)
    {
        if (is_string($response) && $response !== '') {
            fwrite(self::$stdout, str_ends_with($response, PHP_EOL) ? $response : $response . PHP_EOL);
        }

        return match (true) {
            $response === null => 0,
            is_int($response) => $response,
            $response === false => 1,
            default => 0,
        };
    }

    private function init($stdout = null, $stderr = null)
    {
        if ($stdout !== null) {
            self::$stdout = $stdout;
            self::$ownsStdout = false;
        } elseif (self::$stdout === null) {

            if (defined('STDOUT')) {
                self::$stdout = STDOUT;
            } else {
                self::$stdout = fopen('php://stdout', 'w');
                self::$ownsStdout = true;
            }
        }

        if ($stderr !== null) {
            self::$stderr = $stderr;
            self::$ownsStderr = false;
        } elseif (self::$stderr === null) {

            if (defined('STDERR')) {
                self::$stderr = STDERR;
            } else {
                self::$stderr = fopen('php://stderr', 'w');
                self::$ownsStderr = true;
            }
        }

        self::$supportsColors = is_resource(self::$stdout) && self::detect(self::$stdout);
    }

    private function cleanup()
    {
        if (self::$ownsStdout && is_resource(self::$stdout)) {
            fclose(self::$stdout);
        }

        if (self::$ownsStderr && is_resource(self::$stderr)) {
            fclose(self::$stderr);
        }

        self::$stdout = null;
        self::$stderr = null;
        self::$ownsStdout = false;
        self::$ownsStderr = false;
        self::$supportsColors = false;
        self::$command = '';
        self::$arguments = [];
    }

    private static function detect($stream)
    {
        if (PHP_OS_FAMILY === 'Windows' && function_exists('sapi_windows_vt100_support')) {
            @sapi_windows_vt100_support($stream, true);
        }

        return function_exists('stream_isatty') && @stream_isatty($stream);
    }

    private static function styled(string $message, array $codes)
    {
        if (!self::$supportsColors || $codes === []) {
            return $message;
        }

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

            self::error(self::$command === '' ? 'No se recibió ningún comando.' : sprintf("El comando '%s' no está definido.", self::$command));
            self::log(sprintf("Usa '%s help' para ver la lista de comandos.", self::bin()));

            return 1;
        };
    }

    protected function defaultException(): callable
    {
        return function (Throwable $exception) {
            self::error($exception->getMessage());
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
