<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Command\Console\Guardian;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\IntegrityConfig;
use Pulsar\Config\IntegrityPolicyMode;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Extension\Studio\Command\Console\Guardian\GuardianIntegrityVerifyCommand;
use Pulsar\Integrity\IntegrityManifest;
use Pulsar\Integrity\ManifestBuilder;
use Pulsar\Integrity\ManifestFormat;
use Pulsar\Integrity\ManifestVerifier;

#[CoversClass(GuardianIntegrityVerifyCommand::class)]
final class GuardianIntegrityVerifyCommandTest extends TestCase
{
    private BufferedOutput $output;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->output = new BufferedOutput();
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_integrity_verify_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function it_is_configured_correctly(): void
    {
        $verifier = new ManifestVerifier($this->tempDir);
        $config = new IntegrityConfig();
        $command = new GuardianIntegrityVerifyCommand($verifier, $config);

        self::assertSame('studio:console:guardian:integrity:verify', $command->name);
        self::assertSame('Verify integrity manifest against filesystem', $command->description);
        self::assertArrayHasKey('strict', $command->options);
        self::assertSame('s', $command->options['strict']['shortcut']);
        self::assertArrayHasKey('json', $command->options);
        self::assertSame('j', $command->options['json']['shortcut']);
    }

    #[Test]
    public function it_returns_failure_when_manifest_not_found_text(): void
    {
        $verifier = new ManifestVerifier($this->tempDir);
        $config = new IntegrityConfig(manifestPath: $this->tempDir . DIRECTORY_SEPARATOR . 'nonexistent.json');

        $command = new GuardianIntegrityVerifyCommand($verifier, $config);
        $input = new ArrayInput('studio:console:guardian:integrity:verify');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('Manifest not found', $this->output->buffer);
        self::assertStringContainsString('integrity:build', $this->output->buffer);
    }

