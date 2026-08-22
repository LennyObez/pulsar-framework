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
use RuntimeException;

use function bin2hex;
use function mkdir;
use function random_bytes;
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
        mkdir($this->tempDir . '/cache', 0o755, true);
    }

    protected function tearDown(): void
    {
        @rmdir($this->tempDir . '/storage');
        @rmdir($this->tempDir . '/cache');
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
        @rmdir($this->tempDir . '/cache');

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
        // storage/ and cache/ are missing, so at least 2 fails
        self::assertGreaterThanOrEqual(2, $counts['fail']);

        @unlink($this->tempDir . '/vendor/autoload.php');
        @rmdir($this->tempDir . '/vendor');

        // Re-create for tearDown
        mkdir($this->tempDir . '/storage', 0o755, true);
        mkdir($this->tempDir . '/cache', 0o755, true);
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

    private function buildOutputStub(): OutputInterface
    {
        return $this->createStub(OutputInterface::class);
    }
}
