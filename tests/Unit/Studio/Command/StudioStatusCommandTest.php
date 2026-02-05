<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Extension\Studio\Command\StudioStatusCommand;
use Pulsar\Extension\Studio\Config\StudioCollectorConfig;
use Pulsar\Extension\Studio\Config\StudioConfig;
use Pulsar\Extension\Studio\Config\StudioRetentionConfig;
use Pulsar\Extension\Studio\Config\StudioSecurityConfig;
use Pulsar\Extension\Studio\Config\StudioServerConfig;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(StudioStatusCommand::class)]
final class StudioStatusCommandTest extends TestCase
{
    #[Test]
    public function configuredCorrectly(): void
    {
        $config = $this->createConfig();
        $command = new StudioStatusCommand($config);

        self::assertSame('studio:status', $command->name);
        self::assertSame('Display Studio status and configuration', $command->description);
        self::assertArrayHasKey('json', $command->options);
        self::assertSame('j', $command->options['json']['shortcut']);
    }

    #[Test]
    public function displaysStatusInTextFormat(): void
    {
        $config = $this->createConfig(
            enabled: true,
            storagePath: 'storage/studio/events.db',
            samplingRate: 0.5,
            maxAgeDays: 14,
            maxSizeMb: 1000,
            host: '127.0.0.1',
            port: 8585,
        );

        $command = new StudioStatusCommand($config);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('studio:status'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Pulsar Studio Status', $output->buffer);
        self::assertStringContainsString('Enabled:       Yes', $output->buffer);
        self::assertStringContainsString('storage/studio/events.db', $output->buffer);
        self::assertStringContainsString('50%', $output->buffer);
        self::assertStringContainsString('14 days', $output->buffer);
        self::assertStringContainsString('1000 MB', $output->buffer);
        self::assertStringContainsString('127.0.0.1:8585', $output->buffer);
    }

    #[Test]
    public function displaysDisabledStatusInTextFormat(): void
    {
        $config = $this->createConfig(enabled: false);

        $command = new StudioStatusCommand($config);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('studio:status'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Enabled:       No', $output->buffer);
    }

    #[Test]
    public function displaysCollectorStatusInTextFormat(): void
    {
        $config = $this->createConfig(
            httpCollector: true,
            databaseCollector: false,
            logsCollector: true,
            exceptionsCollector: false,
            schedulerCollector: true,
            featureFlagsCollector: false,
        );

        $command = new StudioStatusCommand($config);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('studio:status'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('[INFO] Collectors', $output->buffer);
        self::assertStringContainsString('HTTP:          Active', $output->buffer);
        self::assertStringContainsString('Database:      Disabled', $output->buffer);
        self::assertStringContainsString('Logs:          Active', $output->buffer);
        self::assertStringContainsString('Exceptions:    Disabled', $output->buffer);
        self::assertStringContainsString('Scheduler:     Active', $output->buffer);
        self::assertStringContainsString('Feature Flags: Disabled', $output->buffer);
    }

    #[Test]
    public function displaysStorageStatsWhenStoreProvided(): void
    {
        $config = $this->createConfig();
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('count')->willReturn(150);
        $store->method('sizeInBytes')->willReturn(524288);

        $command = new StudioStatusCommand($config, $store);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('studio:status'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('[INFO] Storage', $output->buffer);
        self::assertStringContainsString('Events:        150', $output->buffer);
        self::assertStringContainsString('Size:          524288 bytes', $output->buffer);
    }

    #[Test]
    public function outputsJsonFormat(): void
    {
        $config = $this->createConfig(
            enabled: true,
            storagePath: 'storage/studio/events.db',
            samplingRate: 1.0,
            maxAgeDays: 7,
            maxSizeMb: 500,
            host: '127.0.0.1',
            port: 8585,
        );

        $command = new StudioStatusCommand($config);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('studio:status', [], ['json' => true]),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array{enabled: bool, storage_path: string, sampling_rate: float|int, retention: array{max_age_days: int, max_size_mb: int}, server: array{host: string, port: int}, collectors: array<string, bool>, storage: null|array{event_count: int, size_bytes: int}} $json */
        $json = json_decode($output->buffer, true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($json['enabled']);
        self::assertSame('storage/studio/events.db', $json['storage_path']);
        self::assertEquals(1.0, $json['sampling_rate']);
        self::assertSame(7, $json['retention']['max_age_days']);
        self::assertSame(500, $json['retention']['max_size_mb']);
        self::assertSame('127.0.0.1', $json['server']['host']);
        self::assertSame(8585, $json['server']['port']);
        self::assertNull($json['storage']);
    }

    #[Test]
    public function jsonOutputIncludesStorageStats(): void
    {
        $config = $this->createConfig();
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('count')->willReturn(42);
        $store->method('sizeInBytes')->willReturn(1024);

        $command = new StudioStatusCommand($config, $store);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('studio:status', [], ['json' => true]),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array{storage: array{event_count: int, size_bytes: int}} $json */
        $json = json_decode($output->buffer, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($json['storage']);
        self::assertSame(42, $json['storage']['event_count']);
        self::assertSame(1024, $json['storage']['size_bytes']);
    }

    #[Test]
    public function jsonOutputIncludesCollectors(): void
    {
        $config = $this->createConfig(
            httpCollector: true,
            databaseCollector: false,
            logsCollector: true,
            exceptionsCollector: true,
            schedulerCollector: false,
            featureFlagsCollector: true,
        );

        $command = new StudioStatusCommand($config);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('studio:status', [], ['json' => true]),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array{collectors: array{http: bool, database: bool, logs: bool, exceptions: bool, scheduler: bool, feature_flags: bool}} $json */
        $json = json_decode($output->buffer, true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($json['collectors']['http']);
        self::assertFalse($json['collectors']['database']);
        self::assertTrue($json['collectors']['logs']);
        self::assertTrue($json['collectors']['exceptions']);
        self::assertFalse($json['collectors']['scheduler']);
        self::assertTrue($json['collectors']['feature_flags']);
    }

    #[Test]
    public function worksWithoutEventStore(): void
    {
        $config = $this->createConfig();

        $command = new StudioStatusCommand($config, null);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('studio:status'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringNotContainsString('[INFO] Storage', $output->buffer);
    }

    private function createConfig(
        bool $enabled = true,
        string $storagePath = 'storage/studio/studio.sqlite',
        float $samplingRate = 1.0,
        int $maxAgeDays = 7,
        int $maxSizeMb = 500,
        string $host = '127.0.0.1',
        int $port = 8585,
        bool $httpCollector = true,
        bool $databaseCollector = true,
        bool $logsCollector = true,
        bool $exceptionsCollector = true,
        bool $schedulerCollector = true,
        bool $featureFlagsCollector = true,
    ): StudioConfig {
        return new StudioConfig(
            enabled: $enabled,
            storagePath: $storagePath,
            retention: new StudioRetentionConfig(maxAgeDays: $maxAgeDays, maxSizeMb: $maxSizeMb),
            security: new StudioSecurityConfig(),
            server: new StudioServerConfig(host: $host, port: $port),
            collectors: new StudioCollectorConfig(
                http: $httpCollector,
                database: $databaseCollector,
                logs: $logsCollector,
                exceptions: $exceptionsCollector,
                scheduler: $schedulerCollector,
                featureFlags: $featureFlagsCollector,
            ),
            samplingRate: $samplingRate,
        );
    }
}
