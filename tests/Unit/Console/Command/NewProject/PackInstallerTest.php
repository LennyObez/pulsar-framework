<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\NewProject;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\NewProject\PackInstaller;
use Pulsar\Console\Command\NewProject\PackLoader;
use Pulsar\Console\OutputInterface;
use RuntimeException;

use const DIRECTORY_SEPARATOR;

#[CoversClass(PackInstaller::class)]
final class PackInstallerTest extends TestCase
{
    private string $tempDir;
    private string $packsDir;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(8));
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_pack_inst_test_' . $suffix;
        $this->packsDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_pack_inst_packs_' . $suffix;
        mkdir($this->tempDir, 0o755, true);
        mkdir($this->packsDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        $this->removeDirectory($this->packsDir);
    }

    #[Test]
    public function it_installs_pack_files_with_variable_substitution(): void
    {
        // Create a test pack
        $packDir = $this->packsDir . DIRECTORY_SEPARATOR . 'test-pack';
        mkdir($packDir, 0o755, true);

        file_put_contents($packDir . DIRECTORY_SEPARATOR . 'pack.json', json_encode([
            'name' => 'test-pack',
            'description' => 'A test pack',
            'version' => '1.0.0',
            'requiredPulsarVersion' => '^1.0',
            'compliancePresets' => [],
            'files' => [
                'src/Entity.php' => 'src/Entity/Entity.php',
            ],
            'postInstallCommands' => [],
        ], JSON_PRETTY_PRINT));

        // Create the template file
        mkdir($packDir . DIRECTORY_SEPARATOR . 'src', 0o755, true);
        file_put_contents(
            $packDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Entity.php',
            "<?php\n\nnamespace {{namespace}}\\Entity;\n\nfinal class Entity\n{\n    // Project: {{project_name}}\n}\n",
        );

        $loader = new PackLoader($this->packsDir);
        $installer = new PackInstaller($loader);
        $output = $this->createStub(OutputInterface::class);

        $installer->install('test-pack', 'my-app', $this->tempDir, $output);

        $targetFile = $this->tempDir . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . 'Entity' . DIRECTORY_SEPARATOR . 'Entity.php';

        self::assertFileExists($targetFile);

        $content = file_get_contents($targetFile);
        self::assertIsString($content);
        self::assertStringContainsString('namespace MyApp\\Entity;', $content);
        self::assertStringContainsString('Project: my-app', $content);
        self::assertStringNotContainsString('{{namespace}}', $content);
        self::assertStringNotContainsString('{{project_name}}', $content);
    }

    #[Test]
    public function it_copies_static_documentation_files(): void
    {
        $packDir = $this->packsDir . DIRECTORY_SEPARATOR . 'doc-pack';
        mkdir($packDir, 0o755, true);

        file_put_contents($packDir . DIRECTORY_SEPARATOR . 'pack.json', json_encode([
            'name' => 'doc-pack',
            'description' => 'Pack with docs',
            'version' => '1.0.0',
            'requiredPulsarVersion' => '^1.0',
            'files' => [],
        ], JSON_PRETTY_PRINT));

        file_put_contents($packDir . DIRECTORY_SEPARATOR . 'CONTROLS.md', '# Controls');
        file_put_contents($packDir . DIRECTORY_SEPARATOR . 'NOT-CERTIFIED.md', '# Not Certified');

        $docsDir = $packDir . DIRECTORY_SEPARATOR . 'docs';
        mkdir($docsDir, 0o755, true);
        file_put_contents($docsDir . DIRECTORY_SEPARATOR . 'setup.md', '# Setup Guide');

        $loader = new PackLoader($this->packsDir);
        $installer = new PackInstaller($loader);
        $output = $this->createStub(OutputInterface::class);

        $installer->install('doc-pack', 'my-app', $this->tempDir, $output);

        self::assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . 'CONTROLS.md');
        self::assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . 'NOT-CERTIFIED.md');
        self::assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'setup.md');
    }

    #[Test]
    public function it_reports_progress_via_output(): void
    {
        $packDir = $this->packsDir . DIRECTORY_SEPARATOR . 'progress-pack';
        mkdir($packDir, 0o755, true);
        mkdir($packDir . DIRECTORY_SEPARATOR . 'src', 0o755, true);

        file_put_contents($packDir . DIRECTORY_SEPARATOR . 'pack.json', json_encode([
            'name' => 'progress-pack',
            'description' => 'Progress test',
            'version' => '1.0.0',
            'requiredPulsarVersion' => '^1.0',
            'files' => [
                'src/Test.php' => 'src/Test.php',
            ],
        ], JSON_PRETTY_PRINT));

        file_put_contents($packDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Test.php', '<?php');
        file_put_contents($packDir . DIRECTORY_SEPARATOR . 'NOT-CERTIFIED.md', '# Disclaimer');

        $writtenLines = [];
        $output = $this->createStub(OutputInterface::class);
        $output->method('writeln')->willReturnCallback(function (string $msg = '') use (&$writtenLines): void {
            $writtenLines[] = $msg;
        });

        $loader = new PackLoader($this->packsDir);
        $installer = new PackInstaller($loader);

        $installer->install('progress-pack', 'my-app', $this->tempDir, $output);

        $allOutput = implode("\n", $writtenLines);

        self::assertStringContainsString('Installing control pack: progress-pack', $allOutput);
        self::assertStringContainsString('Pack files:', $allOutput);
        self::assertStringContainsString('Created src/Test.php', $allOutput);
    }

    #[Test]
    public function it_throws_for_nonexistent_pack(): void
    {
        $loader = new PackLoader($this->packsDir);
        $installer = new PackInstaller($loader);
        $output = $this->createStub(OutputInterface::class);

        $this->expectException(RuntimeException::class);

        $installer->install('nonexistent', 'my-app', $this->tempDir, $output);
    }

    #[Test]
    public function it_creates_nested_directories_for_target_files(): void
    {
        $packDir = $this->packsDir . DIRECTORY_SEPARATOR . 'nested-pack';
        mkdir($packDir . DIRECTORY_SEPARATOR . 'src', 0o755, true);

        file_put_contents($packDir . DIRECTORY_SEPARATOR . 'pack.json', json_encode([
            'name' => 'nested-pack',
            'description' => 'Nested dir test',
            'version' => '1.0.0',
            'requiredPulsarVersion' => '^1.0',
            'files' => [
                'src/Deep.php' => 'src/Very/Deep/Nested/Entity.php',
            ],
        ], JSON_PRETTY_PRINT));

        file_put_contents($packDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Deep.php', '<?php');

        $loader = new PackLoader($this->packsDir);
        $installer = new PackInstaller($loader);
        $output = $this->createStub(OutputInterface::class);

        $installer->install('nested-pack', 'my-app', $this->tempDir, $output);

        self::assertFileExists(
            $this->tempDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Very'
            . DIRECTORY_SEPARATOR . 'Deep' . DIRECTORY_SEPARATOR . 'Nested'
            . DIRECTORY_SEPARATOR . 'Entity.php',
        );
    }

    #[Test]
    public function it_skips_missing_source_files_gracefully(): void
    {
        $packDir = $this->packsDir . DIRECTORY_SEPARATOR . 'missing-files';
        mkdir($packDir, 0o755, true);

        file_put_contents($packDir . DIRECTORY_SEPARATOR . 'pack.json', json_encode([
            'name' => 'missing-files',
            'description' => 'Missing files test',
            'version' => '1.0.0',
            'requiredPulsarVersion' => '^1.0',
            'files' => [
                'src/DoesNotExist.php' => 'src/Entity.php',
            ],
        ], JSON_PRETTY_PRINT));

        $loader = new PackLoader($this->packsDir);
        $installer = new PackInstaller($loader);
        $output = $this->createStub(OutputInterface::class);

        // Should not throw
        $installer->install('missing-files', 'my-app', $this->tempDir, $output);

        self::assertFileDoesNotExist($this->tempDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Entity.php');
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
