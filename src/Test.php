<?php

declare(strict_types=1);

namespace Ajo;

use Ajo\Core\Console as CoreConsole;
use ArrayObject;
use AssertionError;
use Closure;
use Countable;
use FilesystemIterator;
use InvalidArgumentException;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionFunction;
use Throwable;

/**
 * Runner de tests.
 *
 * Los archivos dentro del directorio configurado deben registrar suites mediante:
 *
 * ```php
 * Tests::suite('Contexto', function (Tests $t) {
 *   $t->beforeEach(fn($state) => $state['count'] = ($state['count'] ?? 0) + 1);
 *   $t->test('ejemplo', function ($state) {
 *       Tests::assertTrue($state['count'] > 0);
 *   });
 * });
 * ```
 */
final class Test
{
    private static ?self $instance = null;

    private string $path;
    private ?string $file = null;

    /** @var array<string, array{
     *   title:string,
     *   file:?string,
     *   state:array,
     *   tests:list<array{name:string,handler:Closure,skip:bool,only:bool}>,
     *   before:list<Closure>,
     *   after:list<Closure>,
     *   beforeEach:list<Closure>,
     *   afterEach:list<Closure>,
     *   skip:bool,
     *   only:bool,
     *   last:?int
     * }> */
    private array $suites = [];

    /** @var list<string> */
    private array $queue = [];

    private ?string $current = null;

    private bool $hasOnly = false;

    private array $summary = [
        'total' => 0,
        'passed' => 0,
        'failed' => 0,
        'skipped' => 0,
    ];

    private function __construct(string $path)
    {
        $this->path = rtrim($path, '/');
    }

    public static function register(CoreConsole $cli, string $path): self
    {
        $self = self::$instance ??= new self($path);

        $cli->command('run', fn() => $self->run())->describe(sprintf('Ejecuta los tests definidos en %s.', $path));
        $cli->command('list', fn() => $self->list())->describe('Lista suites y casos de prueba disponibles.');

        return $self;
    }

    public static function suite(string $name, callable $builder, array $state = []): self
    {
        $self = self::instance();

        $index = count($self->suites) + 1;
        $normalized = preg_replace('/\s+/', '_', strtolower(trim($name))) ?: (string)$index;
        $id = sprintf('%04d:%s', $index, $normalized);

        $self->suites[$id] = [
            'title' => $name,
            'file' => $self->file,
            'state' => $state,
            'tests' => [],
            'before' => [],
            'after' => [],
            'beforeEach' => [],
            'afterEach' => [],
            'skip' => false,
            'only' => false,
            'last' => null,
        ];

        $self->queue[] = $id;
        $previous = $self->current;
        $self->current = $id;

        try {
            $self->invoke($builder);
        } finally {
            $self->current = $previous;
        }

        return $self;
    }

    public function test(string $name, callable $handler, array $options = [])
    {
        return $this->tap(function () use ($name, $handler, $options) {

            $suite = &$this->suites[$this->current];

            $suite['tests'][] = [
                'name' => $name,
                'handler' => $this->wrap($handler),
                'skip' => (bool)($options['skip'] ?? false),
                'only' => (bool)($options['only'] ?? false),
            ];

            $suite['last'] = array_key_last($suite['tests']);

            if (!empty($options['only'])) {
                $this->hasOnly = true;
            }
        });
    }

    public function before(callable $handler): self
    {
        return $this->tap(fn() => $this->suites[$this->current]['before'][] = $this->wrap($handler));
    }

    public function after(callable $handler): self
    {
        return $this->tap(fn() => $this->suites[$this->current]['after'][] = $this->wrap($handler));
    }

    public function beforeEach(callable $handler): self
    {
        return $this->tap(fn() => $this->suites[$this->current]['beforeEach'][] = $this->wrap($handler));
    }

    public function afterEach(callable $handler): self
    {
        return $this->tap(fn() => $this->suites[$this->current]['afterEach'][] = $this->wrap($handler));
    }

    public function skip(?string $test = null)
    {
        return $this->tap(fn() => $this->flag('skip', $test));
    }

    public function only(?string $test = null): self
    {
        return $this->tap(fn() => $this->flag('only', $test));
    }

