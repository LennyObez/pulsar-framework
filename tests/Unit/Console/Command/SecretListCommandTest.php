<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\SecretListCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Vault\SecretVault;

use function bin2hex;
use function random_bytes;
use function sys_get_temp_dir;

#[CoversClass(SecretListCommand::class)]
final class SecretListCommandTest extends TestCase
{
    #[Test]
    public function configuredWithCorrectName(): void
    {
        $vault = $this->createVault();
        $command = new SecretListCommand($vault);

        self::assertSame('secret:list', $command->name);
        self::assertNotEmpty($command->description);
    }

    #[Test]
    public function emptyVaultShowsInfoMessage(): void
    {
        $vault = $this->createVault();
        $command = new SecretListCommand($vault);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('secret:list'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('empty', $output->buffer);
    }

    #[Test]
    public function listsAllSecretKeysWithoutValues(): void
    {
        $vault = $this->createVault();
        $vault->set('DB_PASSWORD', 'secret1');
        $vault->set('STRIPE_KEY', 'secret2');
        $vault->set('JWT_SECRET', 'secret3');

        $command = new SecretListCommand($vault);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('secret:list'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('DB_PASSWORD', $output->buffer);
        self::assertStringContainsString('STRIPE_KEY', $output->buffer);
        self::assertStringContainsString('JWT_SECRET', $output->buffer);
        self::assertStringContainsString('3 secret(s)', $output->buffer);
        // Values must never appear in output
        self::assertStringNotContainsString('secret1', $output->buffer);
        self::assertStringNotContainsString('secret2', $output->buffer);
        self::assertStringNotContainsString('secret3', $output->buffer);
    }

    private function createVault(): SecretVault
    {
        $key = MasterKey::fromHex(bin2hex(random_bytes(32)));
        $path = sys_get_temp_dir() . '/pulsar_vault_test_' . bin2hex(random_bytes(4)) . '.php';

        return SecretVault::create($key, $path);
    }
}
