<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\IntegrityConfig;
use Pulsar\Console\Command\IntegrityVerifyCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Integrity\FileVerificationResult;
use Pulsar\Integrity\FileVerificationStatus;
use Pulsar\Integrity\IntegrityManifest;
use Pulsar\Integrity\ManifestVerifierInterface;
use Pulsar\Integrity\VerificationResult;

use function bin2hex;
use function file_put_contents;
use function is_dir;
use function json_encode;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function unlink;

use const DIRECTORY_SEPARATOR;

#[CoversClass(IntegrityVerifyCommand::class)]
final class IntegrityVerifyCommandTest extends TestCase
{
    private string $tempDir;
    private BufferedOutput $output;

    #[Override]
    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_integrity_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o750, true);
        $this->output = new BufferedOutput();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function nameIsIntegrityVerify(): void
    {
        $command = $this->createCommand();

        self::assertSame('integrity:verify', $command->name);
    }

    #[Test]
    public function missingManifestReturnsError(): void
    {
        $command = $this->createCommand();
        $input = new ArrayInput();
        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('Manifest not found', $this->output->errorBuffer);
    }

    #[Test]
    public function missingManifestJsonMode(): void
    {
        $command = $this->createCommand();
        $input = new ArrayInput(options: ['json' => true]);
        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($this->output->buffer, true);
        self::assertIsArray($decoded);
        self::assertFalse($decoded['success']);
        /** @var array<string, string> $data */
        $data = $decoded['data'];
        self::assertStringContainsString('Manifest not found', $data['error']);
    }

    #[Test]
    public function allVerifiedPassesInTableMode(): void
    {
        $this->writeManifest();

        $result = new VerificationResult(
            passed: true,
            verified: 3,
            modified: 0,
            missing: 0,
            added: 0,
            files: [
                new FileVerificationResult('src/Foo.php', FileVerificationStatus::Verified),
                new FileVerificationResult('src/Bar.php', FileVerificationStatus::Verified),
                new FileVerificationResult('config/app.php', FileVerificationStatus::Verified),
            ],
        );

        $verifier = $this->createStub(ManifestVerifierInterface::class);
        $verifier->method('verify')->willReturn($result);

        $command = $this->createCommand(verifier: $verifier);
        $input = new ArrayInput();
        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('OK', $this->output->buffer);
        self::assertStringContainsString('3 verified', $this->output->buffer);
        self::assertStringContainsString('Integrity verification passed', $this->output->buffer);
    }

    #[Test]
    public function modifiedFilesDetectedWithoutStrict(): void
    {
        $this->writeManifest();

        $result = new VerificationResult(
            passed: false,
            verified: 2,
            modified: 1,
            missing: 0,
            added: 0,
            files: [
                new FileVerificationResult('src/Foo.php', FileVerificationStatus::Verified),
                new FileVerificationResult('src/Bar.php', FileVerificationStatus::Modified, 'abc', 'def'),
                new FileVerificationResult('config/app.php', FileVerificationStatus::Verified),
            ],
        );

        $verifier = $this->createStub(ManifestVerifierInterface::class);
        $verifier->method('verify')->willReturn($result);

        $command = $this->createCommand(verifier: $verifier);
        $input = new ArrayInput();
        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('MODIFIED', $this->output->buffer);
        self::assertStringContainsString('1 modified', $this->output->buffer);
        self::assertStringContainsString('detected changes', $this->output->buffer);
    }

    #[Test]
    public function strictModeFailsOnModifications(): void
    {
        $this->writeManifest();

        $result = new VerificationResult(
            passed: false,
            verified: 1,
            modified: 1,
            missing: 0,
            added: 0,
            files: [
                new FileVerificationResult('src/Foo.php', FileVerificationStatus::Modified),
                new FileVerificationResult('src/Bar.php', FileVerificationStatus::Verified),
            ],
        );

        $verifier = $this->createStub(ManifestVerifierInterface::class);
        $verifier->method('verify')->willReturn($result);

        $command = $this->createCommand(verifier: $verifier);
        $input = new ArrayInput(options: ['strict' => true]);
        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('strict mode', $this->output->errorBuffer);
    }

    #[Test]
    public function jsonModeRendersEnvelopeWithFileDetails(): void
    {
        $this->writeManifest();

        $result = new VerificationResult(
            passed: true,
            verified: 2,
            modified: 0,
            missing: 0,
            added: 0,
            files: [
                new FileVerificationResult('src/Foo.php', FileVerificationStatus::Verified),
                new FileVerificationResult('src/Bar.php', FileVerificationStatus::Verified),
            ],
        );

        $verifier = $this->createStub(ManifestVerifierInterface::class);
        $verifier->method('verify')->willReturn($result);

        $command = $this->createCommand(verifier: $verifier);
        $input = new ArrayInput(options: ['json' => true]);
        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($this->output->buffer, true);
        self::assertIsArray($decoded);
        self::assertSame('integrity:verify', $decoded['command']);
        self::assertTrue($decoded['success']);
        /** @var array<string, mixed> $data */
        $data = $decoded['data'];
        self::assertTrue($data['passed']);
        self::assertSame(2, $data['verified']);
        /** @var list<mixed> $files */
        $files = $data['files'];
        self::assertCount(2, $files);
    }

    #[Test]
    public function jsonModeWithModifiedFilesIncludesHashes(): void
    {
        $this->writeManifest();

        $result = new VerificationResult(
            passed: false,
            verified: 0,
            modified: 1,
            missing: 0,
            added: 0,
            files: [
                new FileVerificationResult('src/Foo.php', FileVerificationStatus::Modified, 'expected_hash', 'actual_hash'),
            ],
        );

        $verifier = $this->createStub(ManifestVerifierInterface::class);
        $verifier->method('verify')->willReturn($result);

        $command = $this->createCommand(verifier: $verifier);
        $input = new ArrayInput(options: ['json' => true]);
        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($this->output->buffer, true);
        self::assertIsArray($decoded);
        /** @var array<string, mixed> $data */
        $data = $decoded['data'];
        /** @var list<array<string, string>> $files */
        $files = $data['files'];
        self::assertSame('expected_hash', $files[0]['expected_hash']);
        self::assertSame('actual_hash', $files[0]['actual_hash']);
    }

    #[Test]
    public function jsonModeStrictFailsOnModifications(): void
    {
        $this->writeManifest();

        $result = new VerificationResult(
            passed: false,
            verified: 0,
            modified: 1,
            missing: 0,
            added: 0,
            files: [
                new FileVerificationResult('src/Foo.php', FileVerificationStatus::Modified),
            ],
        );

        $verifier = $this->createStub(ManifestVerifierInterface::class);
        $verifier->method('verify')->willReturn($result);

        $command = $this->createCommand(verifier: $verifier);
        $input = new ArrayInput(options: ['json' => true, 'strict' => true]);
        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);
    }

    #[Test]
    public function missingAndAddedFilesRendered(): void
    {
        $this->writeManifest();

        $result = new VerificationResult(
            passed: false,
            verified: 0,
            modified: 0,
            missing: 1,
            added: 1,
            files: [
                new FileVerificationResult('src/Deleted.php', FileVerificationStatus::Missing),
                new FileVerificationResult('src/New.php', FileVerificationStatus::Added),
            ],
        );

        $verifier = $this->createStub(ManifestVerifierInterface::class);
        $verifier->method('verify')->willReturn($result);

        $command = $this->createCommand(verifier: $verifier);
        $input = new ArrayInput();
        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('MISSING', $this->output->buffer);
        self::assertStringContainsString('ADDED', $this->output->buffer);
        self::assertStringContainsString('1 missing', $this->output->buffer);
        self::assertStringContainsString('1 added', $this->output->buffer);
    }

    private function createCommand(?ManifestVerifierInterface $verifier = null): IntegrityVerifyCommand
    {
        return new IntegrityVerifyCommand(
            config: new IntegrityConfig(
                manifestPath: 'manifest.json',
            ),
            verifier: $verifier ?? $this->createStub(ManifestVerifierInterface::class),
            basePath: $this->tempDir,
        );
    }

    private function writeManifest(): void
    {
        $content = json_encode([
            'version' => IntegrityManifest::VERSION,
            'algorithm' => 'blake2b',
            'generated_at' => 1700000000,
            'framework_version' => '1.0.0',
            'entry_count' => 0,
            'scope' => ['include' => [], 'exclude' => []],
            'entries' => [],
        ]);

        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'manifest.json', $content);
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
