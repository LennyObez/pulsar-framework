<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Command\Console\Guardian;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\IntegrityConfig;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Extension\Studio\Command\Console\Guardian\GuardianIntegrityBuildCommand;
use Pulsar\Integrity\ManifestBuilder;

#[CoversClass(GuardianIntegrityBuildCommand::class)]
final class GuardianIntegrityBuildCommandTest extends TestCase
{
    private BufferedOutput $output;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->output = new BufferedOutput();
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_integrity_build_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function it_is_configured_correctly(): void
    {
        $builder = new ManifestBuilder($this->tempDir);
        $config = new IntegrityConfig();
        $command = new GuardianIntegrityBuildCommand($builder, $config);

        self::assertSame('studio:console:guardian:integrity:build', $command->name);
        self::assertSame('Build an integrity manifest', $command->description);
        self::assertArrayHasKey('sign', $command->options);
        self::assertSame('s', $command->options['sign']['shortcut']);
        self::assertArrayHasKey('output', $command->options);
        self::assertSame('o', $command->options['output']['shortcut']);
        self::assertArrayHasKey('json', $command->options);
        self::assertSame('j', $command->options['json']['shortcut']);
    }

    #[Test]
    public function it_builds_manifest_as_text(): void
    {
        // Create some test files to include in the manifest
        $srcDir = $this->tempDir . DIRECTORY_SEPARATOR . 'src';
        mkdir($srcDir, 0o750, true);
        file_put_contents($srcDir . DIRECTORY_SEPARATOR . 'App.php', '<?php class App {}');
        file_put_contents($srcDir . DIRECTORY_SEPARATOR . 'Router.php', '<?php class Router {}');

        $builder = new ManifestBuilder($this->tempDir);
        $outputPath = $this->tempDir . DIRECTORY_SEPARATOR . 'manifest.json';
        $config = new IntegrityConfig(
            manifestPath: $outputPath,
            include: ['src/*.php'],
            exclude: [],
        );

        $command = new GuardianIntegrityBuildCommand($builder, $config);
        $input = new ArrayInput('studio:console:guardian:integrity:build');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Integrity Manifest Built', $this->output->buffer);
        self::assertStringContainsString($outputPath, $this->output->buffer);
        self::assertStringContainsString('Entries:  2', $this->output->buffer);
        self::assertStringContainsString('Signed:   no', $this->output->buffer);
        self::assertFileExists($outputPath);
    }

    #[Test]
    public function it_builds_manifest_with_custom_output_path(): void
    {
        $builder = new ManifestBuilder($this->tempDir);
        $customPath = $this->tempDir . DIRECTORY_SEPARATOR . 'custom' . DIRECTORY_SEPARATOR . 'manifest.json';
        $config = new IntegrityConfig(include: [], exclude: []);

        $command = new GuardianIntegrityBuildCommand($builder, $config);
        $input = new ArrayInput('studio:console:guardian:integrity:build', [], ['output' => $customPath]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString($customPath, $this->output->buffer);
        self::assertFileExists($customPath);
    }

    #[Test]
    public function it_builds_manifest_as_json(): void
    {
        $srcDir = $this->tempDir . DIRECTORY_SEPARATOR . 'src';
        mkdir($srcDir, 0o750, true);
        file_put_contents($srcDir . DIRECTORY_SEPARATOR . 'App.php', '<?php class App {}');

        $builder = new ManifestBuilder($this->tempDir);
        $outputPath = $this->tempDir . DIRECTORY_SEPARATOR . 'manifest.json';
        $config = new IntegrityConfig(
            manifestPath: $outputPath,
            include: ['src/*.php'],
            exclude: [],
        );

        $command = new GuardianIntegrityBuildCommand($builder, $config);
        $input = new ArrayInput('studio:console:guardian:integrity:build', [], ['json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array{command: string, success: bool, data: array{path: string, entries: int, signed: bool}} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertSame('studio:console:guardian:integrity:build', $json['command']);
        self::assertTrue($json['success']);
        self::assertSame($outputPath, $json['data']['path']);
        self::assertSame(1, $json['data']['entries']);
        self::assertFalse($json['data']['signed']);
    }

    #[Test]
    public function it_fails_when_sign_requested_but_no_signer_text(): void
    {
        $builder = new ManifestBuilder($this->tempDir);
        $config = new IntegrityConfig(include: [], exclude: []);

        $command = new GuardianIntegrityBuildCommand($builder, $config, signer: null);
        $input = new ArrayInput('studio:console:guardian:integrity:build', [], ['sign' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('Signing requested but no MasterKey available', $this->output->buffer);
    }

    #[Test]
    public function it_fails_when_sign_requested_but_no_signer_json(): void
    {
        $builder = new ManifestBuilder($this->tempDir);
        $config = new IntegrityConfig(include: [], exclude: []);

        $command = new GuardianIntegrityBuildCommand($builder, $config, signer: null);
        $input = new ArrayInput('studio:console:guardian:integrity:build', [], ['sign' => true, 'json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);

        /** @var array{command: string, success: bool, data: array{error: string}} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertSame('studio:console:guardian:integrity:build', $json['command']);
        self::assertFalse($json['success']);
        self::assertStringContainsString('MasterKey', $json['data']['error']);
    }

    #[Test]
    public function it_builds_empty_manifest_with_no_matching_files(): void
    {
        $builder = new ManifestBuilder($this->tempDir);
        $outputPath = $this->tempDir . DIRECTORY_SEPARATOR . 'manifest.json';
        $config = new IntegrityConfig(
            manifestPath: $outputPath,
            include: ['nonexistent/**/*.php'],
            exclude: [],
        );

        $command = new GuardianIntegrityBuildCommand($builder, $config);
        $input = new ArrayInput('studio:console:guardian:integrity:build');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Entries:  0', $this->output->buffer);
        self::assertStringContainsString('Signed:   no', $this->output->buffer);
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
