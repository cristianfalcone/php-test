<?php

declare(strict_types=1);

namespace Ajo\Core;

use PDO;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * Job v2.5 — single class, single table, rows-per-run (JobSpec plano + draft).
 *
 * Conceptos:
 * - Job (memoria): definición por nombre (o FQCN) con task (opcional), defaults y cron.
 * - Run (DB row): una ejecución concreta (run_at + args + estado de claim/lease).
 *
 * Propiedades:
 * - Claim no bloqueante: FOR UPDATE SKIP LOCKED. [MySQL 8+]
 * - Concurrencia por job: named locks (GET_LOCK), sin tablas extra.
 * - Cron 5/6 campos (parser propio), helpers legibles.
 * - Filtros: when()/skip(). Hooks: before() → then() → catch() → finally().
 * - Prioridad; retries con full-jitter (cap); at()/delay(); idempotencia cron; graceful shutdown; prune.
 */
final class Job2
{
  private const BATCH_LIMIT = 32; // rows per poll

  private PDO $pdo;
  private Clock2 $clock;
  private bool $running = false;
  private string $table;

  /** Definiciones de Job - key = nombre del job (string o FQCN, o draft key) */
  private array $jobs = [];

  /** Nombre activo (para chaining de definición) */
  private ?string $active = null;

  /** Clave de draft (no se crea el spec hasta que haga falta) */
  private string $draft;

  /** Último run id insertado (para at()/delay() post-dispatch) */
  private ?int $last = null;

  public function __construct(PDO $pdo, ?Clock2 $clock = null, string $table = 'jobs')
  {
    $this->pdo   = $pdo;
    $this->clock = $clock ?? new SystemClock2;
    $this->table = $table;
    $this->draft = uniqid();
  }

  // Schema -------------------------------------------------------------------

