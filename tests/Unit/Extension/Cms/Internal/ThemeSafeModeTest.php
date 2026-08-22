<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Internal;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Extension\Cms\Content\Event\CmsReady;
use Pulsar\Extension\Cms\Internal\Themes\ThemeSafeMode;
use Pulsar\Extension\Cms\Themes\InstalledTheme;
use Pulsar\Extension\Cms\Themes\ThemeRepositoryInterface;

#[CoversClass(ThemeSafeMode::class)]
#[CoversClass(CmsReady::class)]
final class ThemeSafeModeTest extends TestCase
{
    // --- ThemeSafeMode ---

    #[Test]
    public function shouldActivateWhenNoActiveTheme(): void
    {
        $repository = $this->createStub(ThemeRepositoryInterface::class);
        $repository->method('findActive')->willReturn(null);

        $logger = $this->createStub(LoggerInterface::class);

        $safeMode = new ThemeSafeMode($repository, $logger);

        self::assertTrue($safeMode->shouldActivate());
    }

    #[Test]
    public function shouldNotActivateWhenActiveThemeExists(): void
    {
        $theme = $this->buildInstalledTheme();
        $repository = $this->createStub(ThemeRepositoryInterface::class);
        $repository->method('findActive')->willReturn($theme);

        $logger = $this->createStub(LoggerInterface::class);

        $safeMode = new ThemeSafeMode($repository, $logger);

        self::assertFalse($safeMode->shouldActivate());
    }

    #[Test]
    public function getDefaultTemplatePathContainsSafeModeDir(): void
    {
        $repository = $this->createStub(ThemeRepositoryInterface::class);
        $logger = $this->createStub(LoggerInterface::class);

        $safeMode = new ThemeSafeMode($repository, $logger);
        $path = $safeMode->getDefaultTemplatePath();

        self::assertStringContainsString('safe-mode', $path);
        self::assertStringEndsWith('.pulse.php', $path);
    }

    #[Test]
    public function enterDoesNotThrowWhenNoActiveTheme(): void
    {
        $repository = $this->createStub(ThemeRepositoryInterface::class);
        $repository->method('findActive')->willReturn(null);

        $logger = $this->createStub(LoggerInterface::class);

        $safeMode = new ThemeSafeMode($repository, $logger);
        $safeMode->enter('no theme found');

        // No active theme means safe mode should still be active
        self::assertTrue($safeMode->shouldActivate());
    }

    #[Test]
    public function enterDoesNotThrowWhenActiveThemeExists(): void
    {
        $theme = $this->buildInstalledTheme();

        $repository = $this->createStub(ThemeRepositoryInterface::class);
        $repository->method('findActive')->willReturn($theme);

        $logger = $this->createStub(LoggerInterface::class);

        $safeMode = new ThemeSafeMode($repository, $logger);
        $safeMode->enter('integrity check failed');

        self::assertInstanceOf(ThemeSafeMode::class, $safeMode);
    }

    // --- CmsReady ---

    #[Test]
    public function cmsReadyHoldsTimestamp(): void
    {
        $now = new DateTimeImmutable();
        $event = new CmsReady(timestamp: $now);

        self::assertSame($now, $event->timestamp);
    }

    private function buildInstalledTheme(): InstalledTheme
    {
        $now = new DateTimeImmutable();

        return new InstalledTheme(
            id: 'theme-1',
            tenantId: null,
            slug: 'default-theme',
            displayName: 'Default Theme',
            version: '1.0.0',
            description: null,
            authorName: null,
            authorUrl: null,
            license: null,
            manifestHash: 'sha256:abc',
            packageHash: 'sha256:def',
            provenanceVerified: true,
            signatureVerified: true,
            isActive: true,
            storagePath: 'storage/themes/default',
            installedAt: $now,
            installedBy: 'user-1',
            activatedAt: $now,
            activatedBy: 'user-1',
            deactivatedAt: null,
            deletedAt: null,
        );
    }
}
