<?php

declare(strict_types=1);

namespace Pulsar\Testing\Browser;

use PHPUnit\Framework\TestCase;
use Pulsar\Api\Api;

/**
 * Base class for browser testing via Playwright or Symfony Panther.
 *
 * Provides integration points and helpers for browser-based tests without
 * building a custom browser test runner. Uses established tools underneath.
 *
 * Setup:
 * 1. Install Playwright (`npm init playwright`) or Panther (`composer require symfony/panther`)
 * 2. Extend this class and override `browserDriver()` if needed
 * 3. Use helpers: waitForElement(), assertPageContains()
 *
 * Configuration is provided through overridable methods, not constructor injection,
 * to keep setup minimal for typical usage.
 */
#[Api(since: '1.0.0')]
abstract class BrowserTestCase extends TestCase
{
    /**
     * Get the base URL for browser tests.
     *
     * Override this in your test class to set your application's URL.
     */
    protected function baseUrl(): string
    {
        return 'http://localhost:8000';
    }

    /**
     * Get the browser driver type.
     *
     * @return string "playwright" or "panther"
     */
    protected function browserDriver(): string
    {
        return 'playwright';
    }

    /**
     * Whether to capture screenshots on test failure.
     */
    protected function captureScreenshotOnFailure(): bool
    {
        return true;
    }

    /**
     * Directory for storing failure screenshots.
     */
    protected function screenshotDirectory(): string
    {
        return 'tests/screenshots';
    }

    /**
     * Get the full URL for a given path.
     */
    protected function url(string $path): string
    {
        return rtrim($this->baseUrl(), '/') . '/' . ltrim($path, '/');
    }
}