  /** Crea la tabla si no existe. */
  public function install(): void
  {
    $table = $this->table;
    $this->pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS `{$table}` (

  id                        BIGINT AUTO_INCREMENT PRIMARY KEY,
  name                      VARCHAR(191) NOT NULL,
  queue                     VARCHAR(64) NOT NULL DEFAULT 'default',
  priority                  INT NOT NULL DEFAULT 100,
  run_at                    DATETIME(6) NOT NULL,
  locked_until              DATETIME(6) NULL,
  attempts                  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  args                      JSON NULL,
  unique_key                VARCHAR(191) NULL,

  UNIQUE KEY uq_cron_unique (unique_key),
  KEY idx_due               (run_at, priority, id),
  KEY idx_name_due          (name, run_at, id)

) ENGINE=InnoDB
SQL);
  }

  // Core helpers -------------------------------------------------------------

  /**
   * Devuelve (por referencia) el JobSpec activo; si no existe, crea el draft en caliente.
   * Nota: job() permite task=null (si el nombre luego es un FQCN, se resuelve en ejecución).
   */
  private function &active(): array
  {
    if ($this->active === null) $this->active = $this->draft;
    if (!isset($this->jobs[$this->active])) $this->jobs[$this->active] = $this->job();
    return $this->jobs[$this->active];
  }

  /** Renombra key de $jobs de forma segura y actualiza $active. */
  private function rename(string $old, string $new): void
  {
    if ($old === $new) return;
    if (isset($this->jobs[$new])) throw new RuntimeException("Job '$new' already defined");
    $this->jobs[$new] = $this->jobs[$old];
    unset($this->jobs[$old]);
    $this->active = $new;
  }

  /** Actualiza pares clave/valor en el JobSpec activo. */
  private function assign(array $kv): self
  {
    $target = &$this->active();
    $target = array_replace($target, $kv);
    return $this;
  }

  /** Acumula hooks/filters en orden sobre el JobSpec activo. */
  private function push(string $key, callable $fn): self
  {
    $spec         = &$this->active();
    $spec[$key]   = $spec[$key] ?? [];
    $spec[$key][] = $fn;
    return $this;
  }

  // Definición / Terminadores ------------------------------------------------

  /**
   * schedule(name|FQCN, ?task): fija/renombra el JobSpec (no toca DB).
   * - Si task es null y name es FQCN → se resuelve en ejecución (Class::handle()).
   * - Si task es null y name no es FQCN → error (necesitamos callable).
   */
  public function schedule(string $name, ?callable $task = null): self
  {
    $name = trim($name);
    if ($name === '') throw new RuntimeException('Missing job name or FQCN.');

    if ($task === null && !class_exists($name)) {
      throw new RuntimeException("Unknown class '$name'. Provide a callable or a valid FQCN with handle().");
    }

    // Establecer/renombrar nombre activo
    if ($this->active === null) {
      $this->active = $name;
      $this->jobs[$name] = $this->job($task);
    } elseif ($this->active === $this->draft) {
      $spec = &$this->active();
      if ($task !== null) $spec['task'] = $task;
      $this->rename($this->draft, $name);
    } else {
      // Ya había un activo con nombre; cambiamos foco o actualizamos task
      if (!isset($this->jobs[$name])) $this->jobs[$name] = $this->job($task);
      $this->active = $name;
      if ($task !== null) $this->jobs[$name]['task'] = $task;
    }

    return $this;
  }

  /**
   * dispatch(?name|FQCN): materializa un Run (fila en DB).
   * - name/FQCN explícito: crea o selecciona el JobSpec (FQCN sin task se resuelve al ejecutar).
   * - name omitido: requiere que $active no sea draft (i.e., schedule() previo).
   * - run_at = spec.runAt ?? now(); args = spec.args; queue/priority = defaults del Job.
   * - Guarda $last para permitir at()/delay() post-dispatch (UPDATE de esa fila) y limpia runAt.
   */
  public function dispatch(?string $name = null): self
  {
    if ($name !== null) {

      $name = trim($name);

      if ($name === '') throw new RuntimeException('Missing job name or FQCN.');

      if (!isset($this->jobs[$name])) {
        // Si es FQCN válido, permitimos task=null (resuelve en ejecución)
        if (class_exists($name)) {
          // Si active es draft, renombrarlo al FQCN (preserva args y otros settings)
          if ($this->active === $this->draft && isset($this->jobs[$this->draft])) {
            $this->rename($this->draft, $name);
          } else {
            $this->jobs[$name] = $this->job(); // task=null
          }
        } else {
          throw new RuntimeException("Unknown job '$name'. Define it first with schedule() or use a valid FQCN.");
        }
      }
      $this->active = $name;
    } else {
      // Sin nombre explícito: no permitir despachar con nombre draft
      if ($this->active === null || $this->active === $this->draft) {
        throw new RuntimeException('No job selected. Pass name/FQCN or call schedule() first.');
      }
    }

    $spec  = $this->jobs[$this->active];
    $runAt = $spec['runAt'] ?? $this->clock->now();

    $stmt = $this->pdo->prepare(
      "INSERT INTO `{$this->table}` (name,queue,priority,run_at,locked_until,attempts,args,unique_key)
       VALUES (:name,:queue,:priority,:run_at,NULL,0,:args,NULL)"
    );

    $stmt->execute([
      ':name'     => $this->active,
      ':queue'    => $spec['queue'],
      ':priority' => $spec['priority'],
      ':run_at'   => $this->ts($runAt),
      ':args'     => json_encode($spec['args'] ?? [], JSON_UNESCAPED_UNICODE),
    ]);

    $this->last = (int)$this->pdo->lastInsertId();

    // Limpiar staging del próximo run
    $this->jobs[$this->active]['runAt'] = null;

    return $this;
  }

  // Modificadores ------------------------------------------------------------

  /** Args (defaults del Job y a la vez args del próximo dispatch). */
  public function args(array $args): self
  {
    return $this->assign(['args' => $args]);
  }

  /** Modifica 'runAt' en el JobSpec o UPDATE post-dispatch */
  public function at(DateTimeImmutable|string $when): self
  {
    $when = is_string($when) ? new DateTimeImmutable($when) : $when;

    // Post-dispatch: actualizar la última fila si no está reclamada
    if ($this->last !== null) {
      $stmt = $this->pdo->prepare(
        "UPDATE `{$this->table}`
            SET run_at=:ts
          WHERE id=:id
            AND (locked_until IS NULL OR locked_until <= NOW(6))"
      );
      $stmt->execute([':ts' => $this->ts($when), ':id' => $this->last]);

      if ($stmt->rowCount() !== 1) {
        throw new RuntimeException("Cannot update dispatched job #{$this->last}; it may have already run or been claimed.");
      }

      return $this;
    }

    // Pre-dispatch: staging en el JobSpec
    return $this->assign(['runAt' => $when]);
  }

  /** Alias semántico de at(now + seconds). */
  public function delay(int $seconds): self
  {
    return $this->at($this->clock->now()->modify('+' . max(0, $seconds) . ' seconds'));
  }

  /** Modifica la cola (defaults del Job). */
  public function queue(string $queue): self
  {
    return $this->assign(['queue' => $queue]);
  }

  /** Modifica la prioridad (defaults del Job). */
  public function priority(int $n): self
  {
    return $this->assign(['priority' => $n]);
  }

  /** Modifica el lease (segundos; job-level). */
  public function lease(int $seconds): self
  {
    return $this->assign(['lease' => max(1, $seconds)]);
  }

  /** Modifica la concurrencia (slots; job-level). */
  public function concurrency(int $n): self
  {
    return $this->assign(['concurrency' => max(1, $n)]);
  }

  /** Configura reintentos con backoff exponencial + jitter (full|none) y cap. */
  public function retries(int $max, int $base = 1, int $cap = 60, string $jitter = 'full'): self
  {
    return $this->assign([
      'maxAttempts' => max(1, $max),
      'backoffBase' => $base,
      'backoffCap'  => $cap,
      'jitter'      => $jitter
    ]);
  }

  // Cron: 5 o 6 campos, helpers de frecuencia y limitadores ------------------

  /** Define expresión cron (5 o 6 campos). */
  public function cron(string $expression): self
  {
    $expression = trim($expression);
    $tokens     = preg_split('/\s+/', $expression) ?: [];

    if (count($tokens) === 5) $expression = '0 ' . $expression; // normalizar a 6 (con segundos)

    return $this->assign([
      'cron'   => $expression,
      'parsed' => Cron2::parse($expression),
    ]);
  }

  /** Helpers legibles + edición granular (seconds/minutes/hours/days/months). */
  public function __call(string $name, array $args): self
  {
    static $map = [
      'everySecond'         => '* * * * * *',
      'everyTwoSeconds'     => '*/2 * * * * *',
      'everyFiveSeconds'    => '*/5 * * * * *',
      'everyTenSeconds'     => '*/10 * * * * *',
      'everyFifteenSeconds' => '*/15 * * * * *',
      'everyTwentySeconds'  => '*/20 * * * * *',
      'everyThirtySeconds'  => '*/30 * * * * *',
      'everyMinute'         => '0 * * * * *',
      'everyTwoMinutes'     => '0 */2 * * * *',
      'everyThreeMinutes'   => '0 */3 * * * *',
      'everyFourMinutes'    => '0 */4 * * * *',
      'everyFiveMinutes'    => '0 */5 * * * *',
      'everyTenMinutes'     => '0 */10 * * * *',
      'everyFifteenMinutes' => '0 */15 * * * *',
      'everyThirtyMinutes'  => '0 */30 * * * *',
      'hourly'              => '0 0 * * * *',
      'everyTwoHours'       => '0 0 */2 * * *',
      'everyThreeHours'     => '0 0 */3 * * *',
      'everyFourHours'      => '0 0 */4 * * *',
      'everySixHours'       => '0 0 */6 * * *',
      'daily'               => '0 0 0 * * *',
      'weekly'              => '0 0 0 * * 0',
      'monthly'             => '0 0 0 1 * *',
      'quarterly'           => '0 0 0 1 */3 *',
      'yearly'              => '0 0 0 1 1 *',
      'weekdays'            => '0 0 0 * * 1-5',
      'weekends'            => '0 0 0 * * 0,6',
      'sundays'             => '0 0 0 * * 0',
      'mondays'             => '0 0 0 * * 1',
      'tuesdays'            => '0 0 0 * * 2',
      'wednesdays'          => '0 0 0 * * 3',
      'thursdays'           => '0 0 0 * * 4',
      'fridays'             => '0 0 0 * * 5',
      'saturdays'           => '0 0 0 * * 6',
    ];

    if (isset($map[$name])) return $this->cron($map[$name]);

    $idx = match ($name) {
      'seconds' => 0,
      'minutes' => 1,
      'hours' => 2,
      'days' => 3,
      'months' => 4,
      default => null
    };

    if ($idx !== null) {
      $value          = $args[0] ?? '*';
      $spec           = &$this->active();
      $parts          = explode(' ', $spec['cron'] ??= '0 0 0 * * *');
      $parts[$idx]    = is_array($value) ? implode(',', $value) : (string)$value;
      $spec['cron']   = implode(' ', $parts);
      $spec['parsed'] = Cron2::parse($spec['cron']);
      return $this;
    }

    throw new RuntimeException("Unknown helper $name");
  }

  // Filtros y hooks ----------------------------------------------------------

  public function when(callable $fn): self
  {
    return $this->push('when',    $fn);
  }

  public function skip(callable $fn): self
  {
    return $this->push('skip',    $fn);
  }

  public function before(callable $fn): self
  {
    return $this->push('before',  $fn);
  }

  public function then(callable $fn): self
  {
    return $this->push('then',    $fn);
  }
  public function catch(callable $fn): self
  {
    return $this->push('catch',   $fn);
  }

  public function finally(callable $fn): self
  {
    return $this->push('finally', $fn);
  }

  // Ejecución ----------------------------------------------------------------

  /** Ejecuta jobs pendientes una vez (útil p/test o cron externo). */
  public function run(int $batch = self::BATCH_LIMIT)
  {
    $this->enqueue(); // idempotente entre servidores
    $runs = $this->batch($batch); // transacción corta (claim)
    foreach ($runs as $run) $this->execute($run);
    return count($runs);
  }

  /** Loop infinito con graceful shutdown. */
  public function forever(int $batch = self::BATCH_LIMIT, int $sleep = 200)
  {
    $this->running = true;
    $restore = $this->trapSignals();

    try {
      while ($this->running) {
        $runs = $this->run($batch);
        if ($runs < $batch) usleep(max(1, $sleep) * 1000);
      }
    } finally {
      $this->running = false;
      $restore();
    }
  }

  /** Señala al loop infinito para que termine tras la iteración actual. */
  public function stop(): void
  {
    $this->running = false;
  }

  /** Limpia runs colgados con lease viejo (crash). */
  public function prune(int $olderThan = 86400): int
  {
    $stmt = $this->pdo->prepare(
      "DELETE FROM `{$this->table}`
        WHERE locked_until IS NOT NULL
          AND locked_until < DATE_SUB(NOW(6), INTERVAL :s SECOND)"
    );
    $stmt->execute([':s' => $olderThan]);
    return $stmt->rowCount();
  }

  // Internals ----------------------------------------------------------------

  /** Encola ocurrencias cron (ahora si corresponde y la siguiente) con defaults args del Job; idempotente por unique_key. */
  private function enqueue(): void
  {
    $now  = $this->clock->now();
    $stmt = $this->pdo->prepare(
      "INSERT IGNORE INTO `{$this->table}` (name,queue,priority,run_at,attempts,args,unique_key)
       VALUES (:name,:queue,:priority,:run_at,0,:args,:uk)"
    );

    foreach ($this->jobs as $name => $job) {

      if (!$job['cron']) continue;

      $exec = fn(DateTimeImmutable $when) => $stmt->execute([
        ':name'     => $name,
        ':queue'    => $job['queue'],
        ':priority' => $job['priority'],
        ':run_at'   => $when->format('Y-m-d H:i:s') . '.000000',
        ':args'     => json_encode($job['args'] ?? [], JSON_UNESCAPED_UNICODE),
        ':uk'       => $this->cronKeyOf($name, $when),
      ]);

      $cron = $job['parsed'] ?? Cron2::parse($job['cron']);

      if (Cron2::matches($cron, $now)) $exec($now);

      $exec(Cron2::nextMatch($cron, $now));
    }
  }

  /**
   * Claim de batch con SKIP LOCKED; aplica sólo a jobs conocidos en este worker.
   * Usa NOW(6) (reloj de MySQL) por performance y consistencia entre procesos.
   */
  private function batch(int $limit): array
  {
    $names = array_keys($this->jobs);

    if (!$names) return [];

    $in = implode(',', array_fill(0, count($names), '?'));
    $table = $this->table;

    $this->pdo->exec("START TRANSACTION");

    $stmt = $this->pdo->prepare(
      "SELECT id,name,queue,priority,run_at,args
         FROM `{$table}`
        WHERE name IN ($in)
          AND run_at <= NOW(6)
          AND (locked_until IS NULL OR locked_until <= NOW(6))
        ORDER BY priority ASC, run_at ASC, id ASC
        LIMIT $limit
        FOR UPDATE SKIP LOCKED"
    ); // patrón de cola SQL: non-blocking claim (SKIP LOCKED)

    $stmt->execute($names);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $runs = [];

    foreach ($rows as $row) {

      $job = $this->jobs[$row['name']];

      // Concurrencia por job (semáforo global por slots).
      $lock = $this->acquire("job:{$row['name']}", (int)$job['concurrency']);
      if ($lock === null) continue;
      $locks = [$lock];

      // Lease corto
      $lease = $this->ts($this->clock->now()->modify("+{$job['lease']} seconds"));

      $stmt2 = $this->pdo->prepare("UPDATE `{$table}` SET locked_until=:u, attempts=attempts+1 WHERE id=:id");
      $stmt2->execute([':u' => $lease, ':id' => $row['id']]);

      $runs[] = [
        'id'    => (int)$row['id'],
        'name'  => $row['name'],
        'args'  => $row['args'] ? json_decode($row['args'], true) : [],
        'locks' => $locks,
      ];
    }

    $this->pdo->exec("COMMIT");

    return $runs;
  }

  /** Toma un slot [0..N-1] con GET_LOCK(key:slot, 0). Devuelve key del lock, cadena vacía si sin límite, o null si falla. */
  private function acquire(string $base, int $N): ?string
  {
    if ($N <= 0) return ''; // Sin límite de concurrencia

    $free = $this->pdo->prepare("SELECT IS_FREE_LOCK(:k)");
    $lock = $this->pdo->prepare("SELECT GET_LOCK(:k, 0)");

    for ($i = 0; $i < $N; $i++) {

      $k = "$base:$i";

      $free->execute([':k' => $k]);
      if ((int)$free->fetchColumn() !== 1) continue;

      $lock->execute([':k' => $k]);
      if ((int)$lock->fetchColumn() === 1) {
        return $k;
      }
    }

    return null;
  }

  /** Libera locks por clave (si no es cadena vacía). */
  private function release(array $locks): void
  {
    $stmt = $this->pdo->prepare("SELECT RELEASE_LOCK(:k)");
    foreach ($locks as $k) if ($k !== '') $stmt->execute([':k' => $k]);
  }

  /** Ejecuta un run con filtros y hooks; borra la fila en éxito; reprograma con backoff en error. */
  private function execute(array $run): void
  {
    $job  = $this->jobs[$run['name']];
    $args = is_array($run['args']) ? $run['args'] : [];

    // todas las when() true => continuar.
    foreach ($job['when'] as $fn) if (!$fn($args)) {
      $this->release($run['locks']);
      return;
    }

    // alguna skip() true => omitido.
    foreach ($job['skip'] as $fn) if ($fn($args)) {
      $this->release($run['locks']);
      return;
    }

    $table = $this->table;

    try {

      foreach ($job['before'] as $fn) $fn($args);

      // Resolver y ejecutar la task:
      // 1) Callable explícito del JobSpec
      // 2) FQCN::handle($args) estático o de instancia, si el nombre del job es un FQCN

      if (isset($job['task']) && is_callable($job['task'])) {
        ($job['task'])($args);
      } elseif (class_exists($run['name'])) {

        $class = $run['name'];

        if (is_callable([$class, 'handle'])) {
          $class::handle($args);
        } else {

          $obj = new $class();

          if (!is_callable([$obj, 'handle'])) {
            throw new RuntimeException("Class $class must define handle()");
          }

          $obj->handle($args);
        }

      } else {
        throw new RuntimeException("No handler for job '{$run['name']}'");
      }

      foreach ($job['then'] as $fn) $fn($args);

      $stmt = $this->pdo->prepare("DELETE FROM `{$table}` WHERE id=:id");
      $stmt->execute([':id' => $run['id']]); // éxito => borrar fila
    } catch (Throwable $e) {

      foreach ($job['catch'] as $fn) $fn($e, $args);

      // attempts ya fue +1 en claim → leer para decidir retry
      $stmt = $this->pdo->prepare("SELECT attempts FROM `{$table}` WHERE id=:id");
      $stmt->execute([':id' => $run['id']]);
      $attempts = (int)$stmt->fetchColumn();

      if ($attempts < $job['maxAttempts']) {
        $eta  = $this->ts($this->clock->now()->modify('+' . $this->backoffDelay($job, $attempts) . ' seconds'));
        $stmt = $this->pdo->prepare("UPDATE `{$this->table}` SET run_at=:eta, locked_until=NULL WHERE id=:id");
        $stmt->execute([':eta' => $eta, ':id' => $run['id']]);
      } else {
        $stmt = $this->pdo->prepare("DELETE FROM `{$this->table}` WHERE id=:id");
        $stmt->execute([':id' => $run['id']]);
      }
    } finally {
      foreach ($job['finally'] as $fn) $fn($args);
      $this->release($run['locks']);
    }
  }

  /** Full Jitter (exponencial con cap) u opción 'none' (cap directo). */
  private function backoffDelay(array $d, int $attempts): int
  {
    $max = min($d['backoffCap'], $d['backoffBase'] * (1 << max(0, $attempts - 1)));
    return ($d['jitter'] ?? 'full') === 'none' ? max(1, $max) : random_int(0, max(1, $max));
  }

  /** Crea definición base de Job (task opcional; runAt null por defecto). */
  private function job(?callable $task = null): array
  {
    return [
      'task'        => $task,            // puede ser null si el nombre es FQCN (lazy resolve en ejecución)
      'queue'       => 'default',
      'priority'    => 100,
      'lease'       => 60,
      'concurrency' => 1,
      'maxAttempts' => 1,
      'backoffBase' => 1,
      'backoffCap'  => 60,
      'jitter'      => 'full',
      'when'        => [],
      'skip'        => [],
      'before'      => [],
      'then'        => [],
      'catch'       => [],
      'finally'     => [],
      'cron'        => null,
      'parsed'      => null,
      'args'        => [],              // defaults para cron y para próximos dispatch()
      'runAt'       => null,            // staging de run (mapea 1:1 a run_at en DB)
    ];
  }

  /** Formatea DateTimeImmutable a string para DB. */
  private function ts(DateTimeImmutable $at): string
  {
    return $at->format('Y-m-d H:i:s.u');
  }

  /** Idempotencia cron por segundo (similar a “name|YYYY-mm-dd HH:ii:ss”). */
  private function cronKeyOf(string $name, DateTimeImmutable $t): string
  {
    return $name . '|' . $t->format('Y-m-d H:i:s');
  }

  /** Señales para apagado limpio. */
  private function trapSignals(): callable
  {
    if (!function_exists('pcntl_signal')) return static fn() => null;

    $prev = [];

    foreach (array_filter([defined('SIGINT') ? SIGINT : null, defined('SIGTERM') ? SIGTERM : null]) as $signal) {

      $prev[$signal] = function_exists('pcntl_signal_get_handler')
        ? pcntl_signal_get_handler($signal)
        : SIG_DFL;

      pcntl_signal($signal, fn() => $this->running = false);
    }

    return fn() => array_map(
      fn($signal) => pcntl_signal($signal, $prev[$signal] ?? SIG_DFL),
      array_keys($prev)
    );
  }
}

