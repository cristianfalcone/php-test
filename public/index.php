<?php

declare(strict_types=1);

use Ajo\Console;
use Ajo\Container;
use Ajo\Database;
use Ajo\Http;

require __DIR__ . '/../vendor/autoload.php';

// Initialize database
Http::use(function() {
    Console::info('Initializing database connection');
    Container::set('db', Database::get());
    Console::success('Database connected');
});

// API Routes
Http::get('/api/health', fn() => [
    'status' => 'ok',
    'framework' => 'Ajo',
    'version' => '1.0.0',
    'timestamp' => time(),
]);

Http::get('/api/features', fn() => [
    'features' => [
        ['name' => 'Zero Dependencies', 'icon' => '📦', 'description' => 'Pure PHP 8.4, no external packages'],
        ['name' => 'HTTP Router', 'icon' => '🚀', 'description' => 'Fast routing with middleware support'],
        ['name' => 'Job Scheduler', 'icon' => '⏱️', 'description' => 'Cron-like scheduling with queues'],
        ['name' => 'Database Layer', 'icon' => '💾', 'description' => 'Clean query builder & migrations'],
        ['name' => 'CLI Framework', 'icon' => '⌨️', 'description' => 'Rich console commands'],
        ['name' => 'Test Runner', 'icon' => '✓', 'description' => 'BDD-style with parallel execution'],
        ['name' => 'DI Container', 'icon' => '🔧', 'description' => 'Lightweight dependency injection'],
        ['name' => 'Modern PHP', 'icon' => '✨', 'description' => 'Property hooks, enums, readonly classes'],
    ]
]);

Http::get('/api/stats', fn() => [
    'stats' => [
        ['label' => 'Lines of Code', 'value' => '~2,500'],
        ['label' => 'Dependencies', 'value' => '0'],
        ['label' => 'PHP Version', 'value' => '8.4'],
        ['label' => 'Test Coverage', 'value' => '>90%'],
    ]
]);

// Test endpoint to verify logging
Http::get('/api/test-logging', function() {
    file_put_contents('/tmp/test-console.log', ''); // Clear file

    $logFile = fopen('/tmp/test-console.log', 'a');

    // Create Console instance with custom streams
    $cli = Console::instance();

    // Manually set streams before logging
    $reflection = new \ReflectionClass($cli);
    $streamMethod = $reflection->getMethod('stream');
    $streamMethod->setAccessible(true);
    $streamMethod->invoke($cli, 'stdout', $logFile, true);
    $streamMethod->invoke($cli, 'stderr', $logFile, false);

    $cli->success('Test success message');
    $cli->info('Test info message');
    $cli->warn('Test warning message');
    $cli->error('Test error message');

    fclose($logFile);

    $content = file_get_contents('/tmp/test-console.log');

    return [
        'logged' => true,
        'hasTimestamps' => str_contains($content, '2025-'),
        'hasColors' => str_contains($content, "\033["),
        'content' => $content
    ];
});

// Serve landing page for root
Http::get('/', function() {
    header('Content-Type: text/html; charset=utf-8');
    echo renderLanding();
    exit;
});

Http::dispatch();

