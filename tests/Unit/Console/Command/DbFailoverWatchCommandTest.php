<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\DbFailoverWatchCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Database\Failover\FailoverManagerInterface;
use Pulsar\Database\Failover\FailoverStateStore;

#[CoversClass(DbFailoverWatchCommand::class)]
final class DbFailoverWatchCommandTest extends TestCase
{
    #[Test]
    public function healthyPrimaryRecordsNoFailover(): void
    {
        $manager = $this->createStub(FailoverManagerInterface::class);
        $manager->method('checkPrimary')->willReturn(true);
        $manager->method('getCurrentPrimary')->willReturn('10.0.0.1');

        $store = $this->store();
        $command = new DbFailoverWatchCommand(static fn(): FailoverManagerInterface => $manager, $store, 1);

        $exit = $command->execute($this->input(), new BufferedOutput());

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertNull($store->currentEndpoint());
    }

    #[Test]
    public function unhealthyButClosedCircuitDoesNotFailOver(): void
    {
        // Below the failure threshold: degraded but the breaker has not opened.
        $manager = $this->createStub(FailoverManagerInterface::class);
        $manager->method('checkPrimary')->willReturn(false);
        $manager->method('isCircuitOpen')->willReturn(false);
        $manager->method('getCurrentPrimary')->willReturn('10.0.0.1');

        $store = $this->store();
        $command = new DbFailoverWatchCommand(static fn(): FailoverManagerInterface => $manager, $store, 1);

        $command->execute($this->input(), new BufferedOutput());

        self::assertNull($store->currentEndpoint());
    }

    #[Test]
    public function openCircuitExecutesFailoverAndPublishesEndpoint(): void
    {
        $manager = $this->createStub(FailoverManagerInterface::class);
        $manager->method('checkPrimary')->willReturn(false);
        $manager->method('isCircuitOpen')->willReturn(true);
        $manager->method('executeFailover')->willReturn(true);
        $manager->method('getCurrentPrimary')->willReturn('10.0.0.2');

        $store = $this->store();
        $command = new DbFailoverWatchCommand(static fn(): FailoverManagerInterface => $manager, $store, 1);

        $exit = $command->execute($this->input(), new BufferedOutput());

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertSame('10.0.0.2', $store->currentEndpoint());
    }

    private function input(): ArrayInput
    {
        return new ArrayInput('db:failover:watch', [], ['max-ticks' => '1', 'interval' => '1']);
    }

    private function store(): FailoverStateStore
    {
        return new class implements FailoverStateStore {
            private ?string $endpoint = null;

            public function currentEndpoint(): ?string
            {
                return $this->endpoint;
            }

            public function recordFailover(string $endpoint): void
            {
                $this->endpoint = $endpoint;
            }

            public function clear(): void
            {
                $this->endpoint = null;
            }
        };
    }
}