// Cron (5/6 campos). ---------------------------------------------------------

final class Cron2
{
  public static function parse(string $expression): array
  {
    $tokens = preg_split('/\s+/', trim($expression)) ?: [];

    if (count($tokens) !== 6) throw new RuntimeException("Invalid cron: $expression");

    [$sec, $min, $hour, $dom, $mon, $dow] = $tokens;

    return [
      'sec'     => self::expand($sec, 0, 59, false),
      'min'     => self::expand($min, 0, 59, false),
      'hour'    => self::expand($hour, 0, 23, false),
      'dom'     => self::expand($dom, 1, 31, false),
      'mon'     => self::expand($mon, 1, 12, false),
      'dow'     => self::expand($dow, 0, 6, true),
      'domStar' => $dom === '*',
      'dowStar' => $dow === '*',
    ];
  }

  /** Expande "*", "a-b", "* /n", "a,b,c" a un set ordenado. */
  private static function expand(string $field, int $min, int $max, bool $isDow): array
  {
    $field = trim($field);

    if ($field === '') $field = '*';

    $seen  = [];
    $clamp = fn(int $v) => $isDow && $v === 7 ? 0 : max($min, min($max, $v));

    foreach (explode(',', $field) as $part) {

      $part = trim($part);

      if ($part === '') continue;

      [$range, $step] = str_contains($part, '/') ? explode('/', $part, 2) : [$part, '1'];
      $step = max(1, (int)$step);

      if ($range === '*') {
        for ($value = $min; $value <= $max; $value += $step) $seen[$value] = true;
      } elseif (str_contains($range, '-')) {

        [$start, $end] = array_map('intval', explode('-', $range, 2));
        [$start, $end] = [$clamp($start), $clamp($end)];

        if ($start > $end) [$start, $end] = [$end, $start];
        for ($value = $start; $value <= $end; $value += $step) $seen[$value] = true;
      } else {
        $seen[$clamp((int)$range)] = true;
      }
    }

    $values = $seen !== [] ? array_keys($seen) : range($min, $max);

    sort($values, SORT_NUMERIC);

    return ['map' => array_fill_keys($values, true), 'list' => $values];
  }

