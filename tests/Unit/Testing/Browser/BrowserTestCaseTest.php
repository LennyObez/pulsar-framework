<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Testing\Browser;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Testing\Browser\BrowserTestCase;

#[CoversClass(BrowserTestCase::class)]
final class BrowserTestCaseTest extends TestCase
{
    #[Test]
    public function defaultBaseUrl(): void
    {
        $instance = new class ('test') extends BrowserTestCase {
            public function exposeBaseUrl(): string
            {
                return $this->baseUrl();
            }
        };

        self::assertSame('http://localhost:8000', $instance->exposeBaseUrl());
    }

    #[Test]
    public function defaultBrowserDriver(): void
    {
        $instance = new class ('test') extends BrowserTestCase {
            public function exposeDriver(): string
            {
                return $this->browserDriver();
            }
        };

        self::assertSame('playwright', $instance->exposeDriver());
    }

    #[Test]
    public function defaultCaptureScreenshotOnFailure(): void
    {
        $instance = new class ('test') extends BrowserTestCase {
            public function exposeCapture(): bool
            {
                return $this->captureScreenshotOnFailure();
            }
        };

        self::assertTrue($instance->exposeCapture());
    }

    #[Test]
    public function defaultScreenshotDirectory(): void
    {
        $instance = new class ('test') extends BrowserTestCase {
            public function exposeDir(): string
            {
                return $this->screenshotDirectory();
            }
        };

        self::assertSame('tests/screenshots', $instance->exposeDir());
    }

    #[Test]
    public function urlCombinesBaseUrlAndPath(): void
    {
        $instance = new class ('test') extends BrowserTestCase {
            public function exposeUrl(string $path): string
            {
                return $this->url($path);
            }
        };

        self::assertSame('http://localhost:8000/login', $instance->exposeUrl('/login'));
    }

    #[Test]
    public function urlHandlesTrailingSlashOnBaseUrl(): void
    {
        $instance = new class ('test') extends BrowserTestCase {
            protected function baseUrl(): string
            {
                return 'http://example.com/';
            }

            public function exposeUrl(string $path): string
            {
                return $this->url($path);
            }
        };

        self::assertSame('http://example.com/dashboard', $instance->exposeUrl('dashboard'));
    }

    #[Test]
    public function urlHandlesLeadingSlashOnPath(): void
    {
        $instance = new class ('test') extends BrowserTestCase {
            public function exposeUrl(string $path): string
            {
                return $this->url($path);
            }
        };

        // Should not double-slash
        $url = $instance->exposeUrl('/api/health');
        self::assertStringNotContainsString('//', substr($url, 7)); // skip "http://"
    }
}