    public function run(): int
    {
        ['bail' => $bail, 'filter' => $filter, 'coverage' => $withCoverage] = $this->parseOptions(Console::arguments());

        if (!$this->load()) {
            return 1;
        }

        $coverageEnabled = $this->startCoverage($withCoverage);

        if ($this->queue === []) {
            Console::log('No se encontraron tests.');
            $this->stopCoverage($coverageEnabled);
            return 0;
        }

        $startedAt = microtime(true);
        $exitCode = 0;

        foreach ($this->queue as $suiteId) {

            $suite = &$this->suites[$suiteId];
            $tests = $this->testsFor($suite, $filter);

            if ($tests === []) {
                continue;
            }

            Console::blank();
            Console::log(Console::bold($suite['title']));

            if ($suite['skip']) {
                array_walk($tests, fn(array $test) => $this->recordSkip($test['name']));
                continue;
            }

            $state = new ArrayObject($suite['state'], ArrayObject::ARRAY_AS_PROPS);

            if (!$this->guard(fn() => $this->callAll($suite['before'], $state), $suite['title'], '[before all]')) {

                $exitCode = 1;

                if ($bail) {
                    $this->reportSummary($startedAt);
                    $this->stopCoverage($coverageEnabled);
                    return $exitCode;
                }

                continue;
            }

            foreach ($tests as $test) {

                if ($test['skip']) {
                    $this->recordSkip($test['name']);
                    continue;
                }

                $this->summary['total']++;

                $started = microtime(true);

                if (!$this->guard(
                    fn() => $this->callAll($suite['beforeEach'], $state),
                    $suite['title'],
                    $test['name'] . ' (before each)'
                )) {

                    $exitCode = 1;

                    if ($bail) {
                        $this->reportSummary($startedAt);
                        $this->stopCoverage($coverageEnabled);
                        return $exitCode;
                    }

                    continue;
                }

                try {
                    ($test['handler'])($state);

                    $this->summary['passed']++;
                    $this->recordSuccess($test['name'], microtime(true) - $started);
                } catch (Throwable $error) {

                    $exitCode = 1;

                    $this->recordFailure($suite['title'], $test['name'], $error, microtime(true) - $started);

                    if ($bail) {
                        $this->reportSummary($startedAt);
                        $this->stopCoverage($coverageEnabled);
                        return $exitCode;
                    }
                }

                if (!$this->guard(
                    fn() => $this->callAll($suite['afterEach'], $state),
                    $suite['title'],
                    $test['name'] . ' (after each)'
                )) {

                    $exitCode = 1;

                    if ($bail) {
                        $this->reportSummary($startedAt);
                        $this->stopCoverage($coverageEnabled);
                        return $exitCode;
                    }
                }
            }

            if (!$this->guard(fn() => $this->callAll($suite['after'], $state), $suite['title'], '[after all]')) {

                $exitCode = 1;

                if ($bail) {
                    $this->reportSummary($startedAt);
                    $this->stopCoverage($coverageEnabled);
                    return $exitCode;
                }
            }
        }

        $this->reportSummary($startedAt);
        $this->stopCoverage($coverageEnabled);

        return $exitCode;
    }

    private function startCoverage(bool $requested): bool
    {
        if (!$requested) return false;

        if (!extension_loaded('pcov')) {
            Console::warn('pcov no está disponible; se omite la cobertura.');
            return false;
        }

        \pcov\clear();
        \pcov\start();

        return true;
    }

    private function stopCoverage(bool $enabled): void
    {
        if (!$enabled) {
            return;
        }

        $coverage = \pcov\collect(\pcov\exclusive, [__FILE__]);

        \pcov\stop();

        $summary = $this->coverageSummary($coverage);

        Console::blank();
        Console::bold()->log('Coverage:');

        if ($summary['files'] === 0 || $summary['total'] === 0) {
            Console::log('No se registraron líneas ejecutables en src/.');
            return;
        }

        Console::log(sprintf('  Archivos: %d', $summary['files']));
        Console::log(sprintf(
            '  Líneas:   %d / %d (%.2f%%)',
            $summary['covered'],
            $summary['total'],
            $summary['percent'],
        ));

        Console::blank();

        foreach ($summary['filesBreakdown'] as $file => $data) {
            Console::log(sprintf('  %s %s', $this->coverageBadge($data['percent']), $file));
            Console::dim()->log(sprintf('      Líneas: %d / %d', $data['covered'], $data['total']));
        }
    }

