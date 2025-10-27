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
    $cli = \Ajo\Console::create();

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
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #333;
            line-height: 1.6;
            min-height: 100vh;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        header {
            text-align: center;
            padding: 4rem 0 3rem;
            color: white;
        }

        h1 {
            font-size: 3.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            letter-spacing: -0.02em;
        }

        .subtitle {
            font-size: 1.25rem;
            opacity: 0.95;
            font-weight: 300;
        }

        .badge {
            display: inline-block;
            background: rgba(255, 255, 255, 0.2);
            padding: 0.5rem 1rem;
            border-radius: 2rem;
            font-size: 0.875rem;
            margin-top: 1rem;
            backdrop-filter: blur(10px);
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin: 3rem 0;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            padding: 2rem;
            border-radius: 1rem;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #667eea;
        }

        .stat-label {
            color: #666;
            font-size: 0.875rem;
            margin-top: 0.5rem;
        }

        .features {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin: 3rem 0;
        }

        .feature-card {
            background: white;
            padding: 2rem;
            border-radius: 1rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .feature-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
        }

        .feature-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }

        .feature-name {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #333;
        }

        .feature-desc {
            color: #666;
            font-size: 0.95rem;
        }

        .cta {
            text-align: center;
            margin: 4rem 0 2rem;
        }

        .cta-button {
            display: inline-block;
            background: white;
            color: #667eea;
            padding: 1rem 2.5rem;
            border-radius: 0.5rem;
            text-decoration: none;
            font-weight: 600;
            font-size: 1.1rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .cta-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.2);
        }

        .code-example {
            background: rgba(0, 0, 0, 0.8);
            color: #f8f8f2;
            padding: 2rem;
            border-radius: 1rem;
            margin: 3rem 0;
            font-family: 'Monaco', 'Courier New', monospace;
            font-size: 0.9rem;
            overflow-x: auto;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }

        .code-example pre {
            margin: 0;
        }

        footer {
            text-align: center;
            padding: 2rem 0;
            color: rgba(255, 255, 255, 0.8);
            font-size: 0.9rem;
        }

        @media (max-width: 768px) {
            h1 {
                font-size: 2.5rem;
            }

            .subtitle {
                font-size: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>Ajo</h1>
            <p class="subtitle">Zero-Dependency PHP Micro-Framework</p>
            <span class="badge">Pure PHP 8.4 • No Dependencies • Production Ready</span>
        </header>

        <div class="stats" id="stats">
            <div class="stat-card">
                <div class="stat-value">~2,500</div>
                <div class="stat-label">Lines of Code</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">0</div>
                <div class="stat-label">Dependencies</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">8.4</div>
                <div class="stat-label">PHP Version</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">&gt;90%</div>
                <div class="stat-label">Test Coverage</div>
            </div>
        </div>

        <div class="code-example">
            <pre><code>// Quick Start Example
use Ajo\Http;

Http::get('/', fn() => ['message' => 'Hello, World!']);
Http::post('/users', fn() => ['created' => true]);

// With middleware
Http::use('/api', fn($next) =>
    Container::has('auth') ? $next() : ['error' => 'unauthorized', 'status' => 401]
);

Http::dispatch();</code></pre>
        </div>

        <div class="features" id="features">
            <!-- Features will be loaded dynamically -->
        </div>

        <div class="cta">
            <a href="https://github.com/yourusername/ajo" class="cta-button">View on GitHub</a>
        </div>

        <footer>
            <p>Built with simplicity, elegance, and modern PHP</p>
        </footer>
    </div>

    <script>
        // Load features dynamically
        fetch('/api/features')
            .then(res => res.json())
            .then(data => {
                const container = document.getElementById('features');
                container.innerHTML = data.features.map(feature => `
                    <div class="feature-card">
                        <div class="feature-icon">${feature.icon}</div>
                        <div class="feature-name">${feature.name}</div>
                        <div class="feature-desc">${feature.description}</div>
                    </div>
                `).join('');
            });
    </script>
</body>
</html>
HTML;
}
