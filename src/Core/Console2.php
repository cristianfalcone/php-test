<?php

declare(strict_types=1);

/**
 * Ajo\Core\Console — micro CLI + PSR-3 logger
 *
 * Features
 * - Command registry, automatic help, middleware (via Router base class)
 * - Sade/mri-like argument parser (aliases, negation, arrays, `--`)
 * - Structured output (stdout/stderr), TTY detection, colors/timestamps
 * - PSR‑3 compliant (eight level methods + log($level,...), {placeholders}, context['exception'])
 *
 * Implementation language: English (code, comments, messages).
 *
 * MIT © Ajo
 */

/* ================================================
 *  PSR-3 stubs (only if psr/log is not installed)
 * ================================================ */

namespace Psr\Log;

if (!interface_exists(LoggerInterface::class)) {
  interface LoggerInterface
  {
    public function emergency($message, array $context = []): void;
    public function alert($message, array $context = []): void;
    public function critical($message, array $context = []): void;
    public function error($message, array $context = []): void;
    public function warning($message, array $context = []): void;
    public function notice($message, array $context = []): void;
    public function info($message, array $context = []): void;
    public function debug($message, array $context = []): void;
    public function log($level, $message, array $context = []): void;
  }
}

if (!interface_exists(InvalidArgumentException::class)) {
  interface InvalidArgumentException extends \Throwable {}
}

if (!class_exists(LogLevel::class)) {
  final class LogLevel
  {
    public const EMERGENCY = 'emergency';
    public const ALERT     = 'alert';
    public const CRITICAL  = 'critical';
    public const ERROR     = 'error';
    public const WARNING   = 'warning';
    public const NOTICE    = 'notice';
    public const INFO      = 'info';
    public const DEBUG     = 'debug';
  }
}

/* ================================================
 *  Console implementation
 * ================================================ */

namespace Ajo\Core;

use Psr\Log\InvalidArgumentException as PsrInvalidArgument;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

/**
 * Console2 — CLI + PSR‑3 Logger.
 *
 * Usage (quick):
 *   use Ajo\Console2;
 *   Console2::command('hello', fn() => (Console2::success('Hello!') || 0));
 *   exit(Console2::dispatch());
 *
 * @method StyleBuilder|string bold(string $text = '') Apply bold style
 * @method StyleBuilder|string dim(string $text = '') Apply dim style
 * @method StyleBuilder|string italic(string $text = '') Apply italic style
 * @method StyleBuilder|string underline(string $text = '') Apply underline style
 * @method StyleBuilder|string inverse(string $text = '') Apply inverse style
 * @method StyleBuilder|string hidden(string $text = '') Apply hidden style
 * @method StyleBuilder|string strikethrough(string $text = '') Apply strikethrough style
 * @method StyleBuilder|string black(string $text = '') Apply black color
 * @method StyleBuilder|string red(string $text = '') Apply red color
 * @method StyleBuilder|string green(string $text = '') Apply green color
 * @method StyleBuilder|string yellow(string $text = '') Apply yellow color
 * @method StyleBuilder|string blue(string $text = '') Apply blue color
 * @method StyleBuilder|string magenta(string $text = '') Apply magenta color
 * @method StyleBuilder|string cyan(string $text = '') Apply cyan color
 * @method StyleBuilder|string white(string $text = '') Apply white color
 * @method StyleBuilder|string gray(string $text = '') Apply gray color
 * @method StyleBuilder|string bgBlack(string $text = '') Apply black background
 * @method StyleBuilder|string bgRed(string $text = '') Apply red background
 * @method StyleBuilder|string bgGreen(string $text = '') Apply green background
 * @method StyleBuilder|string bgYellow(string $text = '') Apply yellow background
 * @method StyleBuilder|string bgBlue(string $text = '') Apply blue background
 * @method StyleBuilder|string bgMagenta(string $text = '') Apply magenta background
 * @method StyleBuilder|string bgCyan(string $text = '') Apply cyan background
 * @method StyleBuilder|string bgWhite(string $text = '') Apply white background
 */
