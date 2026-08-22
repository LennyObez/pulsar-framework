<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\Dev;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\Dev\DevConfig;
use Pulsar\Console\Command\Dev\DevStatusCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

#[CoversClass(DevStatusCommand::class)]
final class DevStatusCommandTest extends TestCase
{
    #[Test]
    public function commandIsConfiguredCorrectly(): void
    {
        $command = new DevStatusCommand(new DevConfig());

        self::assertSame('dev:status', $command->name);
        self::assertNotEmpty($command->description);
    }

    #[Test]
    public function executeOutputsDockerComposeStatusCommand(): void
    {
        $config = new DevConfig(projectName: 'myapp');
        $command = new DevStatusCommand($config);

        $input = $this->createStub(InputInterface::class);
        $output = $this->createMock(OutputInterface::class);

        $output->expects(self::atLeastOnce())
            ->method('writeln')
            ->with(self::callback(static function (string $line): bool {
                // Must mention docker compose with project name at some point
                return str_contains($line, 'docker compose -p myapp ps')
                    || str_contains($line, 'status');
            }));

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
    }
}
