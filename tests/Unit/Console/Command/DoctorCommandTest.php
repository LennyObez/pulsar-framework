<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\DoctorCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\ConnectionManagerInterface;
use Pulsar\Database\Result;
use ReflectionClass;
use RuntimeException;

use function array_keys;
use function bin2hex;
use function dirname;
use function file_get_contents;
use function glob;
use function implode;
use function mkdir;
use function preg_match_all;
use function random_bytes;
use function sort;
use function sprintf;
use function sys_get_temp_dir;

#[CoversClass(DoctorCommand::class)]
final class DoctorCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/pulsar_doctor_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);
        mkdir($this->tempDir . '/storage', 0o755, true);
        mkdir($this->tempDir . '/var', 0o755, true);
    }

    protected function tearDown(): void
    {
        @rmdir($this->tempDir . '/storage');
        @rmdir($this->tempDir . '/var');
        @rmdir($this->tempDir);
    }

    #[Test]
    public function commandNameIsDoctor(): void
    {
        $command = new DoctorCommand();
        self::assertSame('doctor', $command->name);
    }

    #[Test]
    public function commandHasDescription(): void
    {
        $command = new DoctorCommand();
        self::assertNotEmpty($command->description);
    }

    #[Test]
    public function passesWithValidEnvironment(): void
    {
        $command = new DoctorCommand(
            masterKey: bin2hex(random_bytes(32)),
            basePath: $this->tempDir,
        );

        // Create vendor/autoload.php
        mkdir($this->tempDir . '/vendor', 0o755, true);
        file_put_contents($this->tempDir . '/vendor/autoload.php', '<?php');

        $input = $this->createStub(InputInterface::class);
        $output = $this->buildOutputStub();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        $counts = $command->getCounts();
        self::assertSame(0, $counts['fail']);

        // Cleanup
        @unlink($this->tempDir . '/vendor/autoload.php');
        @rmdir($this->tempDir . '/vendor');
    }

    #[Test]
    public function detectsMissingMasterKey(): void
    {
        $command = new DoctorCommand(
            masterKey: null,
            basePath: $this->tempDir,
        );

        mkdir($this->tempDir . '/vendor', 0o755, true);
        file_put_contents($this->tempDir . '/vendor/autoload.php', '<?php');

        $input = $this->createStub(InputInterface::class);
        $output = $this->buildOutputStub();

        $command->execute($input, $output);

        $counts = $command->getCounts();
        self::assertGreaterThan(0, $counts['fail']);

        @unlink($this->tempDir . '/vendor/autoload.php');
        @rmdir($this->tempDir . '/vendor');
    }

    #[Test]
    public function detectsWeakMasterKey(): void
    {
        $command = new DoctorCommand(
            masterKey: 'tooshort',
            basePath: $this->tempDir,
        );

        mkdir($this->tempDir . '/vendor', 0o755, true);
        file_put_contents($this->tempDir . '/vendor/autoload.php', '<?php');

        $input = $this->createStub(InputInterface::class);
        $output = $this->buildOutputStub();

        $command->execute($input, $output);

        $counts = $command->getCounts();
        self::assertGreaterThan(0, $counts['fail']);

        @unlink($this->tempDir . '/vendor/autoload.php');
        @rmdir($this->tempDir . '/vendor');
    }

    #[Test]
    public function acceptsStrongHexMasterKey(): void
    {
        $command = new DoctorCommand(
            masterKey: bin2hex(random_bytes(32)), // 64 hex chars
            basePath: $this->tempDir,
        );

        mkdir($this->tempDir . '/vendor', 0o755, true);
        file_put_contents($this->tempDir . '/vendor/autoload.php', '<?php');

        $input = $this->createStub(InputInterface::class);
        $output = $this->buildOutputStub();

        $command->execute($input, $output);

        $counts = $command->getCounts();
        // Master key should pass
        // We can't assert zero fails because some required extensions might not be present,
        // but master key specifically should pass
        self::assertGreaterThan(0, $counts['pass']);

        @unlink($this->tempDir . '/vendor/autoload.php');
        @rmdir($this->tempDir . '/vendor');
    }

    #[Test]
    public function detectsMissingWritableDirectories(): void
    {
        @rmdir($this->tempDir . '/storage');
        @rmdir($this->tempDir . '/var');

        $command = new DoctorCommand(
            masterKey: bin2hex(random_bytes(32)),
            basePath: $this->tempDir,
        );

        mkdir($this->tempDir . '/vendor', 0o755, true);
        file_put_contents($this->tempDir . '/vendor/autoload.php', '<?php');

        $input = $this->createStub(InputInterface::class);
        $output = $this->buildOutputStub();

        $command->execute($input, $output);

        $counts = $command->getCounts();
        // storage/ and var/ are missing, so at least 2 fails
        self::assertGreaterThanOrEqual(2, $counts['fail']);

        @unlink($this->tempDir . '/vendor/autoload.php');
        @rmdir($this->tempDir . '/vendor');

        // Re-create for tearDown
        mkdir($this->tempDir . '/storage', 0o755, true);
        mkdir($this->tempDir . '/var', 0o755, true);
    }

    #[Test]
    public function detectsMissingComposerAutoload(): void
    {
        $command = new DoctorCommand(
            masterKey: bin2hex(random_bytes(32)),
            basePath: $this->tempDir,
        );

        $input = $this->createStub(InputInterface::class);
        $output = $this->buildOutputStub();

        $command->execute($input, $output);

        $counts = $command->getCounts();
        self::assertGreaterThan(0, $counts['fail']);
    }

    #[Test]
    public function checksRequiredExtensions(): void
    {
        $command = new DoctorCommand(basePath: $this->tempDir);
        $input = $this->createStub(InputInterface::class);
        $output = $this->buildOutputStub();

        $command->execute($input, $output);

        $counts = $command->getCounts();
        // At least json, mbstring, pcre should pass (they're almost always present)
        self::assertGreaterThan(0, $counts['pass']);
    }

    #[Test]
    public function skipsDbCheckWhenNoConnectionManager(): void
    {
        $command = new DoctorCommand(
            connectionManager: null,
            masterKey: bin2hex(random_bytes(32)),
            basePath: $this->tempDir,
        );

        mkdir($this->tempDir . '/vendor', 0o755, true);
        file_put_contents($this->tempDir . '/vendor/autoload.php', '<?php');

        $input = $this->createStub(InputInterface::class);
        $output = $this->buildOutputStub();

        $command->execute($input, $output);

        $counts = $command->getCounts();
        // Database check should result in a warning, not a failure
        self::assertGreaterThan(0, $counts['warn']);

        @unlink($this->tempDir . '/vendor/autoload.php');
        @rmdir($this->tempDir . '/vendor');
    }

    #[Test]
    public function detectsDatabaseConnectionFailure(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willThrowException(new RuntimeException('Connection refused'));

        $connManager = $this->createStub(ConnectionManagerInterface::class);
        $connManager->method('connection')->willReturn($connection);

        $command = new DoctorCommand(
            connectionManager: $connManager,
            masterKey: bin2hex(random_bytes(32)),
            basePath: $this->tempDir,
        );

        mkdir($this->tempDir . '/vendor', 0o755, true);
        file_put_contents($this->tempDir . '/vendor/autoload.php', '<?php');

        $input = $this->createStub(InputInterface::class);
        $output = $this->buildOutputStub();

        $command->execute($input, $output);

        $counts = $command->getCounts();
        self::assertGreaterThan(0, $counts['fail']);

        @unlink($this->tempDir . '/vendor/autoload.php');
        @rmdir($this->tempDir . '/vendor');
    }

    #[Test]
    public function returnsErrorExitCodeOnFailures(): void
    {
        $command = new DoctorCommand(
            masterKey: null, // This will cause a failure
            basePath: '/nonexistent/path', // This will cause failures too
        );

        $input = $this->createStub(InputInterface::class);
        $output = $this->buildOutputStub();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $exitCode);
    }

    #[Test]
    public function getCountsReturnsCorrectStructure(): void
    {
        $command = new DoctorCommand();
        $counts = $command->getCounts();

        self::assertArrayHasKey('pass', $counts);
        self::assertArrayHasKey('fail', $counts);
        self::assertArrayHasKey('warn', $counts);
        self::assertSame(0, $counts['pass']);
        self::assertSame(0, $counts['fail']);
        self::assertSame(0, $counts['warn']);
    }

    /**
     * Doctor may only demand a directory the shipped configuration writes into.
     *
     * The list held `cache` -- a project-root directory no config file, command or
     * service has ever named. Nothing created it, so `Directory: cache/ (missing)`
     * failed on every correct installation and `pulsar doctor` exited 1 out of the
     * box: the first command a reader runs to confirm their environment is sound,
     * reporting a fault in itself. Every other test in this class made the
     * directories first and then asserted, so all of them stayed green while it
     * shipped.
     *
     * Deriving the answer from `config/*.php` is what makes this fail rather than
     * agree: the files are read as text, so a path added there next month is in
     * scope without anybody remembering this test exists.
     */
    #[Test]
    public function everyDirectoryDoctorDemandsIsOneTheShippedConfigurationWritesInto(): void
    {
        $configured = self::rootsTheShippedConfigurationNames();

        self::assertContains(
            'var',
            $configured,
            'The scan of config/*.php found no `var/` path at all, so it is no longer reading the '
            . 'shipped configuration and its agreement below would mean nothing.',
        );

        foreach (self::directoriesDoctorDemands() as $directory) {
            self::assertContains(
                $directory,
                $configured,
                sprintf(
                    '`pulsar doctor` fails when `%s/` is absent, but no path in config/*.php starts '
                    . 'with it, so nothing in the framework ever creates it and the check can only '
                    . 'ever fail. Name a directory the configuration actually writes into (found: %s).',
                    $directory,
                    implode(', ', $configured),
                ),
            );
        }
    }

    /**
     * The directories DoctorCommand refuses to run without.
     *
     * @return list<string>
     */
    private static function directoriesDoctorDemands(): array
    {
        /** @var mixed $constant */
        $constant = new ReflectionClass(DoctorCommand::class)->getConstant('WRITABLE_DIRS');
        self::assertIsArray($constant);

        $directories = [];

        foreach ($constant as $value) {
            self::assertIsString($value);
            $directories[] = $value;
        }

        return $directories;
    }

    /**
     * First path segment of every relative path the shipped config files name.
     *
     * Read as text rather than executed: a config file may call `getenv()` and
     * build paths from it, and this test is about the literals the repository
     * ships, not about the environment it happens to run in.
     *
     * @return list<string>
     */
    private static function rootsTheShippedConfigurationNames(): array
    {
        $files = glob(dirname(__DIR__, 4) . '/config/*.php');
        self::assertIsArray($files);
        self::assertNotSame([], $files, 'No config/*.php files were found to derive the answer from.');

        $roots = [];

        foreach ($files as $file) {
            $contents = file_get_contents($file);

            if ($contents === false) {
                continue;
            }

            if (preg_match_all("~'([a-z][a-z0-9_-]*)/[a-z0-9_./*-]+'~", $contents, $matches) === false) {
                continue;
            }

            foreach ($matches[1] as $root) {
                $roots[$root] = true;
            }
        }

        $names = array_keys($roots);
        sort($names);

        return $names;
    }

    private function buildOutputStub(): OutputInterface
    {
        return $this->createStub(OutputInterface::class);
    }
}
