<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Server\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Config\StudioCollectorConfig;
use Pulsar\Extension\Studio\Config\StudioConfig;
use Pulsar\Extension\Studio\Config\StudioRetentionConfig;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Extension\Studio\Server\Controller\LandingController;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\ResponseStatus;

#[CoversClass(LandingController::class)]
final class LandingControllerTest extends TestCase
{
    private function createRequest(): ServerRequest
    {
        return new ServerRequest(
            method: 'GET',
            uri: '/studio',
        );
    }

    private function createConfig(
        float $samplingRate = 1.0,
        int $maxAgeDays = 7,
        int $maxSizeMb = 500,
        string $storagePath = 'storage/studio/studio.sqlite',
        ?StudioCollectorConfig $collectors = null,
    ): StudioConfig {
        return new StudioConfig(
            enabled: true,
            storagePath: $storagePath,
            retention: new StudioRetentionConfig(
                maxAgeDays: $maxAgeDays,
                maxSizeMb: $maxSizeMb,
            ),
            collectors: $collectors ?? new StudioCollectorConfig(),
            samplingRate: $samplingRate,
        );
    }

    #[Test]
    public function handleReturnsHtmlResponseWithDefaultStore(): void
    {
        $config = $this->createConfig();
        $controller = new LandingController($config);

        $response = $controller->handle($this->createRequest());

        $body = (string) $response->getBody();
        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('<!DOCTYPE html>', $body);
        self::assertStringContainsString('Pulsar Studio', $body);
    }

    #[Test]
    public function handleDisplaysEventCountFromStore(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('count')->willReturn(42);
        $store->method('sizeInBytes')->willReturn(1024);

        $config = $this->createConfig();
        $controller = new LandingController($config, $store);

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('42', (string) $response->getBody());
    }

    #[Test]
    public function handleDisplaysStorageSizeInBytes(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('count')->willReturn(0);
        $store->method('sizeInBytes')->willReturn(512);

        $config = $this->createConfig();
        $controller = new LandingController($config, $store);

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('512 B', (string) $response->getBody());
    }

    #[Test]
    public function handleDisplaysStorageSizeInKilobytes(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('count')->willReturn(0);
        $store->method('sizeInBytes')->willReturn(2048);

        $config = $this->createConfig();
        $controller = new LandingController($config, $store);

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('2.0 KB', (string) $response->getBody());
    }

    #[Test]
    public function handleDisplaysStorageSizeInMegabytes(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('count')->willReturn(0);
        $store->method('sizeInBytes')->willReturn(2097152);

        $config = $this->createConfig();
        $controller = new LandingController($config, $store);

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('2.0 MB', (string) $response->getBody());
    }

    #[Test]
    public function handleFormatsLargeEventCountsInThousands(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('count')->willReturn(1500);
        $store->method('sizeInBytes')->willReturn(0);

        $config = $this->createConfig();
        $controller = new LandingController($config, $store);

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('1.5K', (string) $response->getBody());
    }

    #[Test]
    public function handleFormatsLargeEventCountsInMillions(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('count')->willReturn(1500000);
        $store->method('sizeInBytes')->willReturn(0);

        $config = $this->createConfig();
        $controller = new LandingController($config, $store);

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('1.5M', (string) $response->getBody());
    }

    #[Test]
    public function handleDisplaysSamplingRateAsPercentage(): void
    {
        $config = $this->createConfig(samplingRate: 0.5);
        $controller = new LandingController($config);

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('50%', (string) $response->getBody());
    }

    #[Test]
    public function handleDisplaysRetentionDays(): void
    {
        $config = $this->createConfig(maxAgeDays: 14);
        $controller = new LandingController($config);

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('14d', (string) $response->getBody());
    }

    #[Test]
    public function handleDisplaysMaxSizeMb(): void
    {
        $config = $this->createConfig(maxSizeMb: 1000);
        $controller = new LandingController($config);

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('1000 MB', (string) $response->getBody());
    }

    #[Test]
    public function handleDisplaysStoragePath(): void
    {
        $config = $this->createConfig(storagePath: '/custom/path/studio.db');
        $controller = new LandingController($config);

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('/custom/path/studio.db', (string) $response->getBody());
    }

    #[Test]
    public function handleEscapesStoragePathForHtml(): void
    {
        $config = $this->createConfig(storagePath: 'path/<script>alert("xss")</script>.db');
        $controller = new LandingController($config);

        $response = $controller->handle($this->createRequest());

        $body = (string) $response->getBody();
        self::assertStringNotContainsString('<script>alert("xss")</script>', $body);
        self::assertStringContainsString('&lt;script&gt;', $body);
    }

    #[Test]
    public function handleDisplaysEnabledCollectorBadges(): void
    {
        $collectors = new StudioCollectorConfig(
            http: true,
            database: true,
            logs: true,
            exceptions: true,
            scheduler: true,
            featureFlags: true,
        );
        $config = $this->createConfig(collectors: $collectors);
        $controller = new LandingController($config);

        $response = $controller->handle($this->createRequest());

        $body = (string) $response->getBody();
        self::assertStringContainsString('class="collector-badge"', $body);
        self::assertStringContainsString('>HTTP</span>', $body);
        self::assertStringContainsString('>Database</span>', $body);
        self::assertStringContainsString('>Logs</span>', $body);
        self::assertStringContainsString('>Exceptions</span>', $body);
        self::assertStringContainsString('>Scheduler</span>', $body);
        self::assertStringContainsString('>Flags</span>', $body);
    }

    #[Test]
    public function handleDisplaysDisabledCollectorBadges(): void
    {
        $collectors = new StudioCollectorConfig(
            http: false,
            database: false,
            logs: false,
            exceptions: false,
            scheduler: false,
            featureFlags: false,
        );
        $config = $this->createConfig(collectors: $collectors);
        $controller = new LandingController($config);

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('class="collector-badge disabled"', (string) $response->getBody());
    }

    #[Test]
    public function handleIncludesNavigationLinks(): void
    {
        $config = $this->createConfig();
        $controller = new LandingController($config);

        $response = $controller->handle($this->createRequest());

        $body = (string) $response->getBody();
        self::assertStringContainsString('href="/studio/console"', $body);
        self::assertStringContainsString('href="/studio/console/requests"', $body);
        self::assertStringContainsString('href="/studio/console/database"', $body);
        self::assertStringContainsString('href="/studio/console/logs"', $body);
        self::assertStringContainsString('href="/studio/console/exceptions"', $body);
    }

    #[Test]
    public function handleIncludesStylesheetLink(): void
    {
        $config = $this->createConfig();
        $controller = new LandingController($config);

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('href="/studio/assets/studio.css"', (string) $response->getBody());
    }

    #[Test]
    public function handleWithNullStoreDisplaysZeroValues(): void
    {
        $config = $this->createConfig();
        $controller = new LandingController($config, null);

        $response = $controller->handle($this->createRequest());

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertStringContainsString('0', (string) $response->getBody());
    }
}