final class Console2 extends Router implements LoggerInterface
{
  /* ---------------------------- CLI state ----------------------------- */

  /**
   * Command registry:
   * - name
   * - handler
   * - description
   * - usages (list)
   * - options (list of ["flags","desc","default"])
   * - examples (list of ["usage","desc"])
   * - aliases (short => long)
   *
   * @var array<string, array{
   *   name:string,
   *   handler:callable,
   *   description?:string,
   *   usages?:list<string>,
   *   options?:list<array{flags:string,desc?:string,default?:mixed}>,
   *   examples?:list<array{usage:string,desc:?string}>,
   *   aliases?:array<string,string>
   * }>
   */
  private array $commands = [];

  private ?string $activeCommand = null;   // command under configuration (fluent builder)
  private string  $binaryName    = 'console';
  private string  $currentCommand = '';    // command being dispatched

  /** Raw argv for current command (excluding the command name) */
  private array $rawArguments = [];

  /** Parsed options for current command (positionals in '_') */
  private array $parsedOptions = [];

  /** @var resource|null */
  private $stdout = null;
  /** @var resource|null */
  private $stderr = null;

  private bool $interactive = false;
  private ?bool $colorsEnabled = null;      // null = auto-detect
  private ?bool $timestampsEnabled = null;  // null = auto-detect

  /* -------------------------- ANSI & styles --------------------------- */

  /**
   * ANSI codes as positional arrays [start, end].
   * Access with $codes[0] (start), $codes[1] (end).
   */
  private const ANSI = [
    'reset'         => ['0',  '0'],
    'bold'          => ['1',  '22'],
    'dim'           => ['2',  '22'],
    'italic'        => ['3',  '23'],
    'underline'     => ['4',  '24'],
    'inverse'       => ['7',  '27'],
    'hidden'        => ['8',  '28'],
    'strikethrough' => ['9',  '29'],

    'black'         => ['30', '39'],
    'red'           => ['31', '39'],
    'green'         => ['32', '39'],
    'yellow'        => ['33', '39'],
    'blue'          => ['34', '39'],
    'magenta'       => ['35', '39'],
    'cyan'          => ['36', '39'],
    'white'         => ['37', '39'],
    'gray'          => ['90', '39'],

    'bgBlack'       => ['40', '49'],
    'bgRed'         => ['41', '49'],
    'bgGreen'       => ['42', '49'],
    'bgYellow'      => ['43', '49'],
    'bgBlue'        => ['44', '49'],
    'bgMagenta'     => ['45', '49'],
    'bgCyan'        => ['46', '49'],
    'bgWhite'       => ['47', '49'],
  ];

  /** Level → style mapping (used by level rendering) */
  private const LEVEL_STYLE = [
    'debug'     => 'gray',
    'info'      => 'blue',
    'notice'    => 'cyan',
    'success'   => 'green',
    'warning'   => 'yellow',
    'error'     => 'red',
    'critical'  => 'red',
    'alert'     => 'magenta',
    'emergency' => 'bgRed',
  ];

  /* ------------------------- Constructor/Help ------------------------- */

