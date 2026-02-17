<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\SecretGetCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Vault\SecretVault;

use function bin2hex;
use function random_bytes;
use function sys_get_temp_dir;

#[CoversClass(SecretGetCommand::class)]
final class SecretGetCommandTest extends TestCase
{
    #[Test]
    public function configuredWithCorrectName(): void
    {
        $vault = $this->createVault();
        $command = new SecretGetCommand($vault);

        self::assertSame('secret:get', $command->name);
        self::assertNotEmpty($command->description);
    }

    #[Test]
    public function missingKeyArgumentReturnsError(): void
    {
        $vault = $this->createVault();
        $command = new SecretGetCommand($vault);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('secret:get'), $output);

        self::assertSame(ExitCode::Error->value, $exit);
    }

    private function createVault(): SecretVault
    {
        $key = MasterKey::fromHex(bin2hex(random_bytes(32)));
        $path = sys_get_temp_dir() . '/pulsar_vault_test_' . bin2hex(random_bytes(4)) . '.php';

        return SecretVault::create($key, $path);
    }
}