    private function list(): int
    {
        $options = $this->parseOptions(Console::arguments());

        if (!$this->load()) return 1;

        if ($this->queue === []) {
            Console::log('No hay suites registradas.');
            return 0;
        }

        $filter = $options['filter'];
        $shown = 0;

        foreach ($this->queue as $suiteId) {

            $suite = $this->suites[$suiteId];
            $tests = $this->testsFor($suite, $filter, false);

            if ($tests === []) continue;

            $shown++;

            Console::log(Console::bold($suite['title']));

            foreach ($tests as $test) Console::log('  - ' . $test['name']);

            if ($suite['file']) Console::dim()->log('    ' . $suite['file']);

            Console::blank();
        }

        if ($shown === 0) Console::log('No hay tests que coincidan con el filtro.');

        return 0;
    }

    private function tap(callable $callback): self
    {
        if ($this->current === null) {
            throw new LogicException('Definí una suite antes de registrar tests u hooks.');
        }

        $callback();
        return $this;
    }

    private function wrap(callable $handler): Closure
    {
        $callable = $handler instanceof Closure ? $handler : Closure::fromCallable($handler);

        return match ((new ReflectionFunction($callable))->getNumberOfParameters()) {
            0 => static function (ArrayObject $state) use ($callable): void {
                $callable();
            },
            1 => static function (ArrayObject $state) use ($callable): void {
                $callable($state);
            },
            default => throw new InvalidArgumentException('Los handlers de Tests aceptan como máximo un parámetro.'),
        };
    }

    private function flag(string $flag, ?string $target): void
    {
        $suite = &$this->suites[$this->current];
        $index = null;

        if ($target !== null) {

            foreach ($suite['tests'] as $i => $test) {
                if ($test['name'] === $target) {
                    $index = $i;
                    break;
                }
            }

            if ($index === null) throw new LogicException(sprintf("El test '%s' no está definido en la suite '%s'.", $target, $suite['title']));
        } elseif ($suite['last'] !== null) $index = $suite['last'];

        if ($index === null) $suite[$flag] = true;
        else $suite['tests'][$index][$flag] = true;
        if ($flag === 'only') $this->hasOnly = true;
    }

    private function sourceDirectory(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src';
    }

    /**
     * @param array<string, array<int, int>> $coverage
     * @return array{files:int,covered:int,total:int,percent:float,filesBreakdown:array<string,array{covered:int,total:int,percent:float}>}
     */
    private function coverageSummary(array $coverage): array
    {
        $base = $this->sourceDirectory() . DIRECTORY_SEPARATOR;
        $files = [];
        $coveredTotal = 0;
        $linesTotal = 0;

        foreach ($coverage as $file => $flags) {

            if (!str_starts_with($file, $base)) {
                continue;
            }

            $covered = 0;
            $total = 0;

            foreach ($flags as $flag) {
                if ($flag > 0) {
                    $covered++;
                    $total++;
                } elseif ($flag < 0) {
                    $total++;
                }
            }

            if ($total === 0) {
                continue;
            }

            $relative = substr($file, strlen($base));
            $percent = ($covered / $total) * 100;

            $files[$relative] = [
                'covered' => $covered,
                'total' => $total,
                'percent' => $percent,
            ];

            $coveredTotal += $covered;
            $linesTotal += $total;
        }

        ksort($files);

        $globalPercent = $linesTotal === 0 ? 0.0 : ($coveredTotal / $linesTotal) * 100;

        return [
            'files' => count($files),
            'covered' => $coveredTotal,
            'total' => $linesTotal,
            'percent' => $globalPercent,
            'filesBreakdown' => $files,
        ];
    }

    private function coverageBadge(float $percent): string
    {
        $label = sprintf(' %6.2f%% ', $percent);

        return match (true) {
            $percent >= 90 => Console::bgGreen($label),
            $percent >= 75 => Console::bgYellow($label),
            default => Console::bgRed($label),
        };
    }

    /**
     * @param array<string, mixed> $suite
     * @return list<array{name:string,handler:Closure,skip:bool,only:bool}>
     */
    private function testsFor(array $suite, ?string $filter, bool $respectOnly = true): array
    {
        $tests = $suite['tests'];

        if ($tests === []) {
            return [];
        }

        if ($respectOnly && $this->hasOnly && !$suite['only']) {
            $tests = array_values(array_filter(
                $tests,
                static fn(array $test): bool => $test['only'] === true,
            ));
        }

        if ($filter === null || $filter === '') {
            return $tests;
        }

        $needle = strtolower($filter);
        $suiteName = strtolower($suite['title']);
        $suiteMatches = str_contains($suiteName, $needle);

        return array_values(array_filter(
            $tests,
            static fn(array $test): bool => $suiteMatches || str_contains(strtolower($test['name']), $needle),
        ));
    }

