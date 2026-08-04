<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\IntegrityConfig;
use Pulsar\Console\Command\IntegrityBuildCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Integrity\Exception\IntegrityException;
use Pulsar\Integrity\IntegrityManifest;
use Pulsar\Integrity\ManifestBuilderInterface;
use Pulsar\Integrity\ManifestEntry;
use Pulsar\Integrity\ManifestScope;
use Pulsar\Integrity\ManifestSignerInterface;
use SodiumException;

use function bin2hex;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function unlink;

use const DIRECTORY_SEPARATOR;

#[CoversClass(IntegrityBuildCommand::class)]
final class IntegrityBuildCommandTest extends TestCase
{
    private string $tempDir;
    private BufferedOutput $output;

    #[Override]
    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_integrity_build_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o750, true);
        $this->output = new BufferedOutput();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function configuredCorrectly(): void
    {
        $command = $this->createCommand();

        self::assertSame('integrity:build', $command->name);
        self::assertNotEmpty($command->description);
    }

    #[Test]
    public function buildsManifestSuccessfully(): void
    {
        $manifest = new IntegrityManifest(
            version: IntegrityManifest::VERSION,
            algorithm: 'sha256',
            generatedAt: 1700000000,
            frameworkVersion: '1.0.0',
            entryCount: 2,
            entries: [
                new ManifestEntry('src/Foo.php', 'abc123', 100),
                new ManifestEntry('src/Bar.php', 'def456', 200),
            ],
            scope: new ManifestScope(['src/**/*.php'], []),
        );

        $builder = $this->createStub(ManifestBuilderInterface::class);
        $builder->method('build')->willReturn($manifest);

        $command = $this->createCommand(builder: $builder);
        $input = new ArrayInput('integrity:build');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Hashed 2 file(s)', $this->output->buffer);
        self::assertStringContainsString('Manifest written to', $this->output->buffer);
    }

    #[Test]
    public function buildFailureReturnsError(): void
    {
        $builder = $this->createStub(ManifestBuilderInterface::class);
        $builder->method('build')->willThrowException(
            IntegrityException::buildFailed('disk full'),
        );

        $command = $this->createCommand(builder: $builder);
        $input = new ArrayInput('integrity:build');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('Build failed', $this->output->errorBuffer);
    }

    #[Test]
    public function signWithoutSignerFails(): void
    {
        $manifest = $this->createMinimalManifest();

        $builder = $this->createStub(ManifestBuilderInterface::class);
        $builder->method('build')->willReturn($manifest);

        $command = $this->createCommand(builder: $builder, signer: null);
        $input = new ArrayInput('integrity:build', [], ['sign' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('no ManifestSignerInterface', $this->output->errorBuffer);
    }

    #[Test]
    public function signSuccess(): void
    {
        $manifest = $this->createMinimalManifest();

        $builder = $this->createStub(ManifestBuilderInterface::class);
        $builder->method('build')->willReturn($manifest);

        $signer = $this->createStub(ManifestSignerInterface::class);
        $signer->method('sign')->willReturn('hmac_signature_abc');

        $command = $this->createCommand(builder: $builder, signer: $signer);
        $input = new ArrayInput('integrity:build', [], ['sign' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Manifest signed', $this->output->buffer);
        self::assertStringContainsString('Manifest written to', $this->output->buffer);
    }

    #[Test]
    public function signingFailureReturnsError(): void
    {
        $manifest = $this->createMinimalManifest();

        $builder = $this->createStub(ManifestBuilderInterface::class);
        $builder->method('build')->willReturn($manifest);

        $signer = $this->createStub(ManifestSignerInterface::class);
        $signer->method('sign')->willThrowException(new SodiumException('key missing'));

        $command = $this->createCommand(builder: $builder, signer: $signer);
        $input = new ArrayInput('integrity:build', [], ['sign' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('Signing failed', $this->output->errorBuffer);
    }

    private function createCommand(
        ?ManifestBuilderInterface $builder = null,
        ?ManifestSignerInterface $signer = null,
    ): IntegrityBuildCommand {
        return new IntegrityBuildCommand(
            config: new IntegrityConfig(
                enabled: true,
                manifestPath: 'manifest.json',
                include: ['src/'],
                exclude: [],
            ),
            builder: $builder ?? $this->createStub(ManifestBuilderInterface::class),
            basePath: $this->tempDir,
            signer: $signer,
        );
    }

    private function createMinimalManifest(): IntegrityManifest
    {
        return new IntegrityManifest(
            version: IntegrityManifest::VERSION,
            algorithm: 'sha256',
            generatedAt: 1700000000,
            frameworkVersion: '1.0.0',
            entryCount: 1,
            entries: [new ManifestEntry('src/Foo.php', 'abc123', 100)],
            scope: new ManifestScope(['src/**/*.php'], []),
        );
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        /** @var list<string> $items */
        $items = scandir($dir);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