    #[Test]
    public function it_returns_failure_when_manifest_not_found_json(): void
    {
        $verifier = new ManifestVerifier($this->tempDir);
        $config = new IntegrityConfig(manifestPath: $this->tempDir . DIRECTORY_SEPARATOR . 'nonexistent.json');

        $command = new GuardianIntegrityVerifyCommand($verifier, $config);
        $input = new ArrayInput('studio:console:guardian:integrity:verify', [], ['json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);

        /** @var array{command: string, success: bool, data: array{error: string}} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertSame('studio:console:guardian:integrity:verify', $json['command']);
        self::assertFalse($json['success']);
        self::assertStringContainsString('Manifest not found', $json['data']['error']);
    }

    #[Test]
    public function it_verifies_passing_manifest_as_text(): void
    {
        // Create a file and build a manifest from it
        $srcDir = $this->tempDir . DIRECTORY_SEPARATOR . 'src';
        mkdir($srcDir, 0o750, true);
        file_put_contents($srcDir . DIRECTORY_SEPARATOR . 'App.php', '<?php class App {}');

        $builder = new ManifestBuilder($this->tempDir);
        $manifest = $builder->build(['src/*.php'], []);
        $manifestPath = $this->tempDir . DIRECTORY_SEPARATOR . 'manifest.json';
        file_put_contents($manifestPath, ManifestFormat::toJson($manifest));

        $verifier = new ManifestVerifier($this->tempDir);
        $config = new IntegrityConfig(manifestPath: $manifestPath);

        $command = new GuardianIntegrityVerifyCommand($verifier, $config);
        $input = new ArrayInput('studio:console:guardian:integrity:verify');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Integrity Verification', $this->output->buffer);
        self::assertStringContainsString('Verified:  1', $this->output->buffer);
        self::assertStringContainsString('Modified:  0', $this->output->buffer);
        self::assertStringContainsString('Missing:   0', $this->output->buffer);
        self::assertStringContainsString('Integrity check passed.', $this->output->buffer);
    }

    #[Test]
    public function it_detects_modified_files(): void
    {
        // Create a file and build a manifest
        $srcDir = $this->tempDir . DIRECTORY_SEPARATOR . 'src';
        mkdir($srcDir, 0o750, true);
        file_put_contents($srcDir . DIRECTORY_SEPARATOR . 'App.php', '<?php class App {}');

        $builder = new ManifestBuilder($this->tempDir);
        $manifest = $builder->build(['src/*.php'], []);
        $manifestPath = $this->tempDir . DIRECTORY_SEPARATOR . 'manifest.json';
        file_put_contents($manifestPath, ManifestFormat::toJson($manifest));

        // Modify the file after building manifest
        file_put_contents($srcDir . DIRECTORY_SEPARATOR . 'App.php', '<?php class App { /* modified */ }');

        $verifier = new ManifestVerifier($this->tempDir);
        $config = new IntegrityConfig(manifestPath: $manifestPath);

        $command = new GuardianIntegrityVerifyCommand($verifier, $config);
        $input = new ArrayInput('studio:console:guardian:integrity:verify');

        $exit = $command->execute($input, $this->output);

        // Not strict, so success even when verification fails
        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Modified:  1', $this->output->buffer);
        self::assertStringContainsString('Discrepancies:', $this->output->buffer);
        self::assertStringContainsString('[modified]', $this->output->buffer);
        self::assertStringContainsString('Integrity check failed.', $this->output->buffer);
    }

    #[Test]
    public function it_returns_failure_in_strict_mode_when_check_fails(): void
    {
        $srcDir = $this->tempDir . DIRECTORY_SEPARATOR . 'src';
        mkdir($srcDir, 0o750, true);
        file_put_contents($srcDir . DIRECTORY_SEPARATOR . 'App.php', '<?php class App {}');

        $builder = new ManifestBuilder($this->tempDir);
        $manifest = $builder->build(['src/*.php'], []);
        $manifestPath = $this->tempDir . DIRECTORY_SEPARATOR . 'manifest.json';
        file_put_contents($manifestPath, ManifestFormat::toJson($manifest));

        // Modify the file
        file_put_contents($srcDir . DIRECTORY_SEPARATOR . 'App.php', '<?php class App { /* modified */ }');

        $verifier = new ManifestVerifier($this->tempDir);
        $config = new IntegrityConfig(manifestPath: $manifestPath);

        $command = new GuardianIntegrityVerifyCommand($verifier, $config);
        $input = new ArrayInput('studio:console:guardian:integrity:verify', [], ['strict' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);
    }

    #[Test]
    public function it_returns_failure_when_config_mode_is_strict(): void
    {
        $srcDir = $this->tempDir . DIRECTORY_SEPARATOR . 'src';
        mkdir($srcDir, 0o750, true);
        file_put_contents($srcDir . DIRECTORY_SEPARATOR . 'App.php', '<?php class App {}');

        $builder = new ManifestBuilder($this->tempDir);
        $manifest = $builder->build(['src/*.php'], []);
        $manifestPath = $this->tempDir . DIRECTORY_SEPARATOR . 'manifest.json';
        file_put_contents($manifestPath, ManifestFormat::toJson($manifest));

        // Modify the file
        file_put_contents($srcDir . DIRECTORY_SEPARATOR . 'App.php', '<?php class App { /* modified */ }');

        $verifier = new ManifestVerifier($this->tempDir);
        $config = new IntegrityConfig(manifestPath: $manifestPath, mode: IntegrityPolicyMode::Strict);

        $command = new GuardianIntegrityVerifyCommand($verifier, $config);
        $input = new ArrayInput('studio:console:guardian:integrity:verify');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);
    }

    #[Test]
    public function it_verifies_manifest_as_json(): void
    {
        $srcDir = $this->tempDir . DIRECTORY_SEPARATOR . 'src';
        mkdir($srcDir, 0o750, true);
        file_put_contents($srcDir . DIRECTORY_SEPARATOR . 'App.php', '<?php class App {}');

        $builder = new ManifestBuilder($this->tempDir);
        $manifest = $builder->build(['src/*.php'], []);
        $manifestPath = $this->tempDir . DIRECTORY_SEPARATOR . 'manifest.json';
        file_put_contents($manifestPath, ManifestFormat::toJson($manifest));

        $verifier = new ManifestVerifier($this->tempDir);
        $config = new IntegrityConfig(manifestPath: $manifestPath);

        $command = new GuardianIntegrityVerifyCommand($verifier, $config);
        $input = new ArrayInput('studio:console:guardian:integrity:verify', [], ['json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array{command: string, success: bool, data: array{passed: bool, verified: int, modified: int, missing: int, added: int, discrepancies: list<mixed>}} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertSame('studio:console:guardian:integrity:verify', $json['command']);
        self::assertTrue($json['success']);
        self::assertTrue($json['data']['passed']);
        self::assertSame(1, $json['data']['verified']);
        self::assertSame(0, $json['data']['modified']);
        self::assertSame([], $json['data']['discrepancies']);
    }

    #[Test]
    public function it_returns_failure_json_when_check_fails_and_strict(): void
    {
        $srcDir = $this->tempDir . DIRECTORY_SEPARATOR . 'src';
        mkdir($srcDir, 0o750, true);
        file_put_contents($srcDir . DIRECTORY_SEPARATOR . 'App.php', '<?php class App {}');

        $builder = new ManifestBuilder($this->tempDir);
        $manifest = $builder->build(['src/*.php'], []);
        $manifestPath = $this->tempDir . DIRECTORY_SEPARATOR . 'manifest.json';
        file_put_contents($manifestPath, ManifestFormat::toJson($manifest));

        // Modify the file
        file_put_contents($srcDir . DIRECTORY_SEPARATOR . 'App.php', '<?php class App { /* modified */ }');

        $verifier = new ManifestVerifier($this->tempDir);
        $config = new IntegrityConfig(manifestPath: $manifestPath);

        $command = new GuardianIntegrityVerifyCommand($verifier, $config);
        $input = new ArrayInput('studio:console:guardian:integrity:verify', [], ['json' => true, 'strict' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);

        /** @var array{command: string, success: bool, data: array{passed: bool, discrepancies: list<array{path: string, status: string}>}} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertFalse($json['data']['passed']);
        self::assertCount(1, $json['data']['discrepancies']);
        self::assertSame('modified', $json['data']['discrepancies'][0]['status']);
    }

    #[Test]
    public function it_returns_success_json_when_check_fails_but_not_strict(): void
    {
        $srcDir = $this->tempDir . DIRECTORY_SEPARATOR . 'src';
        mkdir($srcDir, 0o750, true);
        file_put_contents($srcDir . DIRECTORY_SEPARATOR . 'App.php', '<?php class App {}');

        $builder = new ManifestBuilder($this->tempDir);
        $manifest = $builder->build(['src/*.php'], []);
        $manifestPath = $this->tempDir . DIRECTORY_SEPARATOR . 'manifest.json';
        file_put_contents($manifestPath, ManifestFormat::toJson($manifest));

        // Modify the file
        file_put_contents($srcDir . DIRECTORY_SEPARATOR . 'App.php', '<?php class App { /* modified */ }');

        $verifier = new ManifestVerifier($this->tempDir);
        $config = new IntegrityConfig(manifestPath: $manifestPath);

        $command = new GuardianIntegrityVerifyCommand($verifier, $config);
        $input = new ArrayInput('studio:console:guardian:integrity:verify', [], ['json' => true]);

        $exit = $command->execute($input, $this->output);

        // Not strict, so even with failed verification, exit code should be success
        self::assertSame(ExitCode::Success->value, $exit);
    }

    #[Test]
    public function it_detects_missing_files(): void
    {
        $srcDir = $this->tempDir . DIRECTORY_SEPARATOR . 'src';
        mkdir($srcDir, 0o750, true);
        file_put_contents($srcDir . DIRECTORY_SEPARATOR . 'App.php', '<?php class App {}');

        $builder = new ManifestBuilder($this->tempDir);
        $manifest = $builder->build(['src/*.php'], []);
        $manifestPath = $this->tempDir . DIRECTORY_SEPARATOR . 'manifest.json';
        file_put_contents($manifestPath, ManifestFormat::toJson($manifest));

        // Delete the file after building manifest
        unlink($srcDir . DIRECTORY_SEPARATOR . 'App.php');

        $verifier = new ManifestVerifier($this->tempDir);
        $config = new IntegrityConfig(manifestPath: $manifestPath);

        $command = new GuardianIntegrityVerifyCommand($verifier, $config);
        $input = new ArrayInput('studio:console:guardian:integrity:verify');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit); // Not strict
        self::assertStringContainsString('Missing:   1', $this->output->buffer);
        self::assertStringContainsString('[missing]', $this->output->buffer);
        self::assertStringContainsString('Integrity check failed.', $this->output->buffer);
    }

    #[Test]
    public function it_verifies_empty_manifest(): void
    {
        $manifest = new IntegrityManifest(
            version: 1,
            algorithm: 'sha256',
            generatedAt: time(),
            frameworkVersion: '1.0.0-rc.2',
            entryCount: 0,
            entries: [],
        );
        $manifestPath = $this->tempDir . DIRECTORY_SEPARATOR . 'manifest.json';
        file_put_contents($manifestPath, ManifestFormat::toJson($manifest));

        $verifier = new ManifestVerifier($this->tempDir);
        $config = new IntegrityConfig(manifestPath: $manifestPath);

        $command = new GuardianIntegrityVerifyCommand($verifier, $config);
        $input = new ArrayInput('studio:console:guardian:integrity:verify');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Verified:  0', $this->output->buffer);
        self::assertStringContainsString('Integrity check passed.', $this->output->buffer);
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