    private function callAll(array $handlers, ArrayObject $state): void
    {
        foreach ($handlers as $handler) $handler($state);
    }

    private function guard(callable $operation, string $suite, string $where): bool
    {
        try {
            $operation();
            return true;
        } catch (Throwable $error) {
            $this->recordFailure($suite, $where, $error);
            return false;
        }
    }

    private function recordSuccess(string $name, float $seconds): void
    {
        Console::log(sprintf('  %s %s %s', Console::green('[PASS]'), $name, Console::dim('(' . $this->formatDuration($seconds) . ')')));
    }

    private function recordFailure(string $suite, string $name, Throwable $error, ?float $seconds = null): void
    {
        Console::log(sprintf('  %s %s %s', Console::red('[FAIL]'), $name, $seconds !== null ? Console::dim('(' . $this->formatDuration($seconds) . ')') : ''));
        Console::red()->log('    ' . $error->getMessage());
        Console::dim()->log(sprintf('    at %s:%d', $error->getFile(), $error->getLine()));

        foreach ($this->renderTrace($error) as $line) Console::dim()->log('      ' . $line);

        $this->summary['failed']++;
    }

    private function recordSkip(string $name): void
    {
        $this->summary['skipped']++;
        $this->summary['total']++;
        Console::log(sprintf('  %s %s', Console::yellow('[SKIP]'), $name));
    }

    private function reportSummary(float $startedAt): void
    {
        Console::blank();
        Console::bold()->log('Resumen:');
        Console::log('  Total:    ' . $this->summary['total']);
        Console::log('  Pasados:  ' . $this->summary['passed']);
        Console::log('  Saltados: ' . $this->summary['skipped']);
        Console::log('  Fallos:   ' . $this->summary['failed']);
        Console::log('  Tiempo:   ' . $this->formatDuration(microtime(true) - $startedAt));
    }

    private function reset(): void
    {
        $this->suites = [];
        $this->queue = [];
        $this->current = null;
        $this->file = null;
        $this->hasOnly = false;
        $this->summary = [
            'total' => 0,
            'passed' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];
    }

    private function load(): bool
    {
        $this->reset();

        if (!is_dir($this->path)) {
            Console::error(sprintf('No existe el directorio de tests: %s', $this->path));
            return false;
        }

        foreach ($this->discover() as $file) {
            $this->file = $file;
            require $file;
            $this->file = null;
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function discover(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->path, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $item) if ($item->isFile() && strtolower($item->getExtension()) === 'php') $files[] = $item->getPathname();

        sort($files, SORT_STRING);

        return $files;
    }

    private function invoke(callable $callable): void
    {
        $closure = $callable instanceof Closure ? $callable : Closure::fromCallable($callable);

        match ((new ReflectionFunction($closure))->getNumberOfParameters()) {
            0 => $closure(),
            1 => $closure($this),
            default => throw new InvalidArgumentException('Los callbacks de tests admiten como máximo un parámetro.'),
        };
    }

    private function parseOptions(array $arguments): array
    {
        $options = [
            'filter' => null,
            'bail' => false,
            'coverage' => false,
        ];

        foreach ($arguments as $argument) {

            if ($argument === '--bail' || $argument === '-b') {
                $options['bail'] = true;
                continue;
            }

            if ($argument === '--coverage' || $argument === '-c') {
                $options['coverage'] = true;
                continue;
            }

            if (str_starts_with($argument, '--coverage=')) {
                $options['coverage'] = trim(substr($argument, 11)) !== '0';
                continue;
            }

            if (str_starts_with($argument, '--filter=')) {
                $options['filter'] = trim(substr($argument, 9));
                continue;
            }

            if ($options['filter'] === null) $options['filter'] = trim($argument);
        }

        if ($options['filter'] === '') $options['filter'] = null;

        return $options;
    }

    /**
     * @return list<string>
     */
    private function renderTrace(Throwable $error, int $limit = 6): array
    {
        $trace = $error->getTrace();
        $lines = [];

        foreach ($trace as $index => $frame) {

            if ($index >= $limit) break;

            $file = isset($frame['file']) ? (string)$frame['file'] : '[internal]';
            $line = isset($frame['line']) ? (int)$frame['line'] : 0;
            $function = (string)($frame['function'] ?? 'closure');

            $lines[] = sprintf('%s:%d %s()', $file, $line, $function);
        }

        return $lines;
    }

    private function formatDuration(float $seconds): string
    {
        return $seconds >= 1 ? sprintf('%.2fs', $seconds) : sprintf('%.2fms', $seconds * 1000);
    }

    private static function instance(): self
    {
        if (self::$instance === null) throw new LogicException('Tests no está inicializado. Llamá a Tests::register primero.');
        return self::$instance;
    }

    private static function ensure(bool $condition, string $message): void
    {
        if (!$condition) self::fail($message);
    }

    public static function fail(string $message): never
    {
        throw new AssertionError($message);
    }

    public static function assertTrue(mixed $value, string $message = ''): void
    {
        self::ensure($value === true, $message !== '' ? $message : 'Se esperaba true.');
    }

    public static function assertFalse(mixed $value, string $message = ''): void
    {
        self::ensure($value === false, $message !== '' ? $message : 'Se esperaba false.');
    }

    public static function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::ensure($expected === $actual, $message !== '' ? $message : sprintf('Se esperaba valor idéntico a %s.', var_export($expected, true)));
    }

