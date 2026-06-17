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
use Pulsar\SupplyChain\Command\VerifyCommand;

#[CoversClass(VerifyCommand::class)]
final class VerifyCommandTest extends TestCase
{
    #[Test]
    public function configurationSetsNameAndDescription(): void
    {
        $command = new VerifyCommand('/tmp/project');

        self::assertSame('supply-chain:verify', $command->name);
        self::assertSame('Verify release artifact Ed25519 signatures', $command->description);
    }

    #[Test]
    public function configurationRegistersExpectedOptions(): void
    {
        $command = new VerifyCommand('/tmp/project');

        self::assertArrayHasKey('key-file', $command->options);
        self::assertArrayHasKey('dir', $command->options);
        self::assertArrayHasKey('manifest', $command->options);
    }

    #[Test]
    public function keyFileOptionHasShortcut(): void
    {
        $command = new VerifyCommand('/tmp/project');

        self::assertSame('k', $command->options['key-file']['shortcut']);
    }

    #[Test]
    public function manifestOptionHasShortcut(): void
    {
        $command = new VerifyCommand('/tmp/project');

        self::assertSame('m', $command->options['manifest']['shortcut']);
    }

    #[Test]
    public function executeReturnsErrorWhenKeyFileNotProvided(): void
    {
        $command = new VerifyCommand('/tmp/project');

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
        $command = new VerifyCommand('/tmp/project');

        $input = $this->createStub(InputInterface::class);
        $input->method('hasOption')->willReturnMap([
            ['key-file', true],
            ['dir', false],
            ['manifest', false],
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
        $command = new VerifyCommand('/tmp/project');
        $nonExistentKey = '/tmp/no-key-' . bin2hex(random_bytes(4)) . '.pub';

        $input = $this->createStub(InputInterface::class);
        $input->method('hasOption')->willReturnMap([
            ['key-file', true],
            ['dir', false],
            ['manifest', false],
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
        $tempDir = sys_get_temp_dir() . '/pulsar-verify-badkey-' . bin2hex(random_bytes(4));
        mkdir($tempDir, 0o777, true);

        $keyFile = $tempDir . '/bad-key.pub';
        file_put_contents($keyFile, random_bytes(16)); // 16 bytes instead of 32

        $command = new VerifyCommand($tempDir);

        $input = $this->createStub(InputInterface::class);
        $input->method('hasOption')->willReturnMap([
            ['key-file', true],
            ['dir', false],
            ['manifest', false],
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
    public function executeReturnsErrorWhenManifestFileDoesNotExist(): void
    {
        $tempDir = sys_get_temp_dir() . '/pulsar-verify-nomanifest-' . bin2hex(random_bytes(4));
        mkdir($tempDir, 0o777, true);

        $keypair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keypair);

        $keyFile = $tempDir . '/public.key';
        file_put_contents($keyFile, $publicKey);

        $command = new VerifyCommand($tempDir);

        $input = $this->createStub(InputInterface::class);
        $input->method('hasOption')->willReturnMap([
            ['key-file', true],
            ['dir', false],
            ['manifest', false],
        ]);
        $input->method('getOption')->willReturnMap([
            ['key-file', null, $keyFile],
        ]);

        $output = $this->createStub(OutputInterface::class);

        $exitCode = $command->execute($input, $output);

        unlink($keyFile);
        rmdir($tempDir);

        // No manifest file at default path -> error reading manifest
        self::assertSame(ExitCode::Error->value, $exitCode);
    }

    #[Test]
    public function executeVerifiesSignedArtifactsSuccessfully(): void
    {
        $tempDir = sys_get_temp_dir() . '/pulsar-verify-full-' . bin2hex(random_bytes(4));
        $distDir = $tempDir . '/dist';
        mkdir($distDir, 0o777, true);

        // Create a dummy artifact
        $artifactContent = 'fake phar content for verification';
        file_put_contents($distDir . '/app.phar', $artifactContent);

        // Generate Ed25519 keypair and sign the artifact
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        $signature = sodium_crypto_sign_detached($artifactContent, $secretKey);
        $signatureB64 = base64_encode($signature);
        $publicKeyB64 = base64_encode($publicKey);

        // Write manifest
        $manifest = json_encode([
            'signatures' => [
                [
                    'artifact_path' => 'app.phar',
                    'signature' => $signatureB64,
                    'public_key' => $publicKeyB64,
                    'timestamp' => date('c'),
                    'algorithm' => 'ed25519',
                ],
            ],
            'schema_version' => 1,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

        $manifestPath = $tempDir . '/signatures.json';
        file_put_contents($manifestPath, $manifest);

        // Write public key
        $keyFile = $tempDir . '/public.key';
        file_put_contents($keyFile, $publicKey);

        $command = new VerifyCommand($tempDir);

        $input = new ArrayInput(null, [], [
            'key-file' => $keyFile,
            'dir' => $distDir,
            'manifest' => $manifestPath,
        ]);

        $output = $this->createStub(OutputInterface::class);

        $exitCode = $command->execute($input, $output);

        // Clean up
        unlink($distDir . '/app.phar');
        unlink($manifestPath);
        unlink($keyFile);
        rmdir($distDir);
        rmdir($tempDir);

        self::assertSame(ExitCode::Success->value, $exitCode);
    }

    #[Test]
    public function executeReturnsErrorWhenSignatureIsInvalid(): void
    {
        $tempDir = sys_get_temp_dir() . '/pulsar-verify-invalid-' . bin2hex(random_bytes(4));
        $distDir = $tempDir . '/dist';
        mkdir($distDir, 0o777, true);

        // Create an artifact
        file_put_contents($distDir . '/app.phar', 'original content');

        // Generate keypair
        $keypair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keypair);

        // Write manifest with a bogus signature
        $fakeSignature = base64_encode(random_bytes(64));
        $manifest = json_encode([
            'signatures' => [
                [
                    'artifact_path' => 'app.phar',
                    'signature' => $fakeSignature,
                    'public_key' => base64_encode($publicKey),
                    'timestamp' => date('c'),
                    'algorithm' => 'ed25519',
                ],
            ],
            'schema_version' => 1,
        ], JSON_THROW_ON_ERROR);

        $manifestPath = $tempDir . '/signatures.json';
        file_put_contents($manifestPath, $manifest);

        $keyFile = $tempDir . '/public.key';
        file_put_contents($keyFile, $publicKey);

        $command = new VerifyCommand($tempDir);

        $input = new ArrayInput(null, [], [
            'key-file' => $keyFile,
            'dir' => $distDir,
            'manifest' => $manifestPath,
        ]);

        $output = $this->createStub(OutputInterface::class);

        $exitCode = $command->execute($input, $output);

        // Clean up
        unlink($distDir . '/app.phar');
        unlink($manifestPath);
        unlink($keyFile);
        rmdir($distDir);
        rmdir($tempDir);

        self::assertSame(ExitCode::Error->value, $exitCode);
    }
}