  public function __construct(?callable $notFoundHandler = null, ?callable $exceptionHandler = null)
  {
    parent::__construct($notFoundHandler, $exceptionHandler);

    // Initialize streams to default stdout/stderr
    $this->stdout = \STDOUT;
    $this->stderr = \STDERR;

    // Built-in `help` command
    $this->command('help', function (): int {
      $target = $this->options()['_'][0] ?? null;

      if ($target === null) {
        $list = array_keys(array_diff_key($this->commands, ['help' => 1]));
        if ($list === []) {
          $this->line('No commands registered.');
          return 0;
        }

        sort($list, SORT_STRING);
        $this->line('Available commands:');
        $this->blank();

        $w = max(array_map('strlen', $list));
        foreach ($list as $name) {
          $desc = (string)($this->commands[$name]['description'] ?? '');
          $this->line(sprintf("  %-{$w}s  %s", $name, $desc));
        }
        $this->blank();
        $this->line("Use: {$this->binaryName} help <command> to see details.");
        return 0;
      }

      if (!isset($this->commands[$target])) {
        $this->error("Command not found: {$target}");
        return 1;
      }

      $cmd = $this->commands[$target];

      $this->bold()->line($target);
      if (!empty($cmd['description'])) {
        $this->line('  ' . $cmd['description']);
        $this->blank();
      }

      $usages = $cmd['usages'] ?? ["[options]"];
      $this->line('Usage:');
      foreach ($usages as $u) {
        $this->line("  {$this->binaryName} {$target} {$u}");
      }

      if (!empty($cmd['options'])) {
        $this->blank();
        $this->line('Options:');
        $w = 0;
        $rows = [];
        foreach ($cmd['options'] as $opt) {
          $flags = $opt['flags'];
          $w = max($w, strlen($flags));
          $rows[] = [$flags, $opt['desc'] ?? '', $opt['default'] ?? null];
        }
        foreach ($rows as [$flags, $desc, $def]) {
          $suffix = $def !== null ? " (default: {$def})" : '';
          $this->line(sprintf("  %-{$w}s  %s%s", $flags, $desc, $suffix));
        }
      }

      if (!empty($cmd['examples'])) {
        $this->blank();
        $this->line('Examples:');
        foreach ($cmd['examples'] as $ex) {
          $d = $ex['desc'] ? ("\n    " . $ex['desc']) : '';
          $this->line("  {$this->binaryName} {$target} {$ex['usage']}{$d}");
        }
      }

      return 0;
    });
  }

  /* ----------------------- Fluent registration ------------------------ */

  public function command(string $name, callable $handler): self
  {
    $this->commands[$name] = [
      'name'        => $name,
      'handler'     => $handler,
      'usages'      => [],
      'options'     => [],
      'examples'    => [],
      'aliases'     => [], // short => long
    ];
    $this->activeCommand = $name;
    return $this;
  }

  public function describe(string $text): self
  {
    $this->assertActive('describe');
    $this->commands[$this->activeCommand]['description'] = $text;
    return $this;
  }

  public function usage(string $pattern): self
  {
    $this->assertActive('usage');
    $this->commands[$this->activeCommand]['usages'][] = trim($pattern);
    return $this;
  }

  /**
   * Declare options. Supports "-v, --verbose" formats.
   */
  public function option(string $flags, string $description = '', mixed $default = null): self
  {
    $this->assertActive('option');
    // Normalize: preserve comma+space, but trim extra spaces
    $normalized = preg_replace('/\s*,\s*/', ', ', trim($flags));
    $normalized = preg_replace('/\s+/', ' ', $normalized);

    $spec = ['flags' => $normalized, 'desc' => $description, 'default' => $default];

    // Build short -> long alias mapping
    $shorts = [];
    $long = null;
    foreach (array_map('trim', explode(',', $normalized)) as $f) {
      if (str_starts_with($f, '--')) {
        $long = ltrim($f, '-');
      } elseif (str_starts_with($f, '-')) {
        $shorts[] = ltrim($f, '-');
      }
    }
    if ($long) {
      foreach ($shorts as $s) {
        $this->commands[$this->activeCommand]['aliases'][$s] = $long;
      }
    }

    $this->commands[$this->activeCommand]['options'][] = $spec;
    return $this;
  }

  public function example(string $usage, ?string $desc = null): self
  {
    $this->assertActive('example');
    $this->commands[$this->activeCommand]['examples'][] = [
      'usage' => trim($usage),
      'desc'  => $desc
    ];
    return $this;
  }