    public static function assertNotSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::ensure($expected !== $actual, $message !== '' ? $message : 'Se esperaba un valor distinto.');
    }

    public static function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::ensure($expected == $actual, $message !== '' ? $message : 'Los valores no coinciden.');
    }

    public static function assertNotEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::ensure($expected != $actual, $message !== '' ? $message : 'Los valores no deberían coincidir.');
    }

    public static function assertNull(mixed $value, string $message = ''): void
    {
        self::ensure($value === null, $message !== '' ? $message : 'Se esperaba null.');
    }

    public static function assertNotNull(mixed $value, string $message = ''): void
    {
        self::ensure($value !== null, $message !== '' ? $message : 'No se esperaba null.');
    }

    public static function assertNotFalse(mixed $value, string $message = ''): void
    {
        self::ensure($value !== false, $message !== '' ? $message : 'Se esperaba un valor distinto de false.');
    }

    public static function assertCount(int $expected, Countable|array $value, string $message = ''): void
    {
        self::ensure(count($value) === $expected, $message !== '' ? $message : sprintf('Se esperaban %d elementos.', $expected));
    }

    public static function assertArrayHasKey(string|int $key, array $array, string $message = ''): void
    {
        self::ensure(array_key_exists($key, $array), $message !== '' ? $message : sprintf("No existe la llave '%s' en el array.", (string)$key));
    }

    public static function assertContains(mixed $needle, iterable|string $haystack, string $message = ''): void
    {
        $found = false;

        if (is_string($haystack)) $found = is_string($needle) ? str_contains($haystack, $needle) : str_contains($haystack, (string)$needle);

        else foreach ($haystack as $item) if ($item == $needle) {
            $found = true;
            break;
        }

        self::ensure($found, $message !== '' ? $message : 'No se encontró el valor esperado.');
    }

    public static function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
    {
        self::ensure(str_contains($haystack, $needle), $message !== '' ? $message : sprintf("El texto no contiene '%s'.", $needle));
    }

    public static function assertInstanceOf(string $class, mixed $value, string $message = ''): void
    {
        self::ensure($value instanceof $class, $message !== '' ? $message : sprintf('Se esperaba instancia de %s.', $class));
    }

    public static function expectException(string $class, callable $callback, ?string $expectedMessage = null, string $message = ''): void
    {
        try {
            $callback();
        } catch (Throwable $error) {

            self::ensure($error instanceof $class, $message !== '' ? $message : sprintf('Se lanzó %s en lugar de %s.', $error::class, $class));

            if ($expectedMessage !== null) {
                self::ensure(
                    $error->getMessage() === $expectedMessage,
                    sprintf("Se esperaba mensaje '%s', se obtuvo '%s'.", $expectedMessage, $error->getMessage())
                );
            }

            return;
        }

        self::fail($message !== '' ? $message : sprintf('Se esperaba excepción de tipo %s.', $class));
    }
}
