<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\NewProject;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\NewProject\EnvironmentPreset;
use Pulsar\Console\Command\NewProject\ProjectGenerator;
use Pulsar\Console\Command\NewProject\ProjectPreset;
use Pulsar\Console\OutputInterface;
use RuntimeException;

#[CoversClass(ProjectGenerator::class)]
final class ProjectGeneratorTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_project_gen_test_' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function it_generates_a_minimal_project(): void
    {
        $generator = new ProjectGenerator();
        $output = $this->createStub(OutputInterface::class);

        $generator->generate('my-app', ProjectPreset::Minimal, EnvironmentPreset::Local, $this->tempDir, $output);

        self::assertDirectoryExists($this->tempDir);
        self::assertDirectoryExists($this->tempDir . DIRECTORY_SEPARATOR . 'config');
        self::assertDirectoryExists($this->tempDir . DIRECTORY_SEPARATOR . 'public');
        self::assertDirectoryExists($this->tempDir . DIRECTORY_SEPARATOR . 'src');
        self::assertDirectoryExists($this->tempDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'cache');
        self::assertDirectoryExists($this->tempDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'logs');

        // Minimal preset should NOT have web or api directories
        self::assertDirectoryDoesNotExist($this->tempDir . DIRECTORY_SEPARATOR . 'resources');
        self::assertDirectoryDoesNotExist($this->tempDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Http');
    }

    #[Test]
    public function it_generates_a_web_project_with_extra_directories(): void
    {
        $generator = new ProjectGenerator();
        $output = $this->createStub(OutputInterface::class);

        $generator->generate('my-web-app', ProjectPreset::Web, EnvironmentPreset::Local, $this->tempDir, $output);

        self::assertDirectoryExists($this->tempDir . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'views');
        self::assertDirectoryExists($this->tempDir . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'assets');
        self::assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'welcome.php');
        self::assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'security.php');
    }

    #[Test]
    public function it_generates_an_api_project_with_extra_directories(): void
    {
        $generator = new ProjectGenerator();
        $output = $this->createStub(OutputInterface::class);

        $generator->generate('my-api-app', ProjectPreset::Api, EnvironmentPreset::Local, $this->tempDir, $output);

        self::assertDirectoryExists($this->tempDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Http' . DIRECTORY_SEPARATOR . 'Controller');
        self::assertDirectoryExists($this->tempDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Http' . DIRECTORY_SEPARATOR . 'Middleware');
        self::assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Http' . DIRECTORY_SEPARATOR . 'Controller' . DIRECTORY_SEPARATOR . 'HealthController.php');
        self::assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'security.php');
    }

    #[Test]
    public function it_generates_an_env_file(): void
    {
        $generator = new ProjectGenerator();
        $output = $this->createStub(OutputInterface::class);

        $generator->generate('my-app', ProjectPreset::Minimal, EnvironmentPreset::Local, $this->tempDir, $output);

        $envPath = $this->tempDir . DIRECTORY_SEPARATOR . '.env';
        self::assertFileExists($envPath);

        $content = file_get_contents($envPath);
        self::assertIsString($content);
        self::assertStringContainsString('APP_NAME=my-app', $content);
        self::assertStringContainsString('APP_KEY=', $content);
        self::assertStringContainsString('PULSAR_MASTER_KEY=', $content);
    }

    #[Test]
    public function it_generates_common_files_for_all_presets(): void
    {
        $generator = new ProjectGenerator();
        $output = $this->createStub(OutputInterface::class);

        $generator->generate('my-app', ProjectPreset::Minimal, EnvironmentPreset::Local, $this->tempDir, $output);

        self::assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php');
        self::assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php');
        self::assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . '.gitignore');
        self::assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . 'composer.json');
    }

    #[Test]
    public function it_throws_when_target_directory_already_exists(): void
    {
        mkdir($this->tempDir, 0o755, true);

        $generator = new ProjectGenerator();
        $output = $this->createStub(OutputInterface::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already exists');

        $generator->generate('my-app', ProjectPreset::Minimal, EnvironmentPreset::Local, $this->tempDir, $output);
    }

    #[Test]
    public function it_reports_progress_via_output(): void
    {
        $generator = new ProjectGenerator();

        $writtenLines = [];
        $output = $this->createStub(OutputInterface::class);
        $output->method('writeln')->willReturnCallback(function (string $msg = '') use (&$writtenLines): void {
            $writtenLines[] = $msg;
        });

        $generator->generate('my-app', ProjectPreset::Minimal, EnvironmentPreset::Local, $this->tempDir, $output);

        $allOutput = implode("\n", $writtenLines);

        // Should report creating project
        self::assertStringContainsString('my-app', $allOutput);
        self::assertStringContainsString('minimal', $allOutput);

        // Should report directory creation
        self::assertStringContainsString('Directories:', $allOutput);

        // Should report file creation
        self::assertStringContainsString('Files:', $allOutput);
    }

    #[Test]
    public function it_generates_production_env_file(): void
    {
        $generator = new ProjectGenerator();
        $output = $this->createStub(OutputInterface::class);

        $generator->generate('prod-app', ProjectPreset::Minimal, EnvironmentPreset::Production, $this->tempDir, $output);

        $envPath = $this->tempDir . DIRECTORY_SEPARATOR . '.env';
        $content = file_get_contents($envPath);
        self::assertIsString($content);
        self::assertStringContainsString('APP_ENV=production', $content);
        self::assertStringContainsString('APP_DEBUG=false', $content);
    }

    #[Test]
    public function it_generates_staging_env_file(): void
    {
        $generator = new ProjectGenerator();
        $output = $this->createStub(OutputInterface::class);

        $generator->generate('staging-app', ProjectPreset::Minimal, EnvironmentPreset::Staging, $this->tempDir, $output);

        $envPath = $this->tempDir . DIRECTORY_SEPARATOR . '.env';
        $content = file_get_contents($envPath);
        self::assertIsString($content);
        self::assertStringContainsString('APP_ENV=staging', $content);
        self::assertStringContainsString('APP_DEBUG=false', $content);
    }

    #[Test]
    public function it_creates_the_composer_json_with_correct_content(): void
    {
        $generator = new ProjectGenerator();
        $output = $this->createStub(OutputInterface::class);

        $generator->generate('my-app', ProjectPreset::Web, EnvironmentPreset::Local, $this->tempDir, $output);

        $composerPath = $this->tempDir . DIRECTORY_SEPARATOR . 'composer.json';
        $content = file_get_contents($composerPath);
        self::assertIsString($content);

        $decoded = json_decode($content, true);
        self::assertIsArray($decoded);
        self::assertIsString($decoded['name']);
        self::assertStringContainsString('my-app', $decoded['name']);
        self::assertIsArray($decoded['require']);
        self::assertArrayHasKey('require', $decoded);
        self::assertArrayHasKey('php', $decoded['require']);
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
