<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Command\Console\Evidence;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Studio\Command\Console\Evidence\RetentionApplyCommand;
use Pulsar\Studio\Console\Retention\RetentionEnforcerInterface;

#[CoversClass(RetentionApplyCommand::class)]
final class RetentionApplyCommandTest extends TestCase
{
    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->output = new BufferedOutput();
    }

    #[Test]
    public function configuredCorrectly(): void
    {
        $enforcer = $this->createStub(RetentionEnforcerInterface::class);
        $command = new RetentionApplyCommand($enforcer);

        self::assertSame('studio:console:evidence:retention:apply', $command->name);
        self::assertSame('Apply retention policy to evidence store', $command->description);
        self::assertArrayHasKey('json', $command->options);
        self::assertSame('j', $command->options['json']['shortcut']);
    }

    #[Test]
    public function executeDisplaysResultAsText(): void
    {
        $enforcer = $this->createStub(RetentionEnforcerInterface::class);
        $enforcer->method('enforce')->willReturn([            'events_deleted' => 42,
            'vacuum_run' => true,
        ]);

        $command = new RetentionApplyCommand($enforcer);
        $input = new ArrayInput('studio:console:evidence:retention:apply');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Retention Policy Applied', $this->output->buffer);
        self::assertStringContainsString('Events deleted: 42', $this->output->buffer);
        self::assertStringContainsString('Vacuum run:     yes', $this->output->buffer);
    }

    #[Test]
    public function executeDisplaysNoVacuumRun(): void
    {
        $enforcer = $this->createStub(RetentionEnforcerInterface::class);
        $enforcer->method('enforce')->willReturn([            'events_deleted' => 0,
            'vacuum_run' => false,
        ]);

        $command = new RetentionApplyCommand($enforcer);
        $input = new ArrayInput('studio:console:evidence:retention:apply');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Events deleted: 0', $this->output->buffer);
        self::assertStringContainsString('Vacuum run:     no', $this->output->buffer);
    }

    #[Test]
    public function executeOutputsJson(): void
    {
        $enforcer = $this->createStub(RetentionEnforcerInterface::class);
        $enforcer->method('enforce')->willReturn([            'events_deleted' => 100,
            'vacuum_run' => true,
        ]);

        $command = new RetentionApplyCommand($enforcer);
        $input = new ArrayInput('studio:console:evidence:retention:apply', [], ['json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array{command: string, success: bool, data: array{events_deleted: int, vacuum_run: bool}} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertSame('studio:console:evidence:retention:apply', $json['command']);
        self::assertTrue($json['success']);
        self::assertSame(100, $json['data']['events_deleted']);
        self::assertTrue($json['data']['vacuum_run']);
    }

    #[Test]
    public function executeOutputsJsonWithZeroDeleted(): void
    {
        $enforcer = $this->createStub(RetentionEnforcerInterface::class);
        $enforcer->method('enforce')->willReturn([            'events_deleted' => 0,
            'vacuum_run' => false,
        ]);

        $command = new RetentionApplyCommand($enforcer);
        $input = new ArrayInput('studio:console:evidence:retention:apply', [], ['json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array{command: string, success: bool, data: array{events_deleted: int, vacuum_run: bool}} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertSame(0, $json['data']['events_deleted']);
        self::assertFalse($json['data']['vacuum_run']);
    }
}