  /* ------------------------------ Dispatch ---------------------------- */

  /**
   * Dispatch a command.
   *
   * @param ?string $command   null = from argv
   * @param string[] $arguments empty = from argv
   * @param resource|null $stdout
   * @param resource|null $stderr
   */
  public function dispatch(?string $command = null, array $arguments = [], $stdout = null, $stderr = null): int
  {
    // I/O streams (use provided or keep current)
    $this->stdout = $stdout ?? $this->stdout;
    $this->stderr = $stderr ?? $this->stderr;

    // argv/bin/command
    if ($command === null) {
      $argv = $_SERVER['argv'] ?? [];
      $this->binaryName = basename($argv[0] ?? 'console');
      $command          = $argv[1] ?? 'help';
      $arguments        = array_slice($argv, 2);
    } else {
      $this->binaryName = basename($_SERVER['argv'][0] ?? 'console');
    }

    // Default empty command to 'help'
    if ($command === '') {
      $command = 'help';
    }

    $this->currentCommand = $command;
    $this->rawArguments   = array_values($arguments);

    // TTY auto-config: interactive → colors; non-interactive → timestamps
    $this->interactive = $this->isTty($this->stdout);
    $this->colorsEnabled ??= $this->interactive;
    $this->timestampsEnabled ??= !$this->interactive;

    // Parse options (sade/mri-like)
    $this->parsedOptions = $this->parse($command, $this->rawArguments);

    // Build middleware pipeline + execute
    $stack = $this->stack($command);
    $stack[] = function (?Throwable $error, callable $next): int {
      if ($error !== null) {
        throw $error;
      }

      if (!isset($this->commands[$this->currentCommand])) {
        $handler = $this->notFoundHandler ?? ($this->defaultNotFound());
        return (int)($handler() ?? 1);
      }

      $handler = $this->commands[$this->currentCommand]['handler'];

      try {
        $ret = $handler();

        return match (true) {
          is_int($ret)      => $ret,
          $ret === null     => 0,
          $ret === false    => 1,
          $ret === true     => 0,
          is_string($ret)   => ($this->line($ret) || true) ? 0 : 0,
          default           => 0,
        };
      } catch (Throwable $e) {
        $handler = $this->exceptionHandler ?? ($this->defaultException());
        return (int)($handler($e) ?? 1);
      }
    };

    return (int)$this->run($stack);
  }

  /* --------------------------- Router hooks --------------------------- */

  /**
   * Return true when a middleware prefix matches the current command.
   * Supports:
   *   - '*' or ''  → always
   *   - 'job:'     → prefix match (colon-terminated)
   *   - 'job:*'    → wildcard suffix
   *   - exact name → strict match
   */
  protected function matches(string $registered, string $current): bool
  {
    if ($registered === '' || $registered === '*') return true;
    if (str_ends_with($registered, '*')) {
      $prefix = substr($registered, 0, -1);
      return str_starts_with($current, $prefix);
    }
    if (str_ends_with($registered, ':')) {
      return str_starts_with($current, $registered);
    }
    return $registered === $current;
  }

  /**
   * Normalize a route key (command name). For CLI, trim only.
   */
  protected function normalize(string $key): string
  {
    return trim($key);
  }

  /**
   * Default "not found" handler if none was provided to Router.
   * Returns a callable(): int
   */
  protected function defaultNotFound(): callable
  {
    return function (): int {
      $this->error("Command not found: {$this->currentCommand}");
      if (!empty($this->commands)) {
        $this->line("Use: {$this->binaryName} help");
      }
      return 1;
    };
  }

  /**
   * Default exception handler if none was provided to Router.
   * Returns a callable(Throwable): int
   */
  protected function defaultException(): callable
  {
    return function (Throwable $e): int {
      $this->error('Unhandled exception', ['exception' => $e]);
      return 1;
    };
  }

