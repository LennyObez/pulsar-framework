<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\SupplyChain\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\SupplyChain\Command\LicenseCheckCommand;

#[CoversClass(LicenseCheckCommand::class)]
final class LicenseCheckCommandTest extends TestCase
{
    #[Test]
    public function configurationSetsNameAndDescription(): void
    {
        $command = new LicenseCheckCommand('/tmp/project');

        self::assertSame('supply-chain:licenses', $command->name);
        self::assertSame('Check dependency licenses against the allowlist', $command->description);
    }

    #[Test]
    public function executeReturnsErrorWhenComposerLockDoesNotExist(): void
    {
        $nonExistentDir = '/tmp/no-such-project-' . bin2hex(random_bytes(4));
        $command = new LicenseCheckCommand($nonExistentDir);

        $input = $this->createStub(InputInterface::class);
        $output = $this->createStub(OutputInterface::class);

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $exitCode);
    }

    #[Test]
    public function executeReturnsSuccessForAllCompliantLicenses(): void
    {
        $tempDir = sys_get_temp_dir() . '/pulsar-license-test-' . bin2hex(random_bytes(4));
        mkdir($tempDir, 0o777, true);

        $lockData = json_encode([
            'packages' => [
                ['name' => 'vendor/lib-a', 'version' => 'v1.0.0', 'license' => ['MIT']],
                ['name' => 'vendor/lib-b', 'version' => 'v2.0.0', 'license' => ['Apache-2.0']],
            ],
            'packages-dev' => [],
        ], JSON_THROW_ON_ERROR);

        file_put_contents($tempDir . '/composer.lock', $lockData);

        $command = new LicenseCheckCommand($tempDir);
        $input = $this->createStub(InputInterface::class);
        $output = $this->createStub(OutputInterface::class);

        $exitCode = $command->execute($input, $output);

        unlink($tempDir . '/composer.lock');
        rmdir($tempDir);

        self::assertSame(ExitCode::Success->value, $exitCode);
    }

    #[Test]
    public function executeReturnsErrorForNonCompliantLicenses(): void
    {
        $tempDir = sys_get_temp_dir() . '/pulsar-license-fail-' . bin2hex(random_bytes(4));
        mkdir($tempDir, 0o777, true);

        $lockData = json_encode([
            'packages' => [
                ['name' => 'vendor/proprietary', 'version' => 'v1.0.0', 'license' => ['SSPL-1.0']],
            ],
            'packages-dev' => [],
        ], JSON_THROW_ON_ERROR);

        file_put_contents($tempDir . '/composer.lock', $lockData);

        $command = new LicenseCheckCommand($tempDir);
        $input = $this->createStub(InputInterface::class);
        $output = $this->createStub(OutputInterface::class);

        $exitCode = $command->execute($input, $output);

        unlink($tempDir . '/composer.lock');
        rmdir($tempDir);

        self::assertSame(ExitCode::Error->value, $exitCode);
    }

    #[Test]
    public function executeReturnsErrorForUnknownLicenses(): void
    {
        $tempDir = sys_get_temp_dir() . '/pulsar-license-unknown-' . bin2hex(random_bytes(4));
        mkdir($tempDir, 0o777, true);

        $lockData = json_encode([
            'packages' => [
                ['name' => 'vendor/no-license', 'version' => 'v1.0.0', 'license' => []],
            ],
            'packages-dev' => [],
        ], JSON_THROW_ON_ERROR);

        file_put_contents($tempDir . '/composer.lock', $lockData);

        $command = new LicenseCheckCommand($tempDir);
        $input = $this->createStub(InputInterface::class);
        $output = $this->createStub(OutputInterface::class);

        $exitCode = $command->execute($input, $output);

        unlink($tempDir . '/composer.lock');
        rmdir($tempDir);

        self::assertSame(ExitCode::Error->value, $exitCode);
    }

    #[Test]
    public function executeReturnsSuccessForEmptyPackageList(): void
    {
        $tempDir = sys_get_temp_dir() . '/pulsar-license-empty-' . bin2hex(random_bytes(4));
        mkdir($tempDir, 0o777, true);

        $lockData = json_encode([
            'packages' => [],
            'packages-dev' => [],
        ], JSON_THROW_ON_ERROR);

        file_put_contents($tempDir . '/composer.lock', $lockData);

        $command = new LicenseCheckCommand($tempDir);
        $input = $this->createStub(InputInterface::class);
        $output = $this->createStub(OutputInterface::class);

        $exitCode = $command->execute($input, $output);

        unlink($tempDir . '/composer.lock');
        rmdir($tempDir);

        self::assertSame(ExitCode::Success->value, $exitCode);
    }
}
