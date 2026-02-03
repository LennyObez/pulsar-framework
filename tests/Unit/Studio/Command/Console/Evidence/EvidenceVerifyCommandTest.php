<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Command\Console\Evidence;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Studio\Command\Console\Evidence\EvidenceVerifyCommand;
use Pulsar\Studio\Console\Evidence\EvidenceArchive;
use Pulsar\Studio\Console\Evidence\EvidenceVerifier;

#[CoversClass(EvidenceVerifyCommand::class)]
final class EvidenceVerifyCommandTest extends TestCase
{
    private BufferedOutput $output;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->output = new BufferedOutput();
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_verify_cmd_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function it_is_configured_correctly(): void
    {
        $verifier = new EvidenceVerifier();
        $command = new EvidenceVerifyCommand($verifier);

        self::assertSame('studio:console:evidence:verify', $command->name);
        self::assertSame('Verify a Studio evidence archive', $command->description);
        self::assertArrayHasKey('mode', $command->options);
        self::assertSame('m', $command->options['mode']['shortcut']);
        self::assertArrayHasKey('json', $command->options);
        self::assertSame('j', $command->options['json']['shortcut']);
        self::assertCount(1, $command->arguments);
    }

    #[Test]
    public function it_returns_error_when_file_not_found_text(): void
    {
        $verifier = new EvidenceVerifier();
        $command = new EvidenceVerifyCommand($verifier);
        $input = new ArrayInput('studio:console:evidence:verify', ['/nonexistent/file.json']);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('Archive file not found', $this->output->errorBuffer);
    }

    #[Test]
    public function it_returns_error_when_file_not_found_json(): void
    {
        $verifier = new EvidenceVerifier();
        $command = new EvidenceVerifyCommand($verifier);
        $input = new ArrayInput('studio:console:evidence:verify', ['/nonexistent/file.json'], ['json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);

        /** @var array{error: string, message: string} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertSame('file_not_found', $json['error']);
    }

    #[Test]
    public function it_returns_error_for_invalid_archive_text(): void
    {
        $archivePath = $this->tempDir . DIRECTORY_SEPARATOR . 'invalid.json';
        file_put_contents($archivePath, '{"not": "valid"}');

        $verifier = new EvidenceVerifier();
        $command = new EvidenceVerifyCommand($verifier);
        $input = new ArrayInput('studio:console:evidence:verify', [$archivePath]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('Invalid archive', $this->output->errorBuffer);
    }

    #[Test]
    public function it_returns_error_for_invalid_archive_json(): void
    {
        $archivePath = $this->tempDir . DIRECTORY_SEPARATOR . 'invalid.json';
        file_put_contents($archivePath, '{"not": "valid"}');

        $verifier = new EvidenceVerifier();
        $command = new EvidenceVerifyCommand($verifier);
        $input = new ArrayInput('studio:console:evidence:verify', [$archivePath], ['json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);

        /** @var array{error: string, message: string} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertSame('invalid_archive', $json['error']);
    }

    #[Test]
    public function it_requires_mac_key_for_tamper_evident_mode(): void
    {
        $archive = new EvidenceArchive(
            events: [],
            chainLinks: [],
            manifest: ['version' => 1],
        );
        $archivePath = $this->tempDir . DIRECTORY_SEPARATOR . 'archive.json';
        file_put_contents($archivePath, $archive->toJson());

        $verifier = new EvidenceVerifier();
        $command = new EvidenceVerifyCommand($verifier, chainMacKey: null);
        $input = new ArrayInput('studio:console:evidence:verify', [$archivePath], ['mode' => 'tamper-evident']);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('chain MAC key', $this->output->errorBuffer);
    }

    #[Test]
    public function it_requires_mac_key_for_full_mode(): void
    {
        $archive = new EvidenceArchive(
            events: [],
            chainLinks: [],
            manifest: ['version' => 1],
        );
        $archivePath = $this->tempDir . DIRECTORY_SEPARATOR . 'archive.json';
        file_put_contents($archivePath, $archive->toJson());

        $verifier = new EvidenceVerifier();
        $command = new EvidenceVerifyCommand($verifier, chainMacKey: null);
        $input = new ArrayInput('studio:console:evidence:verify', [$archivePath], ['mode' => 'full']);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('PULSAR_MASTER_KEY', $this->output->errorBuffer);
    }

    #[Test]
    public function it_requires_mac_key_for_tamper_evident_mode_json(): void
    {
        $archive = new EvidenceArchive(
            events: [],
            chainLinks: [],
            manifest: ['version' => 1],
        );
        $archivePath = $this->tempDir . DIRECTORY_SEPARATOR . 'archive.json';
        file_put_contents($archivePath, $archive->toJson());

        $verifier = new EvidenceVerifier();
        $command = new EvidenceVerifyCommand($verifier, chainMacKey: null);
        $input = new ArrayInput('studio:console:evidence:verify', [$archivePath], ['mode' => 'tamper-evident', 'json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);

        /** @var array{error: string, message: string} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertSame('mac_key_required', $json['error']);
    }

    #[Test]
    public function it_verifies_empty_archive_in_public_mode_text(): void
    {
        $archive = new EvidenceArchive(
            events: [],
            chainLinks: [],
            manifest: ['version' => 1],
        );
        $archivePath = $this->tempDir . DIRECTORY_SEPARATOR . 'archive.json';
        file_put_contents($archivePath, $archive->toJson());

        $verifier = new EvidenceVerifier();
        $command = new EvidenceVerifyCommand($verifier);
        $input = new ArrayInput('studio:console:evidence:verify', [$archivePath]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Evidence Chain Verification', $this->output->buffer);
        self::assertStringContainsString('Chain integrity verified', $this->output->buffer);
    }

    #[Test]
    public function it_verifies_empty_archive_in_public_mode_json(): void
    {
        $archive = new EvidenceArchive(
            events: [],
            chainLinks: [],
            manifest: ['version' => 1],
        );
        $archivePath = $this->tempDir . DIRECTORY_SEPARATOR . 'archive.json';
        file_put_contents($archivePath, $archive->toJson());

        $verifier = new EvidenceVerifier();
        $command = new EvidenceVerifyCommand($verifier);
        $input = new ArrayInput('studio:console:evidence:verify', [$archivePath], ['json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array<string, mixed> $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertTrue($json['chain_intact']);
        self::assertSame('public', $json['verification_mode']);
    }

    #[Test]
    public function it_returns_error_for_malformed_json_file(): void
    {
        $archivePath = $this->tempDir . DIRECTORY_SEPARATOR . 'malformed.json';
        file_put_contents($archivePath, 'this is not json at all');

        $verifier = new EvidenceVerifier();
        $command = new EvidenceVerifyCommand($verifier);
        $input = new ArrayInput('studio:console:evidence:verify', [$archivePath]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('Invalid archive', $this->output->errorBuffer);
    }

    #[Test]
    public function it_displays_pruned_links_when_present(): void
    {
        $archive = new EvidenceArchive(
            events: [],
            chainLinks: [],
            manifest: ['version' => 1],
        );
        $archivePath = $this->tempDir . DIRECTORY_SEPARATOR . 'archive.json';
        file_put_contents($archivePath, $archive->toJson());

        // The real verifier with empty chain returns links_pruned=0, so pruned line won't show
        $verifier = new EvidenceVerifier();
        $command = new EvidenceVerifyCommand($verifier);
        $input = new ArrayInput('studio:console:evidence:verify', [$archivePath]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        // With 0 pruned links, the pruned line should NOT appear
        self::assertStringNotContainsString('Pruned:', $this->output->buffer);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

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