  /* --------------------------- State accessors ------------------------ */

  public function bin(): string
  {
    return $this->binaryName;
  }
  public function arguments(): array
  {
    return $this->rawArguments;
  }
  public function options(): array
  {
    return $this->parsedOptions;
  }

  /* -------------------------- Environment control --------------------- */

  public function timestamps(bool $enable = true): self
  {
    $this->timestampsEnabled = $enable;
    return $this;
  }

  public function colors(bool $enable = true): self
  {
    $this->colorsEnabled = $enable;
    return $this;
  }
  public function isInteractive(): bool
  {
    return $this->interactive;
  }

  /* ------------------------------ Output ------------------------------ */

  /** Neutral line (replacement for old `log()`), goes to stdout - no timestamps, no labels */
  public function line(string $message = ''): void
  {
    // line() is truly neutral - write directly without timestamps or labels
    fwrite($this->stdout, $message . PHP_EOL);
  }

  /** Semantic success (non-PSR level) */
  public function success(string $message, array $context = []): void
  {
    $this->emit('success', $message, $context, $this->stdout);
  }

  // PSR‑3 level methods
  public function info($message, array $context = []): void
  {
    $this->emit(LogLevel::INFO, (string)$message, $context, $this->stdout);
  }

  public function notice($message, array $context = []): void
  {
    $this->emit(LogLevel::NOTICE, (string)$message, $context, $this->stdout);
  }

  public function debug($message, array $context = []): void
  {
    $this->emit(LogLevel::DEBUG, (string)$message, $context, $this->stdout);
  }

  public function warning($message, array $context = []): void
  {
    $this->emit(LogLevel::WARNING, (string)$message, $context, $this->stderr);
  }

  public function error($message, array $context = []): void
  {
    $this->emit(LogLevel::ERROR, (string)$message, $context, $this->stderr);
  }

  public function critical($message, array $context = []): void
  {
    $this->emit(LogLevel::CRITICAL, (string)$message, $context, $this->stderr);
  }

  public function alert($message, array $context = []): void
  {
    $this->emit(LogLevel::ALERT, (string)$message, $context, $this->stderr);
  }

  public function emergency($message, array $context = []): void
  {
    $this->emit(LogLevel::EMERGENCY, (string)$message, $context, $this->stderr);
  }

  /**
   * PSR‑3 generic entry point.
   * - Validates $level (throws PSR InvalidArgument on unknown levels).
   * - Performs {placeholder} interpolation with $context.
   * - Prints exception details when context['exception'] is Throwable.
   */
  public function log($level, $message, array $context = []): void
  {
    $level = is_string($level) ? strtolower($level) : $level;

    $stdoutLevels = [LogLevel::DEBUG, LogLevel::INFO, LogLevel::NOTICE];
    $stderrLevels = [LogLevel::WARNING, LogLevel::ERROR, LogLevel::CRITICAL, LogLevel::ALERT, LogLevel::EMERGENCY];

    if (!in_array($level, [...$stdoutLevels, ...$stderrLevels], true)) {
      // Per PSR‑3, unknown level must trigger an InvalidArgumentException.
      // Spec reference: php-fig PSR‑3.
      throw new class("Unknown log level: {$level}") extends \InvalidArgumentException implements PsrInvalidArgument {};
    }

    $stream = in_array($level, $stdoutLevels, true) ? $this->stdout : $this->stderr;
    $this->emit($level, (string)$message, $context, $stream);
  }

  /** Write N blank lines to stdout */
  public function blank(int $lines = 1): void
  {
    $lines = max(0, $lines);
    if ($lines === 0) return;
    fwrite($this->stdout, str_repeat(PHP_EOL, $lines));
  }

  /** Low-level write to a specific stream (no prefix decoration) */
  public function write(string $message, $stream = null): void
  {
    $stream ??= $this->stdout;
    fwrite($stream, $message);
  }

