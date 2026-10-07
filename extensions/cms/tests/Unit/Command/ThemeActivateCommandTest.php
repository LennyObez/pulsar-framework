<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Command;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Extension\Cms\Command\ThemeActivateCommand;
use Pulsar\Extension\Cms\Themes\InstalledTheme;
use Pulsar\Extension\Cms\Themes\ThemeRepositoryInterface;

#[CoversClass(ThemeActivateCommand::class)]
final class ThemeActivateCommandTest extends TestCase
{
    #[Test]
    public function configuredCorrectly(): void
    {
        $repo = $this->createStub(ThemeRepositoryInterface::class);
        $command = new ThemeActivateCommand($repo);

        self::assertSame('cms:theme:activate', $command->name);
        self::assertNotEmpty($command->description);
    }

    #[Test]
    public function failsWhenSlugArgumentMissing(): void
    {
        $repo = $this->createStub(ThemeRepositoryInterface::class);
        $command = new ThemeActivateCommand($repo);

        $input = new ArrayInput('cms:theme:activate', []);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Invalid->value, $exitCode);
        self::assertStringContainsString('Missing required argument', $output->errorBuffer);
    }

    #[Test]
    public function failsWhenThemeNotFound(): void
    {
        $repo = $this->createStub(ThemeRepositoryInterface::class);
        $repo->method('findBySlug')->willReturn(null);

        $command = new ThemeActivateCommand($repo);

        $input = new ArrayInput('cms:theme:activate', ['nonexistent']);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $exitCode);
        self::assertStringContainsString('Theme not found', $output->errorBuffer);
    }

    #[Test]
    public function failsWhenThemeIsDeleted(): void
    {
        $now = new DateTimeImmutable();

        $theme = new InstalledTheme(
            id: 'theme-001',
            tenantId: null,
            slug: 'deleted-theme',
            displayName: 'Deleted Theme',
            version: '1.0.0',
            description: null,
            authorName: null,
            authorUrl: null,
            license: null,
            manifestHash: 'abc123',
            packageHash: 'def456',
            provenanceVerified: false,
            signatureVerified: false,
            isActive: false,
            storagePath: '/themes/deleted-theme',
            installedAt: $now,
            installedBy: 'cli',
            activatedAt: null,
            activatedBy: null,
            deactivatedAt: null,
            deletedAt: $now,
        );

        $repo = $this->createStub(ThemeRepositoryInterface::class);
        $repo->method('findBySlug')->willReturn($theme);

        $command = new ThemeActivateCommand($repo);

        $input = new ArrayInput('cms:theme:activate', ['deleted-theme']);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $exitCode);
        self::assertStringContainsString('deleted', $output->errorBuffer);
    }

    #[Test]
    public function returnsSuccessWhenThemeAlreadyActive(): void
    {
        $now = new DateTimeImmutable();

        $theme = new InstalledTheme(
            id: 'theme-001',
            tenantId: null,
            slug: 'active-theme',
            displayName: 'Active Theme',
            version: '1.0.0',
            description: null,
            authorName: null,
            authorUrl: null,
            license: null,
            manifestHash: 'abc123',
            packageHash: 'def456',
            provenanceVerified: false,
            signatureVerified: false,
            isActive: true,
            storagePath: '/themes/active-theme',
            installedAt: $now,
            installedBy: 'cli',
            activatedAt: $now,
            activatedBy: 'cli',
            deactivatedAt: null,
            deletedAt: null,
        );

        $repo = $this->createStub(ThemeRepositoryInterface::class);
        $repo->method('findBySlug')->willReturn($theme);

        $command = new ThemeActivateCommand($repo);

        $input = new ArrayInput('cms:theme:activate', ['active-theme']);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertStringContainsString('already the active theme', $output->buffer);
    }

    #[Test]
    public function activatesThemeAndDeactivatesPrevious(): void
    {
        $now = new DateTimeImmutable();

        $currentActive = new InstalledTheme(
            id: 'theme-old',
            tenantId: null,
            slug: 'old-theme',
            displayName: 'Old Theme',
            version: '1.0.0',
            description: null,
            authorName: null,
            authorUrl: null,
            license: null,
            manifestHash: 'hash1',
            packageHash: 'hash2',
            provenanceVerified: false,
            signatureVerified: false,
            isActive: true,
            storagePath: '/themes/old-theme',
            installedAt: $now,
            installedBy: 'cli',
            activatedAt: $now,
            activatedBy: 'cli',
            deactivatedAt: null,
            deletedAt: null,
        );

        $targetTheme = new InstalledTheme(
            id: 'theme-new',
            tenantId: null,
            slug: 'new-theme',
            displayName: 'New Theme',
            version: '2.0.0',
            description: null,
            authorName: null,
            authorUrl: null,
            license: null,
            manifestHash: 'hash3',
            packageHash: 'hash4',
            provenanceVerified: false,
            signatureVerified: false,
            isActive: false,
            storagePath: '/themes/new-theme',
            installedAt: $now,
            installedBy: 'cli',
            activatedAt: null,
            activatedBy: null,
            deactivatedAt: null,
            deletedAt: null,
        );

        /** @var list<InstalledTheme> $savedThemes */
        $savedThemes = [];

        $repo = $this->createMock(ThemeRepositoryInterface::class);
        $repo->method('findBySlug')->willReturnMap([['new-theme', $targetTheme]]);
        $repo->method('findActive')->willReturn($currentActive);
        $repo->expects(self::exactly(2))
            ->method('save')
            ->willReturnCallback(static function (InstalledTheme $theme) use (&$savedThemes): void {
                $savedThemes[] = $theme;
            });

        $command = new ThemeActivateCommand($repo);

        $input = new ArrayInput('cms:theme:activate', ['new-theme']);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertStringContainsString('New Theme', $output->buffer);
        self::assertStringContainsString('now the active theme', $output->buffer);

        // First save: deactivate old theme
        self::assertCount(2, $savedThemes);
        self::assertSame('theme-old', $savedThemes[0]->id);
        self::assertFalse($savedThemes[0]->isActive);

        // Second save: activate new theme
        self::assertSame('theme-new', $savedThemes[1]->id);
        self::assertTrue($savedThemes[1]->isActive);
    }

    #[Test]
    public function activatesThemeWhenNoPreviousActive(): void
    {
        $now = new DateTimeImmutable();

        $targetTheme = new InstalledTheme(
            id: 'theme-first',
            tenantId: null,
            slug: 'first-theme',
            displayName: 'First Theme',
            version: '1.0.0',
            description: null,
            authorName: null,
            authorUrl: null,
            license: null,
            manifestHash: 'hash1',
            packageHash: 'hash2',
            provenanceVerified: false,
            signatureVerified: false,
            isActive: false,
            storagePath: '/themes/first-theme',
            installedAt: $now,
            installedBy: 'cli',
            activatedAt: null,
            activatedBy: null,
            deactivatedAt: null,
            deletedAt: null,
        );

        /** @var InstalledTheme|null $savedTheme */
        $savedTheme = null;

        $repo = $this->createMock(ThemeRepositoryInterface::class);
        $repo->method('findBySlug')->willReturnMap([['first-theme', $targetTheme]]);
        $repo->method('findActive')->willReturn(null);
        $repo->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (InstalledTheme $theme) use (&$savedTheme): void {
                $savedTheme = $theme;
            });

        $command = new ThemeActivateCommand($repo);

        $input = new ArrayInput('cms:theme:activate', ['first-theme']);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertNotNull($savedTheme);
        self::assertTrue($savedTheme->isActive);
        self::assertSame('cli', $savedTheme->activatedBy);
    }
}
