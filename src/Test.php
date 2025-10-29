<?php

declare(strict_types=1);

namespace Ajo;

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
use BadMethodCallException;

/**
 * Test runner.
 *
 * Files within the configured directories should register suites using:
 *
 * ```php
 * Test::suite('Context', function () {
 *   Test::beforeEach(fn($state) => $state['count'] = ($state['count'] ?? 0) + 1);
 *   Test::case('increments the counter', function ($state) {
 *       Test::assertTrue($state['count'] > 0);
 *   });
 * });
 * ```
 *
 * @method static void assertTrue(mixed $value, string $message = '')
 * @method static void assertFalse(mixed $value, string $message = '')
 * @method static void assertNull(mixed $value, string $message = '')
 * @method static void assertNotNull(mixed $value, string $message = '')
 * @method static void assertNotFalse(mixed $value, string $message = '')
 * @method static void assertSame(mixed $expected, mixed $actual, string $message = '')
 * @method static void assertNotSame(mixed $expected, mixed $actual, string $message = '')
 * @method static void assertEquals(mixed $expected, mixed $actual, string $message = '')
 * @method static void assertNotEquals(mixed $expected, mixed $actual, string $message = '')
 * @method static void assertCount(int $expected, Countable|array $value, string $message = '')
 * @method static void assertArrayHasKey(string|int $key, array $array, string $message = '')
 * @method static void assertInstanceOf(string $class, mixed $value, string $message = '')
 * @method static void assertStringContainsString(string $needle, string $haystack, string $message = '')
 * @method static void assertContains(mixed $needle, iterable|string $haystack, string $message = '')
 */
final class Test
{
    private static ?self $instance = null;

    private ?string $file = null;

    /** @var list<string> */
    private array $testPaths = [];

    /** @var list<string> */
    private array $sourcePaths = [];

    /** @var array<string,array{title:string,file:?string,state:array,tests:list<array{name:string,handler:Closure,skip:bool,only:bool}>,before:list<Closure>,after:list<Closure>,beforeEach:list<Closure>,afterEach:list<Closure>,skip:bool,only:bool,last:?int}> */
    private array $suites = [];

    /** @var list<string> */
    private array $queue = [];

    private ?string $current = null;

    private bool $hasOnly = false;

    private array $summary = [];

    /** @var list<array{suite:string,classname:string,name:string,time:float,status:string,error:?array}> */
    private array $results = [];

    private function __construct() {}

    private static function instance(): self
    {
        return self::$instance ?? throw new LogicException('Test is not initialized. Call Test::register first.');
    }

    // PUBLIC API ============================================================

    /** Registers test commands in the CLI */
    public static function register(ConsoleCore $cli, array $paths = []): self
    {
        $normalize = fn($value) => array_values((array)($value ?? []));

        $self = self::$instance ??= new self();
        $self->testPaths = $normalize($paths['tests'] ?? 'tests');
        $self->sourcePaths = $normalize($paths['src'] ?? 'src');

        $cli->command('test', fn() => $self->run())
            ->describe(sprintf('Run tests defined in %s', implode(', ', $self->testPaths)))
            ->usage('[filter] [options]')
            ->option('-b, --bail', 'Stop on first failure')
            ->option('-c, --coverage', 'Generate coverage report (true=console, html=HTML report, or file path for XML)')
            ->option('-p, --parallel', 'Run in parallel (true=auto-detect CPUs, or specify worker count)')
            ->option('--filter', 'Filter by suite name, test name, or file path')
            ->option('--log', 'Export results to JUnit XML file path')
            ->example('Console', 'Run tests matching "Console"')
            ->example('--filter=Job', 'Filter tests by name')
            ->example('--coverage', 'Show coverage summary in console')
            ->example('--coverage=html', 'Generate HTML coverage report in coverage/')
            ->example('--coverage=coverage.xml', 'Generate Cobertura XML report')
            ->example('--parallel', 'Auto-detect CPUs and run in parallel')
            ->example('--parallel=4', 'Run with 4 workers')
            ->example('--bail --parallel=2', 'Parallel execution, stop on first failure');

        $cli->command('test:list', fn() => $self->list())
            ->describe('List available test suites and cases')
            ->usage('[filter] [options]')
            ->option('--filter', 'Filter by suite name, test name, or file path')
            ->example('Console', 'List tests matching "Console"')
            ->example('--filter=Unit/Console', 'List tests from specific file');

        return $self;
    }

    /** Defines a test suite */
    public static function suite(string $name, callable $builder, array $state = []): self
    {
        $self = self::instance();
        $index = count($self->suites) + 1;
        $id = sprintf('%04d:%s', $index, preg_replace('/\s+/', '_', strtolower(trim($name))) ?: (string)$index);

        $self->queue[] = $id;
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
            'last' => null
        ];

        [$self->current, $previous] = [$id, $self->current];

        try {
            ($builder instanceof Closure ? $builder : Closure::fromCallable($builder))();
        } finally {
            $self->current = $previous;
        }

