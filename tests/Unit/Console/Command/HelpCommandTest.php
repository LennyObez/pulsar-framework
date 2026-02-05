<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Application;
use Pulsar\Console\Command\HelpCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Core\Kernel;

#[CoversClass(HelpCommand::class)]
final class HelpCommandTest extends TestCase
{
    #[Test]
    public function configuredCorrectly(): void
    {
        $app = new Application(new Kernel());
        $command = new HelpCommand($app);

        self::assertSame('help', $command->name);
        self::assertNotEmpty($command->description);
    }

    #[Test]
    public function rendersHelp(): void
    {
        $app = new Application(new Kernel());
        $command = new HelpCommand($app);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('help'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Pulsar', $output->buffer);
        self::assertStringContainsString('Usage:', $output->buffer);
    }
}
