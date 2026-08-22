<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\DebugWiringCommand;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Container\Container;

#[CoversClass(DebugWiringCommand::class)]
final class DebugWiringCommandTest extends TestCase
{
    #[Test]
    public function nameAndDescription(): void
    {
        $command = new DebugWiringCommand(new Container());

        self::assertSame('debug:wiring', $command->name);
        self::assertStringContainsString('wiring', $command->description);
    }

    #[Test]
    public function rendersContractsAndSurfacesDegradedOptionalBindings(): void
    {
        // A bare container leaves anti-spam's optional TaggedCacheInterface
        // unbound, so the command must surface it as a degraded feature.
        $command = new DebugWiringCommand(new Container());
        $output = new BufferedOutput();

        $command->execute(new ArrayInput('debug:wiring'), $output);

        // Contract tables are rendered with the bound/unbound column.
        self::assertStringContainsString('TaggedCacheInterface', $output->buffer);
        self::assertStringContainsString('provides', $output->buffer);
        self::assertStringContainsString('optional', $output->buffer);
        // Degraded section names the inert feature.
        self::assertStringContainsString('Degraded features', $output->buffer);
    }
}