        return $self;
    }

    /** Registers a test case */
    public static function case(string $name, callable $handler, array $options = []): self
    {
        return self::instance()->tap(function () use ($name, $handler, $options) {
            $self = self::$instance;
            $suite = &$self->suites[$self->current];
            $only = (bool)($options['only'] ?? false);
            $reflection = new ReflectionFunction($handler);
            $suite['tests'][] = ['name' => $name, 'handler' => $self->wrap($handler), 'skip' => (bool)($options['skip'] ?? false), 'only' => $only, 'line' => $reflection->getStartLine()];
            $suite['last'] = array_key_last($suite['tests']);
            if ($only) $self->hasOnly = true;
        });
    }

    /** Defines hook that runs before all tests in the suite */
    public static function before(callable $handler): self
    {
        return self::hook('before', $handler);
    }

    /** Defines hook that runs after all tests in the suite */
    public static function after(callable $handler): self
    {
        return self::hook('after', $handler);
    }

    /** Defines hook that runs before each test */
    public static function beforeEach(callable $handler): self
    {
        return self::hook('beforeEach', $handler);
    }

    /** Defines hook that runs after each test */
    public static function afterEach(callable $handler): self
    {
        return self::hook('afterEach', $handler);
    }

    /** Marks a suite or test to be skipped */
    public static function skip(?string $test = null): self
    {
        return self::instance()->tap(fn() => self::$instance->flag('skip', $test));
    }

    /** Marks a suite or test as the only one to run */
    public static function only(?string $test = null): self
    {
        return self::instance()->tap(fn() => self::$instance->flag('only', $test));
    }

    // ASSERTIONS =============================================================

    /** Throws an AssertionError exception */
    public static function fail(string $message): never
    {
        throw new AssertionError($message);
    }

    /** Verifies that a condition is true */
    private static function ensure(bool $condition, string $message)
    {
        if (!$condition) self::fail($message);
    }

    /** Formats a value for display in error messages */
    private static function formatValue($value): string
    {
        if (is_string($value)) return sprintf('"%s"', $value);
        if (is_null($value)) return 'null';
        if (is_bool($value)) return $value ? 'true' : 'false';
        if (is_array($value)) return count($value) <= 5 ? var_export($value, true) : sprintf('Array(%d)', count($value));
        if (is_object($value)) return get_class($value);
        return (string)$value;
    }

    /** Dynamic dispatcher for assertion methods */
    public static function __callStatic(string $name, array $args)
    {
        $msg = fn($i) => $args[$i] ?? '';
        $assertions = [
            'assertTrue'                 => fn() => self::ensure($args[0] === true, $msg(1)                    ?: "Expected: true\nReceived: " . self::formatValue($args[0])),
            'assertFalse'                => fn() => self::ensure($args[0] === false, $msg(1)                   ?: "Expected: false\nReceived: " . self::formatValue($args[0])),
            'assertNull'                 => fn() => self::ensure($args[0] === null, $msg(1)                    ?: "Expected: null\nReceived: " . self::formatValue($args[0])),
            'assertNotNull'              => fn() => self::ensure($args[0] !== null, $msg(1)                    ?: "Expected: not null\nReceived: null"),
            'assertNotFalse'             => fn() => self::ensure($args[0] !== false, $msg(1)                   ?: "Expected: not false\nReceived: false"),
            'assertSame'                 => fn() => self::ensure($args[0] === $args[1], $msg(2)                ?: "Expected: " . self::formatValue($args[0]) . "\nReceived: " . self::formatValue($args[1])),
            'assertNotSame'              => fn() => self::ensure($args[0] !== $args[1], $msg(2)                ?: "Expected: not " . self::formatValue($args[0]) . "\nReceived: " . self::formatValue($args[1])),
            'assertEquals'               => fn() => self::ensure($args[0] == $args[1], $msg(2)                 ?: "Expected: " . self::formatValue($args[0]) . "\nReceived: " . self::formatValue($args[1])),
            'assertNotEquals'            => fn() => self::ensure($args[0] != $args[1], $msg(2)                 ?: "Expected: not " . self::formatValue($args[0]) . "\nReceived: " . self::formatValue($args[1])),
            'assertCount'                => fn() => self::ensure(count($args[1]) === $args[0], $msg(2)         ?: "Expected: {$args[0]} elements\nReceived: " . count($args[1]) . " elements"),
            'assertArrayHasKey'          => fn() => self::ensure(array_key_exists($args[0], $args[1]), $msg(2) ?: "Expected: array with key " . self::formatValue($args[0]) . "\nReceived: array without key"),
            'assertInstanceOf'           => fn() => self::ensure($args[1] instanceof $args[0], $msg(2)         ?: "Expected: instance of {$args[0]}\nReceived: " . get_debug_type($args[1])),
            'assertStringContainsString' => fn() => self::ensure(str_contains($args[1], $args[0]), $msg(2)     ?: "Expected: string containing " . self::formatValue($args[0]) . "\nReceived: " . self::formatValue($args[1])),
            'assertContains'             => fn() => self::ensure(
                is_string($args[1]) ? str_contains($args[1], (string)$args[0]) : in_array($args[0], is_array($args[1]) ? $args[1] : iterator_to_array($args[1])),
                $msg(2) ?: "Expected: contains " . self::formatValue($args[0]) . "\nReceived: does not contain"
            ),
        ];

        if (isset($assertions[$name])) return $assertions[$name]();
        throw new BadMethodCallException("Method $name does not exist");
    }

    /** Verifies that a specific exception type is thrown */
    public static function expectException(string $class, callable $callback, ?string $expectedMessage = null, string $message = '')
    {
        try {
            $callback();
        } catch (Throwable $error) {
            self::ensure($error instanceof $class, $message ?: sprintf('Threw %s instead of %s.', $error::class, $class));
            if ($expectedMessage !== null) {
                self::ensure(
                    $error->getMessage() === $expectedMessage,
                    sprintf("Expected message '%s', got '%s'.", $expectedMessage, $error->getMessage())
                );
            }
            return;
        }

        self::fail($message ?: sprintf('Expected exception of type %s.', $class));
    }

    // EXECUTION ==============================================================

    /** Executes tests according to console arguments */
    public function run()
    {
        $opts = Console::options();

        $bail = (bool)($opts['bail'] ?? false);
        // Support both --filter=X and positional argument
        $filter = $opts['filter'] ?? $opts['_'][0] ?? null;
        $coverage = $opts['coverage'] ?? null;
        $log = $opts['log'] ?? null;

        // Handle parallel option: boolean true = auto-detect CPUs, number = specific count
        $workers = match (true) {
            !isset($opts['parallel']) => 1,
            $opts['parallel'] === true => $this->detectCpuCount(),
            is_numeric($opts['parallel']) => max(1, (int)$opts['parallel']),
            default => 1,
        };

        if (!$this->load()) return 1;

        if ($workers > 1 && function_exists('pcntl_fork')) return $this->parallel($workers, $bail, $filter, $coverage, $log);

        return $this->sequential($bail, $filter, $coverage, $log);
    }

    /** Executes tests sequentially in a single process */
    private function sequential(bool $bail, $filter, $coverage, $log, $shm = null)
    {
        $isParallel = $shm !== null;
        $coverageEnabled = $this->startCoverage($coverage !== null);

        if ($this->queue === []) {
            if (!$isParallel) Console::line('No tests found.');
            $this->stopCoverage($coverageEnabled);
            return 0;
        }

        $startedAt = microtime(true);
        $exitCode = 0;
        $bailout = function ($code = null) use (&$exitCode, $startedAt, $isParallel, $coverageEnabled, $coverage, $log) {
            if ($code) $exitCode = $code;
            if (!$isParallel) $this->summary($startedAt);
            $this->stopCoverage($coverageEnabled, is_string($coverage) ? $coverage : null);
            if ($log && !$isParallel) $this->writeLog($log, microtime(true) - $startedAt);
            return $exitCode;
        };

        $fail = function () use ($bail, $bailout, &$exitCode) {
            if ($bail) return $bailout(1);
            $exitCode = 1;
            return false;
        };

        foreach ($this->queue as $suiteId) {

            $suite = &$this->suites[$suiteId];
            $tests = $this->filter($suite, $filter);

            if ($tests === []) continue;

            $title = $suite['title'];
            $base = getcwd() . '/';
            $class = str_starts_with($suite['file'], $base) ? substr($suite['file'], strlen($base)) : $suite['file'];

            if (!$isParallel) {
                Console::blank();
                Console::line(Console::bold($title));
            }

            $record = fn($type, $name, $seconds = null, $error = null, $testIndex = null, $line = null) => $isParallel
                ? $this->incrementMemory($shm, $suiteId, compact('type', 'name', 'seconds', 'testIndex', 'line') + ['suite' => $title, 'suiteId' => $suiteId, 'classname' => $class, 'error' => $error ? $this->normalizeError($error) : null])
                : $this->render($type, $name, $seconds, $error ? $this->normalizeError($error) : null, $title, $class, $line);

            if ($suite['skip']) {
                foreach ($tests as $testIndex => $test) $record('skip', $test['name'], testIndex: $testIndex, line: $test['line'] ?? null);
                continue;
            }

            $state = new ArrayObject($suite['state'], ArrayObject::ARRAY_AS_PROPS);
            $runHooks = fn($hooks, $label) => $this->guard(fn() => array_walk($hooks, fn($h) => $h($state)), $label, $isParallel);

            if (!$runHooks($suite['before'], '[before all]')) {
                if ($fail()) return $bailout();
                continue;
            }

            foreach ($tests as $testIndex => $test) {

                if ($test['skip']) {
                    $record('skip', $test['name'], testIndex: $testIndex, line: $test['line'] ?? null);
                    continue;
                }

                $started = microtime(true);

                if (!$runHooks($suite['beforeEach'], $test['name'] . ' (before each)')) {
                    $record('fail', $test['name'] . ' (before each)', testIndex: $testIndex, line: $test['line'] ?? null);
                    if ($fail()) return $bailout();
                    continue;
                }

                try {
                    ($test['handler'])($state);
                    $record('pass', $test['name'], microtime(true) - $started, testIndex: $testIndex, line: $test['line'] ?? null);
                } catch (Throwable $error) {
                    $record('fail', $test['name'], microtime(true) - $started, $error, $testIndex, $test['line'] ?? null);
                    if ($fail()) return $bailout();
                }

                if (!$runHooks($suite['afterEach'], $test['name'] . ' (after each)')) {
                    $record('fail', $test['name'] . ' (after each)', testIndex: $testIndex, line: $test['line'] ?? null);
                    if ($fail()) return $bailout();
                }
            }

            if (!$runHooks($suite['after'], '[after all]')) {
                if ($fail()) return $bailout();
            }
        }

        return $bailout($exitCode);
    }

    /** Executes tests in parallel using multiple processes */
    private function parallel(int $workers, bool $bail, $filter, $coverage, $log)
    {
        if ($coverage || !extension_loaded('sysvshm')) {
            Console::warning($coverage ? 'Coverage is not supported in parallel mode. Running sequentially...' : 'sysvshm not available, falling back to sequential...');
            return $this->sequential($bail, $filter, $coverage, $log);
        }

        $chunks = array_chunk($this->queue, (int)ceil(count($this->queue) / $workers));
        $testsPerSuite = array_map(fn($id) => count($this->filter($this->suites[$id], $filter)), $this->queue);
        $totalTests = array_sum($testsPerSuite);
        $shm = $this->createMemory();
        $started = microtime(true);

        Console::line(sprintf('Running %d tests in %d workers...', $totalTests, count($chunks)));
        Console::blank();

        $pids = [];

        foreach ($chunks as $chunk) {
            $pid = pcntl_fork();
            if ($pid === -1) return (Console::warning('Fork failed, falling back to sequential...') || $this->cleanupMemory($shm)) && $this->sequential($bail, $filter, $coverage, $log);
            if ($pid === 0) {
                $this->queue = $chunk;
                exit($this->sequential($bail, $filter, null, null, $shm));
            }
            $pids[] = $pid;
        }

        $this->progress($shm, $totalTests, $testsPerSuite);

        $failed = array_reduce($pids, function ($failures, $pid) {
            pcntl_waitpid($pid, $status);
            return $failures + (pcntl_wexitstatus($status) !== 0 ? 1 : 0);
        }, 0);

        $this->renderParallel($shm);
        $this->cleanupMemory($shm);
        $this->summary($started);

        if ($log) $this->writeLog($log, microtime(true) - $started);

        return $failed > 0 ? 1 : 0;
    }

    // OUTPUT =================================================================

    /** Renders an individual test result to console */
    private function render(string $type, string $name, ?float $seconds = null, $error = null, ?string $suite = null, ?string $classname = null, ?int $line = null)
    {
        [$badge, $color, $counter] = match ($type) {
            'pass' => ['[PASS]', 'green', 'passed'],
            'fail' => ['[FAIL]', 'red', 'failed'],
            'skip' => ['[SKIP]', 'yellow', 'skipped'],
        };

        $location = '';
        if ($classname && $line) {
            $base = getcwd() . '/';
            $filePath = str_starts_with($classname, $base)
                ? substr($classname, strlen($base))
                : $classname;
            $location = ' ' . Console::dim($filePath . ':' . $line);
        }

        Console::line(sprintf(
            '  %s %s %s%s',
            Console::$color($badge),
            $name,
            $seconds ? Console::dim(sprintf('(%s)', $this->duration($seconds))) : '',
            $location
        ));

        $normalized = $error ? $this->normalizeError($error) : null;

        if ($normalized) {
            Console::blank();

            // Show code snippet if available - find first non-test-runner frame
            $errorLine = null;
            $errorFile = null;
            $testRunnerFile = __FILE__;

            // If error is from test runner, find first frame from actual test
            if ($normalized['file'] === $testRunnerFile) {
                foreach ($normalized['trace'] as $frame) {
                    if ($frame['file'] !== $testRunnerFile && $frame['file'] !== '[internal]') {
                        $errorFile = $frame['file'];
                        $errorLine = $frame['line'];
                        break;
                    }
                }
            } else {
                $errorFile = $normalized['file'];
                $errorLine = $normalized['line'];
            }

            if ($errorFile && $errorLine) {
                $snippet = $this->getCodeSnippet($errorFile, $errorLine, 1);
                if ($snippet) {
                    foreach ($snippet as $lineNum => $code) {
                        $prefix = $lineNum === $errorLine ? '  > ' : '    ';
                        Console::dim()->line(sprintf('%s%d: %s', $prefix, $lineNum, $code));
                    }
                    Console::blank();
                }
            }

            // Show error message (handle multiline messages)
            $lines = explode("\n", $normalized['message']);
            foreach ($lines as $msgLine) {
                Console::red()->line('    ' . $msgLine);
            }
            Console::blank();

            // Show filtered stack trace
            $filtered = $this->filterStackTrace($normalized['trace'], $classname);
            if (!empty($filtered)) {
                foreach (array_slice($filtered, 0, 3) as $frame) {
                    Console::dim()->line(sprintf('    at %s', $frame));
                }
            } else {
                Console::dim()->line(sprintf('    at %s:%d', $normalized['file'], $normalized['line']));
            }
        }

        $this->summary[$counter]++;

        if ($suite && $classname) {
            $this->results[] = compact('suite', 'classname', 'name') + [
                'time' => $seconds ?? 0.0,
                'status' => $type,
                'error' => $normalized
            ];
        }
    }

    /** Shows the final execution summary */
    private function summary(float $startedAt)
    {
        Console::blank();
        Console::bold()->line('Summary:');
        Console::line('  Total:   ' . array_sum($this->summary));
        Console::line('  Passed:  ' . $this->summary['passed']);
        Console::line('  Skipped: ' . $this->summary['skipped']);
        Console::line('  Failed:  ' . $this->summary['failed']);
        Console::line('  Time:    ' . $this->duration(microtime(true) - $startedAt));
    }

    /** Formats elapsed time into a compact string */
    private function duration(float $seconds)
    {
        return $seconds >= 1 ? sprintf('%.2fs', $seconds) : sprintf('%.2fms', $seconds * 1000);
    }

    /** Renders consolidated results from parallel execution */
    private function renderParallel($shm)
    {
        [$shmId, $semId] = $shm;

        sem_acquire($semId);
        $results = shm_get_var($shmId, 1)['results'];
        sem_release($semId);

        // Group by suite and sort by original index
        $grouped = [];
        foreach ($results as $r) $grouped[$r['suiteId']][] = $r;
        foreach ($grouped as &$tests) usort($tests, fn($a, $b) => $a['testIndex'] <=> $b['testIndex']);
        unset($tests); // Release reference

        foreach ($this->queue as $suiteId) {
            if (!isset($grouped[$suiteId])) continue;
            $suite = $this->suites[$suiteId];
            Console::blank();
            Console::line(Console::bold($suite['title']));
            array_walk($grouped[$suiteId], fn($r) => $this->render(
                $r['type'],
                $r['name'],
                $r['seconds'] ?? null,
                $r['error'] ?? null,
                $r['suite'],
                $r['classname'],
                $r['line'] ?? null
            ));
        }
    }

    /** Shows parallel execution progress */
    private function progress($shm, $totalTests, $testsPerSuite)
    {
        [$shmId, $semId] = $shm;

        while (true) {
            sem_acquire($semId);
            $state = shm_get_var($shmId, 1);
            sem_release($semId);

            Console::progress($state['completed'], $totalTests, 'Progress', array_combine(
                array_map(fn($id) => $this->suites[$id]['title'], $this->queue),
                array_map(fn($id, $total) => [($state['suiteProgress'][$id] ?? 0), $total], $this->queue, $testsPerSuite)
            ));

            if ($state['completed'] >= $totalTests) break;

            usleep(100000);
        }
        Console::write("\n");
    }

    // EXPORT =================================================================

    /** Writes results in JUnit XML format */
    private function writeLog(string $file, float $totalTime)
    {
        $suites = [];
        foreach ($this->results as $r) $suites[$r['suite']][] = $r;

        $suitesXml = [];
        foreach ($suites as $suiteName => $tests) {
            $stats = array_reduce($tests, fn($c, $t) => [
                'failures' => $c['failures'] + ($t['status'] === 'fail'),
                'skipped' => $c['skipped'] + ($t['status'] === 'skip'),
                'time' => $c['time'] + $t['time']
            ], ['failures' => 0, 'skipped' => 0, 'time' => 0.0]);

            $testsXml = array_map(function ($test) {
                $testcase = sprintf(
                    '<testcase name="%s" classname="%s" time="%.6f">',
                    $this->escape($test['name']),
                    $this->escape($test['classname']),
                    $test['time']
                );

                if ($test['status'] === 'fail') {
                    $e = $test['error'];
                    $type = str_contains($e['message'], 'AssertionError') ? 'failure' : 'error';
                    $errorType = $type === 'failure' ? 'AssertionError' : 'RuntimeException';
                    $trace = sprintf("%s:%d\n", $e['file'], $e['line']) . implode('', array_map(
                        fn($f) => sprintf("  at %s:%d %s()\n", $f['file'], $f['line'], $f['function']),
                        array_slice($e['trace'], 0, 10)
                    ));
                    $testcase .= sprintf('<%s message="%s" type="%s">%s</%s>', $type, $this->escape($e['message']), $errorType, $this->escape($trace), $type);
                } elseif ($test['status'] === 'skip') {
                    $testcase .= '<skipped/>';
                }

                return $testcase . '</testcase>';
            }, $tests);

            $suitesXml[] = sprintf(
                '<testsuite name="%s" tests="%d" failures="%d" errors="0" skipped="%d" time="%.6f">%s</testsuite>',
                $this->escape($suiteName),
                count($tests),
                $stats['failures'],
                $stats['skipped'],
                $stats['time'],
                implode('', $testsXml)
            );
        }

        $xml = sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>%s<testsuites tests="%d" failures="%d" errors="0" time="%.6f">%s</testsuites>',
            "\n",
            array_sum($this->summary),
            $this->summary['failed'],
            $totalTime,
            implode('', $suitesXml)
        );

        file_put_contents($file, $xml);
    }

    /** Writes code coverage in Cobertura XML format */
    private function writeCoverage(string $file, array $coverage)
    {
        $summary = $this->coverageSummary($coverage);
        $lineRate = $summary['total'] > 0 ? $summary['covered'] / $summary['total'] : 0;
        $base = getcwd();

        $classesXml = array_map(function ($path, $flags) use ($base) {
            [$covered, $total] = [0, 0];
            $lines = array_map(function ($line, $flag) use (&$covered, &$total) {
                if ($flag === 0) return '';
                $flag > 0 && $covered++;
                $total++;
                return sprintf('<line number="%d" hits="%d"/>', $line, $flag > 0 ? 1 : 0);
            }, array_keys($flags), $flags);

            $rate = $total > 0 ? $covered / $total : 0;
            $name = str_starts_with($path, $base . '/') ? substr($path, strlen($base) + 1) : $path;

            return sprintf(
                '<class name="%s" filename="%s" line-rate="%.4f" branch-rate="1" complexity="0"><methods/><lines>%s</lines></class>',
                $this->escape(str_replace(['/', '.php'], ['_', ''], $name)),
                $this->escape($name),
                $rate,
                implode('', $lines)
            );
        }, array_keys($coverage), $coverage);

        $xml = sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>%s<!DOCTYPE coverage SYSTEM "http://cobertura.sourceforge.net/xml/coverage-04.dtd">%s<coverage line-rate="%.4f" branch-rate="1" lines-covered="%d" lines-valid="%d" branches-covered="0" branches-valid="0" complexity="0" version="1.0" timestamp="%d"><sources><source>%s</source></sources><packages><package name="app" line-rate="%.4f" branch-rate="1" complexity="0"><classes>%s</classes></package></packages></coverage>',
            "\n",
            "\n",
            $lineRate,
            $summary['covered'],
            $summary['total'],
            time(),
            $this->escape($base),
            $lineRate,
            implode('', $classesXml)
        );

        file_put_contents($file, $xml);
    }

    /** Generates HTML coverage report */
    private function writeHtmlCoverage(array $coverage)
    {
        $dir = 'coverage';
        !is_dir($dir) && mkdir($dir, 0755, true);

        $summary = $this->coverageSummary($coverage);
        $badge = fn($pct) => sprintf(
            '<span style="background:%s;color:white;padding:4px 8px;border-radius:3px;font-weight:bold">%.2f%%</span>',
            $pct >= 90 ? '#22c55e' : ($pct >= 75 ? '#eab308' : '#ef4444'),
            $pct
        );

        $css = 'body{font-family:system-ui,sans-serif;margin:20px;background:#f9fafb}h1{color:#111827}table{width:100%;border-collapse:collapse;background:white;box-shadow:0 1px 3px rgba(0,0,0,0.1)}th,td{padding:12px;text-align:left;border-bottom:1px solid #e5e7eb}th{background:#f3f4f6;font-weight:600}tr:hover{background:#f9fafb}a{color:#2563eb;text-decoration:none}a:hover{text-decoration:underline}.right{text-align:right}';
        $codeCss = 'body{font-family:system-ui,sans-serif;margin:0;background:#f9fafb}h1{margin:20px;color:#111827}.code{background:white;box-shadow:0 1px 3px rgba(0,0,0,0.1);margin:20px}pre{margin:0;padding:0;font-family:monospace;font-size:13px;line-height:1.5}.line{display:flex;border-bottom:1px solid #f3f4f6}.num{background:#f9fafb;color:#6b7280;padding:0 12px;min-width:50px;text-align:right;user-select:none}.src{flex:1;padding:0 12px;white-space:pre}.covered{background:#dcfce7}.uncovered{background:#fee2e2}a{margin:20px;display:inline-block;color:#2563eb;text-decoration:none}a:hover{text-decoration:underline}';

        // Index page
        $rows = [];
        foreach ($summary['filesBreakdown'] as $file => $data) {
            $rows[] = sprintf(
                '<tr><td>%s</td><td><a href="%s">%s</a></td><td class="right">%d/%d</td></tr>',
                $badge($data['percent']),
                htmlspecialchars(str_replace(['/', '.php'], ['_', ''], $file) . '.html'),
                htmlspecialchars($file),
                $data['covered'],
                $data['total']
            );
        }

        $totalRow = sprintf(
            '<tr style="font-weight:bold;border-top:2px solid #9ca3af"><td>%s</td><td>Total (%d files)</td><td class="right">%d/%d</td></tr>',
            $badge($summary['percent']),
            $summary['files'],
            $summary['covered'],
            $summary['total']
        );

        file_put_contents("$dir/index.html", sprintf(
            '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Coverage Report</title><style>%s</style></head><body><h1>Code Coverage Report</h1><table><thead><tr><th>Coverage</th><th>File</th><th class="right">Lines</th></tr></thead><tbody>%s%s</tbody></table></body></html>',
            $css,
            implode('', $rows),
            $totalRow
        ));

        // Individual file pages
        $base = getcwd() . '/';

        foreach ($coverage as $path => $flags) {
            $name = str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
            $htmlFile = str_replace(['/', '.php'], ['_', ''], $name) . '.html';
            $source = file_get_contents($path);
            $lines = explode("\n", $source);

            $rendered = array_map(function ($i) use ($lines, $flags) {
                $lineNum = $i + 1;
                $code = htmlspecialchars($lines[$i] ?? '');
                $flag = $flags[$lineNum] ?? 0;
                $class = $flag > 0 ? 'covered' : ($flag < 0 ? 'uncovered' : '');
                return sprintf('<div class="line %s"><span class="num">%d</span><span class="src">%s</span></div>', $class, $lineNum, $code);
            }, array_keys($lines));

            $stats = array_reduce($flags, fn($c, $f) => [
                'covered' => $c['covered'] + ($f > 0 ? 1 : 0),
                'total' => $c['total'] + ($f !== 0 ? 1 : 0)
            ], ['covered' => 0, 'total' => 0]);

            $pct = $stats['total'] > 0 ? ($stats['covered'] / $stats['total']) * 100 : 0;

            file_put_contents("$dir/$htmlFile", sprintf(
                '<!DOCTYPE html><html><head><meta charset="utf-8"><title>%s - Coverage</title><style>%s</style></head><body><a href="index.html">&larr; Back to index</a><h1>%s %s</h1><div class="code"><pre>%s</pre></div></body></html>',
                htmlspecialchars($name),
                $codeCss,
                htmlspecialchars($name),
                $badge($pct),
                implode('', $rendered)
            ));
        }
    }

    // PARALLEL ===============================================================

    /** Creates shared memory for inter-process communication */
    private function createMemory()
    {
        $shmId = shm_attach(ftok(__FILE__, 't'), 1024 * 1024);
        $semId = sem_get(ftok(__FILE__, 's'));
        shm_put_var($shmId, 1, ['completed' => 0, 'suiteProgress' => array_fill_keys($this->queue, 0), 'results' => []]);
        return [$shmId, $semId];
    }

    /** Releases shared memory and semaphore */
    private function cleanupMemory($shm)
    {
        [$shmId, $semId] = $shm;
        shm_remove($shmId);
        sem_remove($semId);
    }

    /** Atomically increments counters in shared memory */
    private function incrementMemory($shm, $suiteId, $result = null)
    {
        [$shmId, $semId] = $shm;
        sem_acquire($semId);
        $state = shm_get_var($shmId, 1);
        $state['completed']++;
        $state['suiteProgress'][$suiteId]++;
        if ($result) $state['results'][] = $result;
        shm_put_var($shmId, 1, $state);
        sem_release($semId);
    }

    // COVERAGE ===============================================================

    /** Expands path patterns to PHP files */
    private function expandPhpFiles(array $patterns, bool $realpath = false)
    {
        $files = [];
        $add = function ($path) use (&$files, $realpath) {
            if (!is_file($path)) return;
            if (pathinfo($path, PATHINFO_EXTENSION) !== 'php') return;
            if ($realpath) $path = realpath($path) ?: null;
            if (!$path) return;
            $files[] = $path;
        };

        foreach ($patterns as $pattern) {
            if (is_dir($pattern)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($pattern, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );

                foreach ($iterator as $item) $add($item->getPathname());
                continue;
            }

            [$base, $rest] = str_contains($pattern, '**') ? explode('**', $pattern, 2) : [null, null];

            if (!$base || !is_dir($base = rtrim($base, '/'))) {
                foreach (glob($pattern) ?: [] as $match) $add($match);
                continue;
            }

            $suffix = ltrim($rest, '/');
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $item) {
                $path = $item->getPathname();
                if ($suffix && !fnmatch('*' . $suffix, $path)) continue;
                $add($path);
            }
        }

        return array_values(array_unique($files));
    }

    /** Starts code coverage collection */
    private function startCoverage(bool $requested)
    {
        if (!$requested) return false;

        if (!extension_loaded('pcov')) {
            Console::warn('pcov not available; skipping coverage.');
            return false;
        }

        // Set pcov.directory to project root to instrument all files
        // Actual filtering is done in stopCoverage() with \pcov\inclusive
        ini_set('pcov.directory', dirname(__DIR__));

        \pcov\clear();
        \pcov\start();

        return true;
    }

    /** Stops collection and shows or saves coverage */
    private function stopCoverage(bool $enabled, $coveragePath = null)
    {
        if (!$enabled) return null;

        // Use inclusive mode (1) to collect only the files we care about (from sourcePaths)
        // Constants: all=0, inclusive=1, exclusive=2
        $files = array_filter($this->expandPhpFiles($this->sourcePaths, true), fn($f) => $f !== __FILE__);
        $coverage = \pcov\collect(1, $files);
        \pcov\stop();

        if ($coveragePath === 'html') return $this->writeHtmlCoverage($coverage) ?: $coverage;
        if (is_string($coveragePath)) return $this->writeCoverage($coveragePath, $coverage) ?: $coverage;

        $summary = $this->coverageSummary($coverage);
        Console::blank();
        Console::bold()->line('Coverage:');

        if ($summary['files'] === 0 || $summary['total'] === 0) return Console::line('  No executable lines registered in src/.') ?: $coverage;

        Console::blank();

        $badge = fn($pct) => match (true) {
            $pct >= 90 => Console::bgGreen(sprintf(' %6.2f%% ', $pct)),
            $pct >= 75 => Console::bgYellow(sprintf(' %6.2f%% ', $pct)),
            default => Console::bgRed(sprintf(' %6.2f%% ', $pct)),
        };

        $rows = array_map(fn($file, $data) => [
            '%' => $badge($data['percent']),
            'File' => $file,
            'Lines' => sprintf('%d/%d', $data['covered'], $data['total']),
        ], array_keys($summary['filesBreakdown']), $summary['filesBreakdown']);

        $rows[] = [
            '%' => $badge($summary['percent']),
            'File' => Console::bold(sprintf('Total (%d files)', $summary['files'])),
            'Lines' => Console::bold(sprintf('%d/%d', $summary['covered'], $summary['total'])),
        ];

        Console::table(['%' => '', 'File' => 'File', 'Lines' => '>Lines'], $rows);

        return $coverage;
    }

    /**
     * Calculates coverage summary per file
     * @param array<string, array<int, int>> $coverage
     * @return array{files:int,covered:int,total:int,percent:float,filesBreakdown:array<string,array{covered:int,total:int,percent:float}>}
     */
    private function coverageSummary(array $coverage)
    {
        $base = getcwd() . '/';
        [$files, $coveredTotal, $linesTotal] = [[], 0, 0];

        foreach ($coverage as $file => $flags) {
            $covered = count(array_filter($flags, fn($f) => $f > 0));
            $total = count(array_filter($flags, fn($f) => $f !== 0));

            if ($total === 0) continue;

            $name = str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
            $files[$name] = compact('covered', 'total') + ['percent' => ($covered / $total) * 100];
            $coveredTotal += $covered;
            $linesTotal += $total;
        }

        ksort($files);
        return [
            'files' => count($files),
            'covered' => $coveredTotal,
            'total' => $linesTotal,
            'percent' => $linesTotal ? ($coveredTotal / $linesTotal) * 100 : 0.0,
            'filesBreakdown' => $files,
        ];
    }

    // STATE ==================================================================

    /** Loads test files from the configured directory */
    private function load()
    {
        $this->reset();

        $files = $this->expandPhpFiles($this->testPaths);

        sort($files);

        foreach ($files as $file) {
            $this->file = $file;
            require $file;
            $this->file = null;
        }

        return true;
    }

    /** Lists all available suites and tests */
    private function list()
    {
        if (!$this->load()) return 1;

        if ($this->queue === []) {
            Console::line('No suites registered.');
            return 0;
        }

        $opts = Console::options();
        // Support both --filter=X and positional argument
        $filter = $opts['filter'] ?? $opts['_'][0] ?? null;
        $shown = 0;

        $base = getcwd() . '/';

        foreach ($this->queue as $suiteId) {
            $suite = $this->suites[$suiteId];
            $tests = $this->filter($suite, $filter, false);

            if ($tests === []) continue;

            $shown++;
            $filePath = $suite['file'] && str_starts_with($suite['file'], $base)
                ? substr($suite['file'], strlen($base))
                : $suite['file'];

            Console::line(Console::bold($suite['title']));
            foreach ($tests as $test) {
                $location = ($filePath && isset($test['line']))
                    ? ' ' . Console::dim($filePath . ':' . $test['line'])
                    : '';
                Console::line('  ' . $test['name'] . $location);
            }
            Console::blank();
        }

        if ($shown === 0) Console::line('No tests match the filter.');

        return 0;
    }

    /** Resets the runner state */
    private function reset()
    {
        $this->suites = [];
        $this->queue = [];
        $this->current = null;
        $this->file = null;
        $this->hasOnly = false;
        $this->summary = array_fill_keys(['passed', 'failed', 'skipped'], 0);
        $this->results = [];
    }

    // HELPERS ================================================================

    /** Executes callback and returns $this for method chaining */
    private function tap(callable $callback): self
    {
        if ($this->current === null) throw new LogicException('Define a suite before registering tests or hooks.');
        $callback();
        return $this;
    }

    /** Registers a hook in the current suite */
    private static function hook(string $slot, callable $handler)
    {
        $self = self::instance();
        return $self->tap(fn() => $self->suites[$self->current][$slot][] = $self->wrap($handler));
    }

    /** Escapes values for safe XML output */
    private function escape($value)
    {
        return htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Normalizes throwable data into a standard array structure */
    private function normalizeError($error)
    {
        if (!$error) return null;

        $normalizeTrace = static fn($frames) => array_map(static fn($f) => [
            'file' => $f['file'] ?? '[internal]',
            'line' => $f['line'] ?? 0,
            'function' => $f['function'] ?? 'closure',
            'class' => $f['class'] ?? null,
        ], $frames);

        if (is_array($error)) {
            if (isset($error['trace'])) $error['trace'] = $normalizeTrace($error['trace']);
            return $error;
        }

        return [
            'message' => $error->getMessage(),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
            'trace' => $normalizeTrace($error->getTrace()),
        ];
    }

    /** Gets code snippet around a specific line */
    private function getCodeSnippet(string $file, int $line, int $context = 1): array
    {
        if (!is_file($file) || !is_readable($file)) return [];

        $lines = file($file);
        if ($lines === false) return [];

        $start = max(0, $line - $context - 1);
        $end = min(count($lines), $line + $context);
        $snippet = [];

        for ($i = $start; $i < $end; $i++) {
            $snippet[$i + 1] = rtrim($lines[$i]);
        }

        return $snippet;
    }

    /** Filters stack trace to show only relevant frames */
    private function filterStackTrace(array $trace, ?string $testFile): array
    {
        $base = getcwd() . '/';
        $testRunnerFile = __FILE__;

        $filtered = [];
        foreach ($trace as $frame) {
            // Skip internal test runner frames
            if ($frame['file'] === $testRunnerFile) continue;
            if ($frame['file'] === '[internal]') continue;

            // Format the frame
            $file = str_starts_with($frame['file'], $base)
                ? substr($frame['file'], strlen($base))
                : $frame['file'];

            $func = $frame['function'];
            if ($frame['class'] ?? false) {
                $func = $frame['class'] . '->' . $func . '()';
            } elseif ($func === '{closure}' || str_contains($func, '{closure')) {
                $func = '{closure}';
            } else {
                $func .= '()';
            }

            $filtered[] = sprintf('%s (%s:%d)', $func, $file, $frame['line']);
        }

        return $filtered;
    }

    /** Normalizes callables to closures with consistent signature */
    private function wrap(callable $handler)
    {
        $handler = $handler instanceof Closure ? $handler : Closure::fromCallable($handler);
        $params = (new ReflectionFunction($handler))->getNumberOfParameters();

        if ($params > 1) throw new InvalidArgumentException('Test handlers accept at most one parameter.');

        return $params === 0 ? static fn(ArrayObject $state) => $handler() : static fn(ArrayObject $state) => $handler($state);
    }

    /** Marks a suite or test with a flag (skip or only) */
    private function flag(string $flag, ?string $target)
    {
        $suite = &$this->suites[$this->current];

        if ($target !== null) {
            foreach ($suite['tests'] as $i => $test) {
                if ($test['name'] === $target) {
                    $suite['tests'][$i][$flag] = true;
                    if ($flag === 'only') $this->hasOnly = true;
                    return;
                }
            }
            throw new LogicException(sprintf("Test '%s' is not defined in suite '%s'.", $target, $suite['title']));
        }

        $suite['last'] !== null ? $suite['tests'][$suite['last']][$flag] = true : $suite[$flag] = true;

        if ($flag === 'only') $this->hasOnly = true;
    }

    /**
     * Filters tests from a suite according to filter and 'only' flag
     * @param array<string, mixed> $suite
     * @return list<array{name:string,handler:Closure,skip:bool,only:bool}>
     */
    private function filter(array $suite, ?string $filter, bool $respectOnly = true)
    {
        $tests = $suite['tests'];
        if ($tests === []) return [];
        if ($respectOnly && $this->hasOnly && !$suite['only']) $tests = array_filter($tests, static fn($test) => $test['only']);
        if ($filter) {
            $needle = strtolower($filter);
            $tests = array_filter(
                $tests,
                static fn($test) =>
                str_contains(strtolower($suite['title']), $needle) ||
                    str_contains(strtolower($test['name']), $needle) ||
                    str_contains(strtolower($suite['file'] ?? ''), $needle)
            );
        }
        return array_values($tests);
    }

    /** Executes an operation catching exceptions */
    private function guard(callable $operation, string $where, bool $silent = false)
    {
        try {
            $operation();
            return true;
        } catch (Throwable $error) {
            if (!$silent) $this->render('fail', $where, null, $error);
            return false;
        }
    }

    /** Detects the number of CPU cores available */
    private function detectCpuCount()
    {
        return match (PHP_OS_FAMILY) {
            'Linux' => (int)@shell_exec('nproc') ?: 4,
            'Darwin' => (int)@shell_exec('sysctl -n hw.ncpu') ?: 4,
            default => 4
        };
    }
}
