<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Command\Console\Evidence;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Extension\Studio\Command\Console\Evidence\EvidencePurgeCommand;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;

#[CoversClass(EvidencePurgeCommand::class)]
final class EvidencePurgeCommandTest extends TestCase
{
    /** @var EventStoreInterface&Stub */
    private EventStoreInterface $store;
    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->store = $this->createStub(EventStoreInterface::class);
        $this->output = new BufferedOutput();
    }

    #[Test]
    public function configuredCorrectly(): void
    {
        $command = new EvidencePurgeCommand($this->store);

        self::assertSame('studio:console:evidence:purge', $command->name);
        self::assertSame('Purge all events from evidence store', $command->description);
        self::assertArrayHasKey('confirm', $command->options);
        self::assertArrayHasKey('json', $command->options);
        self::assertSame('c', $command->options['confirm']['shortcut']);
        self::assertSame('j', $command->options['json']['shortcut']);
    }

    #[Test]
    public function executeRequiresConfirmFlag(): void
    {
        $command = new EvidencePurgeCommand($this->store);
        $input = new ArrayInput('studio:console:evidence:purge');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('permanently delete all Studio events', $this->output->buffer);
        self::assertStringContainsString('--confirm', $this->output->buffer);
    }

    #[Test]
    public function executeRequiresConfirmFlagWithJson(): void
    {
        $command = new EvidencePurgeCommand($this->store);
        $input = new ArrayInput('studio:console:evidence:purge', [], ['json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);

        /** @var array{command: string, success: bool, data: array{error: string}} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertSame('studio:console:evidence:purge', $json['command']);
        self::assertFalse($json['success']);
        self::assertStringContainsString('permanently delete', $json['data']['error']);
    }

    #[Test]
    public function executePurgesWithConfirm(): void
    {
        $this->store->method('count')->willReturn(150);

        $store = $this->createMock(EventStoreInterface::class);
        $store->method('count')->willReturn(150);
        $store->expects(self::once())->method('clear');
        $store->expects(self::once())->method('vacuum');

        $command = new EvidencePurgeCommand($store);
        $input = new ArrayInput('studio:console:evidence:purge', [], ['confirm' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Evidence Store Purged', $this->output->buffer);
        self::assertStringContainsString('Removed 150 events', $this->output->buffer);
    }

    #[Test]
    public function executePurgesWithConfirmAndJson(): void
    {
        $store = $this->createMock(EventStoreInterface::class);
        $store->method('count')->willReturn(75);
        $store->expects(self::once())->method('clear');
        $store->expects(self::once())->method('vacuum');

        $command = new EvidencePurgeCommand($store);
        $input = new ArrayInput('studio:console:evidence:purge', [], ['confirm' => true, 'json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array{command: string, success: bool, data: array{events_purged: int}} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertSame('studio:console:evidence:purge', $json['command']);
        self::assertTrue($json['success']);
        self::assertSame(75, $json['data']['events_purged']);
    }

    #[Test]
    public function executePurgesEmptyStore(): void
    {
        $store = $this->createMock(EventStoreInterface::class);
        $store->method('count')->willReturn(0);
        $store->expects(self::once())->method('clear');
        $store->expects(self::once())->method('vacuum');

        $command = new EvidencePurgeCommand($store);
        $input = new ArrayInput('studio:console:evidence:purge', [], ['confirm' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Removed 0 events', $this->output->buffer);
    }
}
