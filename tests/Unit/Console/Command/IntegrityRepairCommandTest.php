<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\IntegrityConfig;
use Pulsar\Console\Command\IntegrityRepairCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Integrity\Exception\IntegrityException;
use Pulsar\Integrity\IntegrityManifest;
use Pulsar\Integrity\ManifestBuilderInterface;
use Pulsar\Integrity\ManifestScope;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Audit\AuditSinkInterface;

use function bin2hex;
use function file_exists;
use function file_get_contents;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function unlink;

use const DIRECTORY_SEPARATOR;

#[CoversClass(IntegrityRepairCommand::class)]
final class IntegrityRepairCommandTest extends TestCase
{
    private string $tempDir;
    private BufferedOutput $output;

    #[Override]
    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_repair_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o750, true);
        $this->output = new BufferedOutput();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function nameIsIntegrityRepair(): void
    {
        $command = $this->createCommand();

        self::assertSame('integrity:repair', $command->name);
    }

    #[Test]
    public function withoutConfirmShowsSafetyWarning(): void
    {
        $command = $this->createCommand();
        $input = new ArrayInput();
        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Invalid->value, $exit);
        self::assertStringContainsString('--confirm', $this->output->buffer);
        self::assertStringContainsString('regenerates', $this->output->buffer);
    }

    #[Test]
    public function withConfirmBuildsAndWritesManifest(): void
    {
        $manifest = new IntegrityManifest(
            version: IntegrityManifest::VERSION,
            algorithm: 'blake2b',
            generatedAt: 1700000000,
            frameworkVersion: '1.0.0',
            entryCount: 2,
            entries: [],
            scope: new ManifestScope(['src/**/*.php'], []),
        );

        $builder = $this->createStub(ManifestBuilderInterface::class);
        $builder->method('build')->willReturn($manifest);

        $command = $this->createCommand(builder: $builder);
        $input = new ArrayInput(options: ['confirm' => true]);
        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('repaired successfully', $this->output->buffer);

        $writtenPath = $this->tempDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'integrity' . DIRECTORY_SEPARATOR . 'manifest.json';
        self::assertTrue(file_exists($writtenPath));

        $json = file_get_contents($writtenPath);
        self::assertIsString($json);
        self::assertNotEmpty($json);
    }

    #[Test]
    public function buildFailureLogsAuditAndReturnsError(): void
    {
        $builder = $this->createStub(ManifestBuilderInterface::class);
        $builder->method('build')->willThrowException(
            new IntegrityException('Cannot scan files'),
        );

        $command = $this->createCommand(builder: $builder);
        $input = new ArrayInput(options: ['confirm' => true]);
        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('Cannot scan files', $this->output->errorBuffer);
    }

    #[Test]
    public function createsDirectoryIfNotExists(): void
    {
        $manifest = new IntegrityManifest(
            version: IntegrityManifest::VERSION,
            algorithm: 'blake2b',
            generatedAt: 1700000000,
            frameworkVersion: '1.0.0',
            entryCount: 0,
            entries: [],
            scope: new ManifestScope(['src/**/*.php'], []),
        );

        $builder = $this->createStub(ManifestBuilderInterface::class);
        $builder->method('build')->willReturn($manifest);

        $command = $this->createCommand(builder: $builder);
        $input = new ArrayInput(options: ['confirm' => true]);
        $command->execute($input, $this->output);

        $dir = $this->tempDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'integrity';
        self::assertTrue(is_dir($dir));
    }

    private function createCommand(?ManifestBuilderInterface $builder = null): IntegrityRepairCommand
    {
        return new IntegrityRepairCommand(
            config: new IntegrityConfig(),
            builder: $builder ?? $this->createStub(ManifestBuilderInterface::class),
            auditLogger: new AuditLogger(
                $this->createStub(AuditSinkInterface::class),
                bin2hex(random_bytes(32)),
            ),
            basePath: $this->tempDir,
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
