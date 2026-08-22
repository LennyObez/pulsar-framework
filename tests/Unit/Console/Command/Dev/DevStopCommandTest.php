<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\Dev;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\Dev\DevConfig;
use Pulsar\Console\Command\Dev\DevStopCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

#[CoversClass(DevStopCommand::class)]
final class DevStopCommandTest extends TestCase
{
    #[Test]
    public function commandIsConfiguredCorrectly(): void
    {
        $command = new DevStopCommand(new DevConfig());

        self::assertSame('dev:stop', $command->name);
        self::assertNotEmpty($command->description);
    }

    #[Test]
    public function executeOutputsDockerComposeDownCommand(): void
    {
        $config = new DevConfig(projectName: 'myapp');
        $command = new DevStopCommand($config);

        $input = $this->createStub(InputInterface::class);
        $output = $this->createMock(OutputInterface::class);

        $output->expects(self::atLeastOnce())
            ->method('writeln')
            ->with(self::callback(static function (string $line): bool {
                return str_contains($line, 'docker compose -p myapp down')
                    || str_contains($line, 'stop');
            }));

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
    }
}