  /* -------------------------- Fluent styles --------------------------- */

  /**
   * Magic method to handle all ANSI style methods dynamically.
   * Supports: bold, dim, italic, underline, inverse, hidden, strikethrough,
   *          black, red, green, yellow, blue, magenta, cyan, white, gray,
   *          bgBlack, bgRed, bgGreen, bgYellow, bgBlue, bgMagenta, bgCyan, bgWhite
   */
  public function __call(string $name, array $args)
  {
    // Check if it's a valid ANSI style
    if (isset(self::ANSI[$name])) {
      return $this->style($name, $args[0] ?? '');
    }
    throw new \BadMethodCallException("Method {$name} does not exist on " . self::class);
  }

  private function style(string $code, string $text = '')
  {
    $b = new StyleBuilder($this, [$code]);
    return $text === '' ? $b : $b->text($text);
  }

  /* ------------------------------ Internals --------------------------- */

  private function assertActive(string $method): void
  {
    if ($this->activeCommand === null || !isset($this->commands[$this->activeCommand])) {
      throw new \LogicException("No active command for {$method}(). Call command() first.");
    }
  }

  /**
   * Sade/mri-like argv parser:
   *  - --no-flag      => flag=false
   *  - -abc           => -a, -b=true, -c=(true|value if present)
   *  - --key=val | --key val | -k val
   *  - repeated flags accumulate into arrays
   *  - positional args go into '_'
   *  - defaults derived from option(...) declarations
   */
  private function parse(string $command, array $args): array
  {
    $out = ['_' => []];

    $aliases = $this->commands[$command]['aliases'] ?? [];

    $i = 0;
    $n = count($args);
    $stop = false;
    while ($i < $n) {
      $arg = $args[$i];

      if ($stop) {
        $out['_'][] = $arg;
        $i++;
        continue;
      }
      if ($arg === '--') {
        $stop = true;
        $i++;
        continue;
      }

      // --long / --no-long / --key=value
      if (str_starts_with($arg, '--')) {
        $raw = substr($arg, 2);
        $val = true;
        if (str_starts_with($raw, 'no-')) {
          $key = substr($raw, 3);
          $val = false;
        } elseif (str_contains($raw, '=')) {
          [$key, $val] = explode('=', $raw, 2);
        } else {
          $key = $raw;
          $next = $args[$i + 1] ?? null;
          if ($next !== null && !str_starts_with($next, '-')) {
            $val = $next;
            $i++;
          }
        }
        $key = $aliases[$key] ?? $key;
        $this->assignOption($out, $key, $val);
        $i++;
        continue;
      }

      // -abc / -o value
      if (str_starts_with($arg, '-') && $arg !== '-') {
        $cluster = substr($arg, 1);
        $letters = preg_split('//u', $cluster, -1, PREG_SPLIT_NO_EMPTY);
        $last = array_key_last($letters);
        foreach ($letters as $idx => $s) {
          $key = $aliases[$s] ?? $s;
          $val = true;
          if ($idx === $last) {
            $next = $args[$i + 1] ?? null;
            if ($next !== null && !str_starts_with($next, '-')) {
              $val = $next;
              $i++;
            }
          }
          $this->assignOption($out, $key, $val);
        }
        $i++;
        continue;
      }

      // positional
      $out['_'][] = $arg;
      $i++;
    }

    // Apply defaults from option declarations
    foreach (($this->commands[$command]['options'] ?? []) as $opt) {
      if (!array_key_exists('default', $opt) || $opt['default'] === null) continue;

      $tokens = array_map('trim', explode(',', (string)$opt['flags']));
      $longName = null;
      foreach ($tokens as $t) {
        if (str_starts_with($t, '--')) {
          $longName = ltrim($t, '-');
          break;
        }
      }
      $targetKey = $longName ?? ltrim($tokens[0], '-');

      if (!array_key_exists($targetKey, $out)) {
        $out[$targetKey] = $opt['default'];
      }
    }

    return $out;
  }

