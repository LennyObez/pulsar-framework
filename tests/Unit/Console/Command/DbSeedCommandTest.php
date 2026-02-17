<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\DbSeedCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Seeder\SeederRunner;

use function bin2hex;
use function random_bytes;
use function sys_get_temp_dir;

#[CoversClass(DbSeedCommand::class)]
final class DbSeedCommandTest extends TestCase
{
    #[Test]
    public function configuredWithCorrectName(): void
    {
        $runner = $this->createRunner();
        $command = new DbSeedCommand($runner);

        self::assertSame('db:seed', $command->name);
        self::assertNotEmpty($command->description);
    }

    #[Test]
    public function noSeedersFoundShowsInfo(): void
    {
        // Empty directory = no seeders discovered
        $runner = $this->createRunner();
        $command = new DbSeedCommand($runner);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('db:seed'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('No seeders found', $output->buffer);
    }

    private function createRunner(): SeederRunner
    {
        $connection = $this->createStub(ConnectionInterface::class);
        // Non-existent directory = discover() returns []
        $seederPath = sys_get_temp_dir() . '/pulsar_seeders_' . bin2hex(random_bytes(4));

        return new SeederRunner($connection, $seederPath);
    }
}
