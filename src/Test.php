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
 * Test runner.
 * 
 * Files within the configured directory should register suites using:
 *
 * ```php
 * Test::describe('Contexto', function () {
 *   Test::beforeEach(fn($state) => $state['count'] = ($state['count'] ?? 0) + 1);
 *   Test::it('should increment the counter', function ($state) {
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
    public static function register(CoreConsole $cli, array $paths = []): self
    {
        $normalize = fn($value) => array_values((array)($value ?? []));

        $self = self::$instance ??= new self();
        $self->testPaths = $normalize($paths['tests'] ?? 'tests');
        $self->sourcePaths = $normalize($paths['src'] ?? 'src');

        $cli->command('test', fn() => $self->run())->describe(sprintf('Runs tests defined in %s.', implode(', ', $self->testPaths)));
        $cli->command('test:list', fn() => $self->list())->describe('Lists available test suites and cases.');

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

    /** Alias for suite() with describe/it nomenclature */
    public static function describe(string $name, callable $builder, array $state = []): self
    {
        return self::suite($name, $builder, $state);
    }

    /** Registers a test case */
    public static function case(string $name, callable $handler, array $options = []): self
    {
        return self::instance()->tap(function () use ($name, $handler, $options) {
            $self = self::$instance;
            $suite = &$self->suites[$self->current];
            $only = (bool)($options['only'] ?? false);
            $suite['tests'][] = ['name' => $name, 'handler' => $self->wrap($handler), 'skip' => (bool)($options['skip'] ?? false), 'only' => $only];
            $suite['last'] = array_key_last($suite['tests']);
            if ($only) $self->hasOnly = true;
        });
    }

    /** Alias for case() with describe/it nomenclature */
    public static function it(string $name, callable $handler, array $options = []): self
    {
        return self::case($name, $handler, $options);
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

    /** Dynamic dispatcher for assertion methods */
    public static function __callStatic(string $name, array $args)
    {
        $msg = fn($i) => $args[$i] ?? '';
        $assertions = [
            'assertTrue'                 => fn() => self::ensure($args[0] === true, $msg(1)                    ?: 'Expected true.'),
            'assertFalse'                => fn() => self::ensure($args[0] === false, $msg(1)                   ?: 'Expected false.'),
            'assertNull'                 => fn() => self::ensure($args[0] === null, $msg(1)                    ?: 'Expected null.'),
            'assertNotNull'              => fn() => self::ensure($args[0] !== null, $msg(1)                    ?: 'Did not expect null.'),
            'assertNotFalse'             => fn() => self::ensure($args[0] !== false, $msg(1)                   ?: 'Expected value other than false.'),
            'assertSame'                 => fn() => self::ensure($args[0] === $args[1], $msg(2)                ?: sprintf('Expected identical value to %s.', var_export($args[0], true))),
            'assertNotSame'              => fn() => self::ensure($args[0] !== $args[1], $msg(2)                ?: 'Expected different value.'),
            'assertEquals'               => fn() => self::ensure($args[0] == $args[1], $msg(2)                 ?: 'Values do not match.'),
            'assertNotEquals'            => fn() => self::ensure($args[0] != $args[1], $msg(2)                 ?: 'Values should not match.'),
            'assertCount'                => fn() => self::ensure(count($args[1]) === $args[0], $msg(2)         ?: sprintf('Expected %d elements.', $args[0])),
            'assertArrayHasKey'          => fn() => self::ensure(array_key_exists($args[0], $args[1]), $msg(2) ?: sprintf("Key '%s' does not exist in array.", (string)$args[0])),
            'assertInstanceOf'           => fn() => self::ensure($args[1] instanceof $args[0], $msg(2)         ?: sprintf('Expected instance of %s.', $args[0])),
            'assertStringContainsString' => fn() => self::ensure(str_contains($args[1], $args[0]), $msg(2)     ?: sprintf("Text does not contain '%s'.", $args[0])),
            'assertContains'             => fn() => self::ensure(
                is_string($args[1]) ? str_contains($args[1], (string)$args[0]) : in_array($args[0], is_array($args[1]) ? $args[1] : iterator_to_array($args[1])),
                $msg(2) ?: 'Expected value not found.'
            ),
        ];

        if (isset($assertions[$name])) return $assertions[$name]();
        throw new \BadMethodCallException("Method $name does not exist");
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
        ['bail' => $bail, 'filter' => $filter, 'coverage' => $coverage, 'log' => $log, 'parallel' => $workers] = $this->options(Console::arguments());

        if (!$this->load()) return 1;

        if ($workers > 1 && function_exists('pcntl_fork')) {
            return $this->parallel($workers, $bail, $filter, $coverage, $log);
        }

        return $this->sequential($bail, $filter, $coverage, $log);
    }

    /** Executes tests sequentially in a single process */
    private function sequential(bool $bail, $filter, $coverage, $log, $shm = null)
    {
        $isParallel = $shm !== null;
        $coverageEnabled = $this->startCoverage($coverage !== null);

        if ($this->queue === []) {
            if (!$isParallel) Console::log('No tests found.');
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
            $class = $this->classname($suite['file']);

            if (!$isParallel) {
                Console::blank();
                Console::log(Console::bold($title));
            }

            $record = fn($type, $name, $seconds = null, $error = null, $testIndex = null) => $isParallel
                ? $this->incrementMemory($shm, $suiteId, compact('type', 'name', 'seconds', 'testIndex') + ['suite' => $title, 'suiteId' => $suiteId, 'classname' => $class, 'error' => $error ? $this->normalizeError($error) : null])
                : $this->render($type, $name, $seconds, $error ? $this->normalizeError($error) : null, $title, $class);

            if ($suite['skip']) {
                foreach ($tests as $testIndex => $test) $record('skip', $test['name'], testIndex: $testIndex);
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
                    $record('skip', $test['name'], testIndex: $testIndex);
                    continue;
                }

                $started = microtime(true);

                if (!$runHooks($suite['beforeEach'], $test['name'] . ' (before each)')) {
                    $record('fail', $test['name'] . ' (before each)', testIndex: $testIndex);
                    if ($fail()) return $bailout();
                    continue;
                }

                try {
                    ($test['handler'])($state);
                    $record('pass', $test['name'], microtime(true) - $started, testIndex: $testIndex);
                } catch (Throwable $error) {
                    $record('fail', $test['name'], microtime(true) - $started, $error, $testIndex);
                    if ($fail()) return $bailout();
                }

                if (!$runHooks($suite['afterEach'], $test['name'] . ' (after each)')) {
                    $record('fail', $test['name'] . ' (after each)', testIndex: $testIndex);
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
            Console::warn($coverage ? 'Coverage is not supported in parallel mode. Running sequentially...' : 'sysvshm not available, falling back to sequential...');
            return $this->sequential($bail, $filter, $coverage, $log);
        }

        $chunks = array_chunk($this->queue, (int)ceil(count($this->queue) / $workers));
        $testsPerSuite = array_map(fn($id) => count($this->filter($this->suites[$id], $filter)), $this->queue);
        $totalTests = array_sum($testsPerSuite);
        $shm = $this->createMemory();
        $started = microtime(true);

        Console::log(sprintf('Running %d tests in %d workers...', $totalTests, count($chunks)));
        Console::blank();

        $pids = [];

        foreach ($chunks as $chunk) {
            $pid = pcntl_fork();
            if ($pid === -1) return (Console::warn('Fork failed, falling back to sequential...') || $this->cleanupMemory($shm)) && $this->sequential($bail, $filter, $coverage, $log);
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
    private function render(string $type, string $name, ?float $seconds = null, $error = null, ?string $suite = null, ?string $classname = null)
    {
        [$badge, $color, $counter] = match ($type) {
            'pass' => ['[PASS]', 'green', 'passed'],
            'fail' => ['[FAIL]', 'red', 'failed'],
            'skip' => ['[SKIP]', 'yellow', 'skipped'],
        };

        Console::log(sprintf(
            '  %s %s %s',
            Console::$color($badge),
            $name,
            $seconds ? Console::dim(sprintf('(%s)', $this->duration($seconds))) : ''
        ));

        $normalized = $error ? $this->normalizeError($error) : null;

        if ($normalized) {
            Console::red()->log('    ' . $normalized['message']);
            Console::dim()->log(sprintf('    at %s:%d', $normalized['file'], $normalized['line']));
            array_walk(array_slice($normalized['trace'], 0, 6), fn($f) => Console::dim()->log(sprintf(
                '      %s:%d %s()',
                $f['file'],
                $f['line'],
                $f['function']
            )));
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
        Console::bold()->log('Summary:');
        Console::log('  Total:   ' . array_sum($this->summary));
        Console::log('  Passed:  ' . $this->summary['passed']);
        Console::log('  Skipped: ' . $this->summary['skipped']);
        Console::log('  Failed:  ' . $this->summary['failed']);
        Console::log('  Time:    ' . $this->duration(microtime(true) - $startedAt));
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
            Console::log(Console::bold($suite['title']));
            array_walk($grouped[$suiteId], fn($r) => $this->render(
                $r['type'],
                $r['name'],
                $r['seconds'] ?? null,
                $r['error'] ?? null,
                $r['suite'],
                $r['classname']
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
        $xml = $this->xml();
        $suites = [];
        foreach ($this->results as $r) $suites[$r['suite']][] = $r;

        $xml->startElement('testsuites');
        $this->attrs($xml, [
            'tests' => array_sum($this->summary),
            'failures' => $this->summary['failed'],
            'errors' => 0,
            'time' => sprintf('%.6f', $totalTime)
        ]);

        foreach ($suites as $suiteName => $tests) {
            $stats = array_reduce($tests, fn($c, $t) => [
                'failures' => $c['failures'] + ($t['status'] === 'fail'),
                'skipped' => $c['skipped'] + ($t['status'] === 'skip'),
                'time' => $c['time'] + $t['time']
            ], ['failures' => 0, 'skipped' => 0, 'time' => 0.0]);

            $xml->startElement('testsuite');
            $this->attrs($xml, [
                'name' => $suiteName,
                'tests' => count($tests),
                'failures' => $stats['failures'],
                'errors' => 0,
                'skipped' => $stats['skipped'],
                'time' => sprintf('%.6f', $stats['time'])
            ]);

            foreach ($tests as $test) {
                $xml->startElement('testcase');
                $this->attrs($xml, ['name' => $test['name'], 'classname' => $test['classname'], 'time' => sprintf('%.6f', $test['time'])]);

                if ($test['status'] === 'fail') {
                    $e = $test['error'];
                    $type = str_contains($e['message'], 'AssertionError') ? 'failure' : 'error';
                    $xml->startElement($type);
                    $this->attrs($xml, ['message' => $e['message'], 'type' => $type === 'failure' ? 'AssertionError' : 'RuntimeException']);
                    $xml->text(sprintf("%s:%d\n", $e['file'], $e['line']) . implode('', array_map(fn($f) => sprintf("  at %s:%d %s()\n", $f['file'], $f['line'], $f['function']), array_slice($e['trace'], 0, 10))));
                    $xml->endElement();
                } elseif ($test['status'] === 'skip') {
                    $xml->writeElement('skipped', '');
                }

                $xml->endElement();
            }

            $xml->endElement();
        }

        $xml->endElement();
        file_put_contents($file, $xml->outputMemory());
    }

    /** Writes code coverage in Clover XML format */
    private function writeCoverage(string $file, array $coverage)
    {
        $xml = $this->xml();
        $summary = $this->coverageSummary($coverage);
        $metrics = fn($covered, $total, $loc = null, $files = null) => $this->attrs($xml, compact('loc', 'files') + [
            'ncloc' => $total,
            'classes' => 0,
            'methods' => 0,
            'coveredmethods' => 0,
            'conditionals' => 0,
            'coveredconditionals' => 0,
            'statements' => $total,
            'coveredstatements' => $covered,
            'elements' => $total,
            'coveredelements' => $covered
        ]);

        $xml->startElement('coverage');
        $this->attrs($xml, ['generated' => time()]);
        $xml->startElement('project');
        $this->attrs($xml, ['timestamp' => time()]);

        foreach ($coverage as $path => $flags) {
            $xml->startElement('file');
            $this->attrs($xml, ['name' => $path]);

            [$covered, $total] = [0, 0];
            foreach ($flags as $line => $flag) {
                if ($flag === 0) continue;
                $xml->startElement('line');
                $this->attrs($xml, ['num' => $line, 'type' => 'stmt', 'count' => $flag > 0 ? 1 : 0]);
                $xml->endElement();
                $flag > 0 && $covered++;
                $total++;
            }

            $xml->startElement('metrics');
            $metrics($covered, $total, count($flags));
            $xml->endElement();
            $xml->endElement();
        }

        $xml->startElement('metrics');
        $metrics($summary['covered'], $summary['total'], null, $summary['files']);
        $xml->endElement();
        $xml->endElement();
        file_put_contents($file, $xml->outputMemory());
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

        if (is_string($coveragePath)) return $this->writeCoverage($coveragePath, $coverage) ?: $coverage;

        $summary = $this->coverageSummary($coverage);
        Console::blank();
        Console::bold()->log('Coverage:');

        if ($summary['files'] === 0 || $summary['total'] === 0) return Console::log('  No executable lines registered in src/.') ?: $coverage;

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
        $first = $this->sourcePaths[0] ?? '';
        $base = $first ? rtrim(realpath(is_dir($first) ? $first : dirname($first)) ?: $first, '/') . '/' : dirname(__DIR__) . '/';
        [$files, $coveredTotal, $linesTotal] = [[], 0, 0];

        foreach ($coverage as $file => $flags) {
            $covered = count(array_filter($flags, fn($f) => $f > 0));
            $total = count(array_filter($flags, fn($f) => $f !== 0));

            if ($total === 0) continue;

            $name = str_starts_with($file, $base) ? substr($file, strlen($base)) : basename($file);
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
            Console::log('No suites registered.');
            return 0;
        }

        $filter = $this->options(Console::arguments())['filter'];
        $shown = 0;

        foreach ($this->queue as $suiteId) {
            $suite = $this->suites[$suiteId];
            $tests = $this->filter($suite, $filter, false);

            if ($tests === []) continue;

            $shown++;
            Console::log(Console::bold($suite['title']));
            foreach ($tests as $test) Console::log('  - ' . $test['name']);
            if ($suite['file']) Console::dim()->log('    ' . $suite['file']);
            Console::blank();
        }

        if ($shown === 0) Console::log('No tests match the filter.');

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

    /** Converts file path to a classname */
    private function classname($file)
    {
        if (!$file) return 'Tests';

        // Find the common base from testPaths
        $base = $this->testPaths[0] ?? '';
        $base = is_dir($base) ? rtrim($base, '/') . '/' : dirname($base) . '/';

        return str_replace(['/', '.php'], ['\\', ''], substr($file, strlen($base)));
    }

    /** Creates an XMLWriter configured for formatted output */
    private function xml()
    {
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');
        $xml->startDocument('1.0', 'UTF-8');
        return $xml;
    }

    /** Writes attributes in batch to an XML element */
    private function attrs($xml, array $attributes)
    {
        foreach ($attributes as $k => $v) if ($v !== null) $xml->writeAttribute($k, is_string($v) ? $v : (string)$v);
    }

    /** Normalizes throwable data into a standard array structure */
    private function normalizeError($error)
    {
        if (!$error) return null;

        $normalizeTrace = static fn($frames) => array_map(static fn($f) => [
            'file' => $f['file'] ?? '[internal]',
            'line' => $f['line'] ?? 0,
            'function' => $f['function'] ?? 'closure'
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
            $tests = array_filter($tests, static fn($test) => str_contains(strtolower($suite['title']), $needle) || str_contains(strtolower($test['name']), $needle));
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

    /** Parses console arguments to extract options */
    private function options(array $arguments)
    {
        $options = ['filter' => null, 'bail' => false, 'coverage' => null, 'log' => null, 'parallel' => 1];
        $cpuCount = fn() => match (PHP_OS_FAMILY) {
            'Linux' => (int)@shell_exec('nproc') ?: 4,
            'Darwin' => (int)@shell_exec('sysctl -n hw.ncpu') ?: 4,
            default => 4
        };

        foreach ($arguments as $arg) {
            match (true) {
                in_array($arg, ['--bail', '-b']) => $options['bail'] = true,
                in_array($arg, ['--coverage', '-c']) => $options['coverage'] = true,
                str_starts_with($arg, '--coverage=') => $options['coverage'] = substr($arg, 11) ?: null,
                str_starts_with($arg, '--log=') => $options['log'] = substr($arg, 6) ?: null,
                str_starts_with($arg, '--filter=') => $options['filter'] = substr($arg, 9) ?: null,
                in_array($arg, ['--parallel', '-p']) => $options['parallel'] = $cpuCount(),
                str_starts_with($arg, '--parallel=') => $options['parallel'] = max(1, (int)substr($arg, 11)),
                $options['filter'] === null => $options['filter'] = $arg ?: null,
                default => null
            };
        }

        return $options;
    }
}
