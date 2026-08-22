<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Themes;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Extension\Cms\Internal\Themes\ThemeSafeMode;
use Pulsar\Extension\Cms\Themes\InstalledTheme;
use Pulsar\Extension\Cms\Themes\ThemeRepositoryInterface;

#[CoversClass(ThemeSafeMode::class)]
final class ThemeSafeModeTest extends TestCase
{
    #[Test]
    public function enter_deactivates_active_theme_and_persists(): void
    {
        $active = $this->createActiveTheme();

        $repository = $this->createMock(ThemeRepositoryInterface::class);
        $repository->method('findActive')->willReturn($active);
        $repository->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (InstalledTheme $theme): bool {
                return !$theme->isActive && $theme->deactivatedAt !== null;
            }));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('critical')
            ->with(
                self::stringContains('Entering theme safe mode'),
                self::callback(static function (array $context): bool {
                    return $context['slug'] === 'test-theme'
                        && $context['reason'] === 'integrity failure';
                }),
            );

        $safeMode = new ThemeSafeMode($repository, $logger);
        $safeMode->enter('integrity failure');
    }

    #[Test]
    public function enter_without_active_theme_logs_warning_and_does_not_save(): void
    {
        $repository = $this->createMock(ThemeRepositoryInterface::class);
        $repository->method('findActive')->willReturn(null);
        $repository->expects(self::never())->method('save');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                self::stringContains('no active theme'),
                self::callback(static fn(array $ctx): bool => $ctx['reason'] === 'missing theme'),
            );

        $safeMode = new ThemeSafeMode($repository, $logger);
        $safeMode->enter('missing theme');
    }

    #[Test]
    public function enter_passes_tenant_id_to_repository(): void
    {
        $repository = $this->createMock(ThemeRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findActive')
            ->with('tenant-42')
            ->willReturn(null);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $safeMode = new ThemeSafeMode($repository, $logger);
        $safeMode->enter('test', 'tenant-42');
    }

    #[Test]
    public function shouldActivate_returns_true_when_no_active_theme(): void
    {
        $repository = $this->createStub(ThemeRepositoryInterface::class);
        $repository->method('findActive')->willReturn(null);

        $logger = $this->createStub(LoggerInterface::class);

        $safeMode = new ThemeSafeMode($repository, $logger);

        self::assertTrue($safeMode->shouldActivate());
    }

    #[Test]
    public function shouldActivate_returns_false_when_active_theme_exists(): void
    {
        $repository = $this->createStub(ThemeRepositoryInterface::class);
        $repository->method('findActive')->willReturn($this->createActiveTheme());

        $logger = $this->createStub(LoggerInterface::class);

        $safeMode = new ThemeSafeMode($repository, $logger);

        self::assertFalse($safeMode->shouldActivate());
    }

    #[Test]
    public function getDefaultTemplatePath_returns_safe_mode_template(): void
    {
        $repository = $this->createStub(ThemeRepositoryInterface::class);
        $logger = $this->createStub(LoggerInterface::class);

        $safeMode = new ThemeSafeMode($repository, $logger);

        self::assertStringContainsString('safe-mode/default.pulse.php', $safeMode->getDefaultTemplatePath());
    }

    private function createActiveTheme(): InstalledTheme
    {
        return new InstalledTheme(
            id: 'theme-1',
            tenantId: null,
            slug: 'test-theme',
            displayName: 'Test Theme',
            version: '1.0.0',
            description: 'A test theme',
            authorName: 'Author',
            authorUrl: null,
            license: 'MIT',
            manifestHash: 'hash1',
            packageHash: 'hash2',
            provenanceVerified: true,
            signatureVerified: true,
            isActive: true,
            storagePath: 'themes/test-theme',
            installedAt: new DateTimeImmutable(),
            installedBy: 'user-1',
            activatedAt: new DateTimeImmutable(),
            activatedBy: 'user-1',
            deactivatedAt: null,
            deletedAt: null,
        );
    }
}
