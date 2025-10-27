<?php

declare(strict_types=1);

namespace Ajo\Tests\Integration;

/**
 * Test job handler with static handle() method for FQCN lazy resolution tests.
 */
final class TestJobHandler
{
    public static function handle(array $args): void
    {
        // Store execution proof in global for test assertions
        $GLOBALS['test_job_executed'] = $args;
    }
}

/**
 * Test job handler with instance handle() method for FQCN lazy resolution tests.
 */
final class TestJobHandlerInstance
{
    public function handle(array $args): void
    {
        // Store execution proof in global for test assertions
        $GLOBALS['test_job_executed'] = $args;
    }
}
