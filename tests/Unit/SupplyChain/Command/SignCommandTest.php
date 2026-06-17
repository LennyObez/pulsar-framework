<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\SupplyChain\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\SupplyChain\Command\SignCommand;

#[CoversClass(SignCommand::class)]
final class SignCommandTest extends TestCase
{
    #[Test]
    public function configurationSetsNameAndDescription(): void
    {
        $command = new SignCommand('/tmp/project');

        self::assertSame('supply-chain:sign', $command->name);
        self::assertSame('Sign release artifacts with Ed25519', $command->description);
    }

    #[Test]
    public function configurationRegistersExpectedOptions(): void
    {
        $command = new SignCommand('/tmp/project');

        self::assertArrayHasKey('key-file', $command->options);
        self::assertArrayHasKey('dir', $command->options);
        self::assertArrayHasKey('output', $command->options);
    }

    #[Test]
    public function keyFileOptionHasShortcut(): void
    {
        $command = new SignCommand('/tmp/project');

        self::assertSame('k', $command->options['key-file']['shortcut']);
    }

    #[Test]
    public function outputOptionHasShortcut(): void
    {
        $command = new SignCommand('/tmp/project');

        self::assertSame('o', $command->options['output']['shortcut']);
    }

    #[Test]
    public function executeReturnsErrorWhenKeyFileNotProvided(): void
    {
        $command = new SignCommand('/tmp/project');

        $input = $this->createStub(InputInterface::class);
        $input->method('hasOption')->willReturn(false);
        $input->method('getOption')->willReturn(null);

        $output = $this->createStub(OutputInterface::class);

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $exitCode);
    }

    #[Test]
    public function executeReturnsErrorWhenKeyFileIsEmptyString(): void
    {
        $command = new SignCommand('/tmp/project');

        $input = $this->createStub(InputInterface::class);
        $input->method('hasOption')->willReturnMap([
            ['key-file', true],
            ['dir', false],
            ['output', false],
        ]);
        $input->method('getOption')->willReturnMap([
            ['key-file', null, ''],
        ]);

        $output = $this->createStub(OutputInterface::class);

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $exitCode);
    }

    #[Test]
    public function executeReturnsErrorWhenKeyFileDoesNotExist(): void
    {
        $command = new SignCommand('/tmp/project');
        $nonExistentKey = '/tmp/no-such-key-' . bin2hex(random_bytes(4)) . '.bin';

        $input = $this->createStub(InputInterface::class);
        $input->method('hasOption')->willReturnMap([
            ['key-file', true],
            ['dir', false],
            ['output', false],
        ]);
        $input->method('getOption')->willReturnMap([
            ['key-file', null, $nonExistentKey],
        ]);

        $output = $this->createStub(OutputInterface::class);

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $exitCode);
    }

    #[Test]
    public function executeReturnsErrorWhenKeyFileHasWrongSize(): void
    {
        $tempDir = sys_get_temp_dir() . '/pulsar-sign-badkey-' . bin2hex(random_bytes(4));
        mkdir($tempDir, 0o777, true);

        // Write an invalid key (wrong length)
        $keyFile = $tempDir . '/bad-key.bin';
        file_put_contents($keyFile, random_bytes(16)); // 16 bytes instead of 64

        $command = new SignCommand($tempDir);

        $input = $this->createStub(InputInterface::class);
        $input->method('hasOption')->willReturnMap([
            ['key-file', true],
            ['dir', false],
            ['output', false],
        ]);
        $input->method('getOption')->willReturnMap([
            ['key-file', null, $keyFile],
        ]);

        $output = $this->createStub(OutputInterface::class);

        $exitCode = $command->execute($input, $output);

        unlink($keyFile);
        rmdir($tempDir);

        self::assertSame(ExitCode::Error->value, $exitCode);
    }

    #[Test]
    public function executeReturnsSuccessWithValidKeyAndEmptyArtifactDir(): void
    {
        $tempDir = sys_get_temp_dir() . '/pulsar-sign-empty-' . bin2hex(random_bytes(4));
        $distDir = $tempDir . '/dist';
        mkdir($distDir, 0o777, true);

        // Generate a valid Ed25519 keypair
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);

        $keyFile = $tempDir . '/secret.key';
        file_put_contents($keyFile, $secretKey);

        $command = new SignCommand($tempDir);

        $input = new ArrayInput(null, [], ['key-file' => $keyFile]);

        $output = $this->createStub(OutputInterface::class);

        $exitCode = $command->execute($input, $output);

        // Clean up
        unlink($keyFile);
        rmdir($distDir);
        rmdir($tempDir);

        // No artifacts to sign results in a warning but still success
        self::assertSame(ExitCode::Success->value, $exitCode);
    }

    #[Test]
    public function executeSignsArtifactsAndWritesManifest(): void
    {
        $tempDir = sys_get_temp_dir() . '/pulsar-sign-full-' . bin2hex(random_bytes(4));
        $distDir = $tempDir . '/dist';
        mkdir($distDir, 0o777, true);

        // Create a dummy artifact
        file_put_contents($distDir . '/app.phar', 'fake phar content');

        // Generate a valid Ed25519 keypair
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);

        $keyFile = $tempDir . '/secret.key';
        file_put_contents($keyFile, $secretKey);

        $manifestPath = $tempDir . '/signatures.json';

        $command = new SignCommand($tempDir);

        $input = new ArrayInput(null, [], [
            'key-file' => $keyFile,
            'dir' => $distDir,
            'output' => $manifestPath,
        ]);

        $output = $this->createStub(OutputInterface::class);

        $exitCode = $command->execute($input, $output);

        $manifestExists = file_exists($manifestPath);
        $manifestContent = $manifestExists ? file_get_contents($manifestPath) : '';

        // Clean up
        if ($manifestExists) {
            unlink($manifestPath);
        }
        unlink($keyFile);
        unlink($distDir . '/app.phar');
        rmdir($distDir);
        rmdir($tempDir);

        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertTrue($manifestExists, 'Manifest file should be created');
        self::assertIsString($manifestContent);

        /** @var array{signatures: array<int, mixed>} $decoded */
        $decoded = json_decode($manifestContent, true, 16, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertArrayHasKey('signatures', $decoded);
        self::assertCount(1, $decoded['signatures']);
    }
}
