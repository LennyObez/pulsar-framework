<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\SecretSetCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Vault\SecretVault;

use function bin2hex;
use function random_bytes;
use function sys_get_temp_dir;

#[CoversClass(SecretSetCommand::class)]
final class SecretSetCommandTest extends TestCase
{
    #[Test]
    public function configuredWithCorrectName(): void
    {
        $vault = $this->createVault();
        $command = new SecretSetCommand($vault);

        self::assertSame('secret:set', $command->name);
        self::assertNotEmpty($command->description);
    }

    #[Test]
    public function missingArgumentsReturnsError(): void
    {
        $vault = $this->createVault();
        $command = new SecretSetCommand($vault);
        $output = new BufferedOutput();

        // No arguments at all
        $exit = $command->execute(new ArrayInput('secret:set'), $output);

        self::assertSame(ExitCode::Error->value, $exit);
    }

    #[Test]
    public function missingValueArgumentReturnsError(): void
    {
        $vault = $this->createVault();
        $command = new SecretSetCommand($vault);
        $output = new BufferedOutput();

        // Only one argument (key only, no value)
        $exit = $command->execute(new ArrayInput('secret:set', ['DB_PASSWORD']), $output);

        self::assertSame(ExitCode::Error->value, $exit);
    }

    private function createVault(): SecretVault
    {
        $key = MasterKey::fromHex(bin2hex(random_bytes(32)));
        $path = sys_get_temp_dir() . '/pulsar_vault_test_' . bin2hex(random_bytes(4)) . '.php';

        return SecretVault::create($key, $path);
    }
}