  private function assignOption(array &$out, string $key, mixed $val): void
  {
    if (!array_key_exists($key, $out)) {
      $out[$key] = $val;
      return;
    }
    if (!is_array($out[$key])) {
      $out[$key] = [$out[$key]];
    }
    $out[$key][] = $val;
  }

  private function isTty($stream): bool
  {
    if (function_exists('stream_isatty')) {
      return @stream_isatty($stream);
    }
    // Fallback: treat CLI SAPI as interactive
    return PHP_SAPI === 'cli';
  }

  private function emit(?string $level, string $message, array $context, $stream): void
  {
    $text = $this->interpolate($message, $context);

    // Append exception if present (per PSR‑3 context semantics)
    if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
      $e = $context['exception'];
      $text .= sprintf(" | %s: %s\n%s", $e::class, $e->getMessage(), $e->getTraceAsString());
    }

    $prefix = '';
    if ($this->timestampsEnabled) {
      $prefix .= '[' . date('Y-m-d H:i:s') . '] ';
    }

    $label = null;
    if ($level !== null) {
      $label = match ($level) {
        'success'             => 'ok',
        LogLevel::DEBUG       => 'debug',
        LogLevel::INFO        => 'info',
        LogLevel::NOTICE      => 'notice',
        LogLevel::WARNING     => 'warning',
        LogLevel::ERROR       => 'error',
        LogLevel::CRITICAL    => 'critical',
        LogLevel::ALERT       => 'alert',
        LogLevel::EMERGENCY   => 'emergency',
        default               => (string)$level,
      };
      $prefix .= "[{$label}] ";
    }

    $rendered = $this->paint($label ?? 'line', $prefix . $text);
    fwrite($stream, $rendered . PHP_EOL);
  }

  private function paint(string $label, string $text): string
  {
    if (!$this->colorsEnabled) return $text;
    $style = self::LEVEL_STYLE[$label] ?? null;
    if ($style === null) return $text;
    return $this->applyStyles([$style], $text);
  }

  /**
   * Apply a list of style names using ANSI sequences.
   * Combines start codes; ends with a reset.
   */
  public function applyStyles(array $styleNames, string $text): string
  {
    if (!$this->colorsEnabled) return $text;

    $starts = [];
    foreach ($styleNames as $name) {
      if (isset(self::ANSI[$name])) {
        $starts[] = self::ANSI[$name][0]; // start code
      }
    }
    if ($starts === []) return $text;

    $startSeq = "\033[" . implode(';', $starts) . 'm';
    $endSeq   = "\033[" . self::ANSI['reset'][1] . 'm'; // reset "0"
    return $startSeq . $text . $endSeq;
  }

  private function interpolate(string $message, array $context): string
  {
    // PSR‑3 placeholder interpolation: {key} is replaced if value is stringable
    $replace = [];
    foreach ($context as $k => $v) {
      if (!is_array($v) && (!is_object($v) || method_exists($v, '__toString'))) {
        $replace['{' . $k . '}'] = (string)$v;
      }
    }
    return strtr($message, $replace);
  }
}

/* ================================================
 *  StyleBuilder — fluent ANSI builder
 * ================================================ */

namespace Ajo\Core;

final class StyleBuilder
{
  /** @var list<string> */
  private array $stack;
  private Console2 $console;

  public function __construct(Console2 $console, array $stack)
  {
    $this->console = $console;
    $this->stack   = $stack;
  }

  public function __call(string $name, array $args): self
  {
    $this->stack[] = $name;
    return $this;
  }

  /** Returns styled text (no output) */
  public function text(string $text): string
  {
    return $this->console->applyStyles($this->stack, $text);
  }

  /** Prints a line with styles applied (sugar) */
  public function line(string $text = ''): void
  {
    $this->console->line($this->text($text));
  }
}
