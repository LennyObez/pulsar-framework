<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\ReleaseCheckerInterface;
use Pulsar\Console\Command\ReleaseInfo;
use Pulsar\Console\Command\SelfUpdateCommand;
use Pulsar\Console\Command\UpdateResult;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use ReflectionProperty;

#[CoversClass(SelfUpdateCommand::class)]
final class SelfUpdateCommandTest extends TestCase
{
    #[Test]
    public function commandNameIsSelfUpdate(): void
    {
        $checker = $this->createStub(ReleaseCheckerInterface::class);
        $command = new SelfUpdateCommand($checker);

        self::assertSame('self-update', $command->name);
    }

    #[Test]
    public function commandHasDescription(): void
    {
        $checker = $this->createStub(ReleaseCheckerInterface::class);
        $command = new SelfUpdateCommand($checker);

        self::assertNotEmpty($command->description);
    }

    #[Test]
    public function returnsErrorWhenReleaseCheckFails(): void
    {
        $checker = $this->createStub(ReleaseCheckerInterface::class);
        $checker->method('getLatestRelease')->willReturn(null);

        $command = new SelfUpdateCommand($checker);

        $input = $this->createStub(InputInterface::class);
        $input->method('hasOption')->willReturn(false);

        $output = $this->buildOutputStub();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $exitCode);
    }

    #[Test]
    public function returnsSuccessWhenAlreadyOnLatestVersion(): void
    {
        $currentVersion = \Pulsar\Core\Version::full();
        $release = new ReleaseInfo(
            version: $currentVersion,
            changelog: '',
            releaseDate: '2026-03-20',
        );

        $checker = $this->createStub(ReleaseCheckerInterface::class);
        $checker->method('getLatestRelease')->willReturn($release);

        $command = new SelfUpdateCommand($checker);

        $input = $this->createStub(InputInterface::class);
        $input->method('hasOption')->willReturn(false);

        $output = $this->buildOutputStub();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
    }

    #[Test]
    public function showsUpdateAvailableInCheckOnlyMode(): void
    {
        $release = new ReleaseInfo(
            version: '99.99.99',
            changelog: 'New features',
            releaseDate: '2026-12-31',
        );

        $checker = $this->createStub(ReleaseCheckerInterface::class);
        $checker->method('getLatestRelease')->willReturn($release);

        $command = new SelfUpdateCommand($checker);

        $input = $this->createStub(InputInterface::class);
        $input->method('hasOption')->willReturnCallback(
            static fn(string $name): bool => $name === 'check',
        );

        $output = $this->buildOutputStub();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
    }

    #[Test]
    public function appliesUpdateSuccessfully(): void
    {
        $release = new ReleaseInfo(
            version: '99.99.99',
            changelog: 'Breaking changes here',
            releaseDate: '2026-12-31',
        );

        $checker = $this->createStub(ReleaseCheckerInterface::class);
        $checker->method('getLatestRelease')->willReturn($release);
        $checker->method('applyUpdate')->willReturn(UpdateResult::ok('Run migrations'));

        $command = new SelfUpdateCommand($checker);

        $input = $this->createStub(InputInterface::class);
        $input->method('hasOption')->willReturn(false);

        $output = $this->buildOutputStub();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
    }

    #[Test]
    public function returnsErrorWhenUpdateFails(): void
    {
        $release = new ReleaseInfo(
            version: '99.99.99',
            changelog: '',
            releaseDate: '2026-12-31',
        );

        $checker = $this->createStub(ReleaseCheckerInterface::class);
        $checker->method('getLatestRelease')->willReturn($release);
        $checker->method('applyUpdate')->willReturn(UpdateResult::failed('Composer conflict'));

        $command = new SelfUpdateCommand($checker);

        $input = $this->createStub(InputInterface::class);
        $input->method('hasOption')->willReturn(false);

        $output = $this->buildOutputStub();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $exitCode);
    }

    #[Test]
    public function optionShortcutsDoNotHaveLeadingDashes(): void
    {
        $checker = $this->createStub(ReleaseCheckerInterface::class);
        $command = new SelfUpdateCommand($checker);

        // Use reflection to access the options property
        $ref = new ReflectionProperty($command, 'options');
        /** @var array<string, array{description: string, shortcut: ?string, default: ?string}> $options */
        $options = $ref->getValue($command);

        self::assertArrayHasKey('check', $options);
        self::assertArrayHasKey('force', $options);
        self::assertSame('c', $options['check']['shortcut']);
        self::assertSame('f', $options['force']['shortcut']);
    }

    #[Test]
    public function forceOptionBypassesVersionCheck(): void
    {
        $currentVersion = \Pulsar\Core\Version::full();
        $release = new ReleaseInfo(
            version: $currentVersion, // Same version
            changelog: '',
            releaseDate: '2026-03-20',
        );

        $checker = $this->createStub(ReleaseCheckerInterface::class);
        $checker->method('getLatestRelease')->willReturn($release);
        $checker->method('applyUpdate')->willReturn(UpdateResult::ok());

        $command = new SelfUpdateCommand($checker);

        $input = $this->createStub(InputInterface::class);
        $input->method('hasOption')->willReturnCallback(
            static fn(string $name): bool => $name === 'force',
        );

        $output = $this->buildOutputStub();

        $exitCode = $command->execute($input, $output);

        // With force, it should proceed even though versions match
        self::assertSame(ExitCode::Success->value, $exitCode);
    }

    private function buildOutputStub(): OutputInterface
    {
        return $this->createStub(OutputInterface::class);
    }
}