function renderLanding(): string
{
    return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ajo - Zero-Dependency PHP Micro-Framework</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'IBM Plex Sans', 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
            background: #f7f6f2;
            color: #1f1f1f;
            line-height: 1.7;
            min-height: 100vh;
        }

        .container {
            max-width: 960px;
            margin: 0 auto;
            padding: 0 1.75rem 4rem;
        }

        header {
            padding: 4.5rem 0 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .brand {
            font-size: 0.85rem;
            letter-spacing: 0.28em;
            text-transform: uppercase;
            color: #7a776b;
        }

        h1 {
            font-size: 3rem;
            font-weight: 600;
            letter-spacing: -0.02em;
        }

        .subtitle {
            font-size: 1.125rem;
            color: #4f4d44;
            max-width: 520px;
        }

        .divider {
            width: 64px;
            height: 1px;
            background: #cbc7ba;
        }

        .stats {
            list-style: none;
            display: flex;
            flex-wrap: wrap;
            gap: 2.5rem;
            padding: 2.75rem 0;
            border-top: 1px solid #dcd8cb;
            border-bottom: 1px solid #dcd8cb;
            margin: 2rem 0 0;
        }

        .stats li {
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
        }

        .stat-value {
            font-size: 1.75rem;
            font-weight: 600;
        }

        .stat-label {
            font-size: 0.85rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #7a776b;
        }

        .layout {
            display: grid;
            gap: 3rem;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            align-items: start;
            margin: 3.5rem 0;
        }

        .text-block {
            display: flex;
            flex-direction: column;
            gap: 1.75rem;
        }

        .text-block p {
            font-size: 1rem;
            color: #3f3d34;
        }

        .text-block ul {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 0.8rem;
        }

        .text-block li {
            padding-left: 1.5rem;
            position: relative;
            color: #4f4d44;
        }

        .text-block li::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0.65rem;
            width: 0.5rem;
            height: 0.5rem;
            border-radius: 50%;
            background: #b5b09f;
        }

        .code-example {
            background: #fbfaf6;
            border: 1px solid #dcd8cb;
            border-radius: 16px;
            padding: 1.75rem;
            font-family: 'SFMono-Regular', 'Menlo', 'Monaco', 'Courier New', monospace;
            font-size: 0.92rem;
            color: #2b2b2b;
            overflow-x: auto;
        }

        .code-example pre {
            margin: 0;
            white-space: pre;
        }

        .features {
            display: flex;
            flex-direction: column;
            gap: 2rem;
            margin: 3rem 0 0;
        }

        .feature {
            border-left: 2px solid #d0ccbf;
            padding-left: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .feature h3 {
            font-size: 1.15rem;
            font-weight: 500;
        }

        .feature p {
            color: #4f4d44;
            font-size: 0.98rem;
        }

        .cta {
            margin: 4rem 0 0;
        }

        .cta a {
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1.75rem;
            border-radius: 999px;
            border: 1px solid #c9c4b4;
            color: inherit;
            text-decoration: none;
            font-weight: 500;
            letter-spacing: 0.04em;
            transition: background 0.2s ease, border-color 0.2s ease;
        }

        .cta a:hover {
            background: #efede5;
            border-color: #bdb7a5;
        }

        footer {
            padding: 3rem 0 1.5rem;
            font-size: 0.85rem;
            color: #7a776b;
        }

        @media (max-width: 640px) {
            h1 {
                font-size: 2.4rem;
            }

            .stats {
                gap: 1.75rem;
            }

            .layout {
                gap: 2.5rem;
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div class="brand">Ajo Framework</div>
            <h1>Lean PHP foundations for ambitious teams</h1>
            <div class="divider"></div>
            <p class="subtitle">
                Ajo distills the ideas from large PHP ecosystems into a single, thoughtful micro-framework.
                No dependencies, just modern PHP and careful engineering.
            </p>
        </header>

        <ul class="stats">
            <li>
                <span class="stat-value">~2,500</span>
                <span class="stat-label">Lines of code</span>
            </li>
            <li>
                <span class="stat-value">0</span>
                <span class="stat-label">Dependencies</span>
            </li>
            <li>
                <span class="stat-value">PHP 8.4</span>
                <span class="stat-label">Runtime</span>
            </li>
            <li>
                <span class="stat-value">&gt;90%</span>
                <span class="stat-label">Coverage</span>
            </li>
        </ul>

        <div class="layout">
            <div class="text-block">
                <p>
                    Every subsystem ships in the box: HTTP routing, console tooling, job scheduling,
                    a migration-aware database layer, and a fearless test runner. Everything is crafted
                    for clarity and tuned for the realities of modern teams.
                </p>
                <ul>
                    <li>Zero external packages and no runtime surprises.</li>
                    <li>Facade-first APIs backed by composable core classes.</li>
                    <li>Custom tooling that mirrors the ergonomics of frameworks you already know.</li>
                </ul>
            </div>
            <div class="code-example">
<pre><code>// Quick start
use Ajo\Http;
use Ajo\Container;

Http::use(fn($next) => Container::has('auth') ? $next() : [
    'status' => 401,
    'error' => 'unauthorized',
]);

Http::get('/', fn() => ['message' => 'Welcome to Ajo']);

Http::dispatch();</code></pre>
            </div>
        </div>

        <div class="features">
            <div class="feature">
                <h3>Modern PHP, no excess</h3>
                <p>Property hooks, enums, readonly classes, and first-class callables put to work without third-party baggage.</p>
            </div>
            <div class="feature">
                <h3>Composable console and HTTP layers</h3>
                <p>Shared architecture patterns bridge CLI and web concerns, so tooling, middleware, and dependency management stay familiar.</p>
            </div>
            <div class="feature">
                <h3>Jobs, migrations, and testing built in</h3>
                <p>One command starts the scheduler, migrator, and bespoke test runner. No PHPUnit clone, just concise commands.</p>
            </div>
        </div>

        <div class="cta">
            <a href="https://github.com/yourusername/ajo">Explore the repository</a>
        </div>

        <footer>
            Crafted with restraint and purpose. Ajo keeps the essentials polished and in reach.
        </footer>
    </div>
</body>
</html>
HTML;
}