  /** Coincide con “momento actual”. Regla DOM vs DOW compatible con cron clásico. */
  public static function matches(array $cron, DateTimeImmutable $timestamp): bool
  {
    $S = (int)$timestamp->format('s');
    $M = (int)$timestamp->format('i');
    $H = (int)$timestamp->format('G');
    $D = (int)$timestamp->format('j');
    $O = (int)$timestamp->format('n');
    $W = (int)$timestamp->format('w');

    if (!isset($cron['sec']['map'][$S], $cron['min']['map'][$M], $cron['hour']['map'][$H], $cron['mon']['map'][$O])) {
      return false;
    }

    $dm = isset($cron['dom']['map'][$D]);
    $wm = isset($cron['dow']['map'][$W]);

    return $cron['domStar'] && $cron['dowStar']
      ? true
      : ($cron['domStar'] ? $wm : ($cron['dowStar'] ? $dm : ($dm || $wm)));
  }

  /** Busca la próxima coincidencia (incremental; sets precomputados → rápido). */
  public static function nextMatch(array $cron, DateTimeImmutable $from): DateTimeImmutable
  {
    $current = $from->modify('+1 second');
    $limit   = (int)$current->format('Y') + 5;

    for ($i = 0; $i < 100000; $i++) {
      if ((int)$current->format('Y') > $limit) throw new RuntimeException('nextMatch overflow');
      if (self::matches($cron, $current)) return $current;
      $current = $current->modify('+1 second');
    }

    throw new RuntimeException('nextMatch iterations overflow');
  }
}

// Clock (inyectable para tests). ---------------------------------------------

interface Clock2
{
  public function now(): DateTimeImmutable;
}

final class SystemClock2 implements Clock2
{
  public function now(): DateTimeImmutable
  {
    return new DateTimeImmutable();
  }
}
