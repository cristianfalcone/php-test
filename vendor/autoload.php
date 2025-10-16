<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$configPath = $root . '/composer.json';
$config = json_decode((string) @file_get_contents($configPath), true);

if (!is_array($config)) {
    throw new RuntimeException("Unable to read composer.json at {$configPath}");
}

$psr4 = [];

foreach (['autoload', 'autoload-dev'] as $section) {
    foreach ((array) ($config[$section]['psr-4'] ?? []) as $prefix => $paths) {
        foreach ((array) $paths as $path) {
            $path = $root . '/' . trim($path, '/\\');
            $psr4[$prefix][] = $path;
        }
    }
}

uksort($psr4, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

spl_autoload_register(
    static function (string $class) use ($psr4): bool {
        foreach ($psr4 as $prefix => $dirs) {

            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            $relativePath = substr($class, strlen($prefix));
            $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relativePath) . '.php';

            foreach ($dirs as $dir) {
                $file = $dir . DIRECTORY_SEPARATOR . $relativePath;
                if (is_file($file)) {
                    require $file;

                    return true;
                }
            }
        }

        return false;
    }
);

return [
    'psr-4' => $psr4,
    'root' => $root,
];
