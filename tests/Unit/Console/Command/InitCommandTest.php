<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\InitCommand;
use Pulsar\Console\Command\NewProject\ComposerJsonGenerator;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;

use function bin2hex;
use function file_exists;
use function file_get_contents;
use function is_dir;
use function json_decode;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function str_contains;
use function unlink;

use const DIRECTORY_SEPARATOR;

#[CoversClass(InitCommand::class)]
final class InitCommandTest extends TestCase
{
    private string $tempDir;
    private BufferedOutput $output;

    #[Override]
    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_init_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o750, true);
        $this->output = new BufferedOutput();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function nameIsInit(): void
    {
        $command = new InitCommand();

        self::assertSame('init', $command->name);
    }

    #[Test]
    public function descriptionIsSet(): void
    {
        $command = new InitCommand();

        self::assertNotEmpty($command->description);
    }

    #[Test]
    public function initializesProjectStructure(): void
    {
        $targetDir = $this->tempDir . DIRECTORY_SEPARATOR . 'myproject';
        $command = new InitCommand();
        $input = new ArrayInput(arguments: [$targetDir]);
        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('initialized successfully', $this->output->buffer);

        // Verify directory structure matches pulsar new --preset=minimal
        self::assertTrue(is_dir($targetDir . DIRECTORY_SEPARATOR . 'config'));
        self::assertTrue(is_dir($targetDir . DIRECTORY_SEPARATOR . 'public'));
        self::assertTrue(is_dir($targetDir . DIRECTORY_SEPARATOR . 'src'));
        self::assertTrue(is_dir($targetDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'cache'));
        self::assertTrue(is_dir($targetDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'logs'));

        // Verify files created
        self::assertTrue(file_exists($targetDir . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php'));
        self::assertTrue(file_exists($targetDir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php'));
        self::assertTrue(file_exists($targetDir . DIRECTORY_SEPARATOR . '.gitignore'));
        self::assertTrue(file_exists($targetDir . DIRECTORY_SEPARATOR . 'composer.json'));
        self::assertTrue(file_exists($targetDir . DIRECTORY_SEPARATOR . '.env'));
    }

    #[Test]
    public function generatedComposerJsonRequiresCorrectPackage(): void
    {
        $targetDir = $this->tempDir . DIRECTORY_SEPARATOR . 'pkgtest';
        $command = new InitCommand();
        $input = new ArrayInput(arguments: [$targetDir]);
        $command->execute($input, $this->output);

        $composerJson = file_get_contents($targetDir . DIRECTORY_SEPARATOR . 'composer.json');
        self::assertIsString($composerJson);

        $decoded = json_decode($composerJson, true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('require', $decoded);

        /** @var array<string, string> $require */
        $require = $decoded['require'];
        self::assertArrayHasKey(ComposerJsonGenerator::FRAMEWORK_PACKAGE, $require);
    }

    /**
     * An existing empty directory is what `init` is for.
     *
     * The command's own help offers "Target directory (default: current
     * directory)", and its default is `getcwd()`, which always exists — so while
     * the generator refused every existing directory, the bare command could not
     * succeed from anywhere, and neither could the install guide's flow of
     * `composer require` followed by `init` in the same directory.
     */
    #[Test]
    public function fillsAnExistingEmptyDirectory(): void
    {
        $targetDir = $this->tempDir . DIRECTORY_SEPARATOR . 'existing';
        mkdir($targetDir, 0o755, true);

        $command = new InitCommand();
        $input = new ArrayInput(arguments: [$targetDir]);
        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertFileExists($targetDir . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php');
    }

    /**
     * ...and a file already there is never replaced.
     *
     * The guard did not disappear, it moved to where the risk actually is. A
     * composer.json left by `composer require pulsar/framework` is the collision
     * this meets in practice, and overwriting it would delete the requirement
     * that put the binary on disk.
     */
    #[Test]
    public function returnsErrorWhenAFileItWouldWriteIsAlreadyThere(): void
    {
        $targetDir = $this->tempDir . DIRECTORY_SEPARATOR . 'existing';
        mkdir($targetDir, 0o755, true);
        file_put_contents($targetDir . DIRECTORY_SEPARATOR . 'composer.json', '{"name":"acme/mine"}');

        $command = new InitCommand();
        $input = new ArrayInput(arguments: [$targetDir]);
        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('composer.json', $this->output->errorBuffer);
        self::assertSame(
            '{"name":"acme/mine"}',
            file_get_contents($targetDir . DIRECTORY_SEPARATOR . 'composer.json'),
        );
    }

    #[Test]
    public function showsNextSteps(): void
    {
        $targetDir = $this->tempDir . DIRECTORY_SEPARATOR . 'nextproject';
        $command = new InitCommand();
        $input = new ArrayInput(arguments: [$targetDir]);
        $command->execute($input, $this->output);

        self::assertStringContainsString('Next steps', $this->output->buffer);
        self::assertStringContainsString('composer install', $this->output->buffer);
    }

    #[Test]
    public function generatedEnvContainsSecureKeys(): void
    {
        $targetDir = $this->tempDir . DIRECTORY_SEPARATOR . 'envtest';
        $command = new InitCommand();
        $input = new ArrayInput(arguments: [$targetDir]);
        $command->execute($input, $this->output);

        $envContent = file_get_contents($targetDir . DIRECTORY_SEPARATOR . '.env');
        self::assertIsString($envContent);
        self::assertTrue(str_contains($envContent, 'APP_KEY=base64:'));
        self::assertTrue(str_contains($envContent, 'PULSAR_MASTER_KEY='));
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
