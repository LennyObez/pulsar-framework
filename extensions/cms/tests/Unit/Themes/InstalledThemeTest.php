<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Themes;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Themes\InstalledTheme;

#[CoversClass(InstalledTheme::class)]
final class InstalledThemeTest extends TestCase
{
    private function createTheme(bool $isActive = false): InstalledTheme
    {
        return new InstalledTheme(
            id: 'theme-1',
            tenantId: null,
            slug: 'horizon',
            displayName: 'Horizon',
            version: '1.0.0',
            description: 'A theme',
            authorName: 'Author',
            authorUrl: null,
            license: 'MIT',
            manifestHash: 'hash1',
            packageHash: 'hash2',
            provenanceVerified: true,
            signatureVerified: true,
            isActive: $isActive,
            storagePath: 'themes/horizon',
            installedAt: new DateTimeImmutable(),
            installedBy: 'user-1',
            activatedAt: null,
            activatedBy: null,
            deactivatedAt: null,
            deletedAt: null,
        );
    }

    #[Test]
    public function activate_sets_active_flag_and_timestamps(): void
    {
        $theme = $this->createTheme(isActive: false);
        $now = new DateTimeImmutable();
        $activated = $theme->activate('admin-1', $now);

        self::assertTrue($activated->isActive);
        self::assertSame($now, $activated->activatedAt);
        self::assertSame('admin-1', $activated->activatedBy);
        self::assertNull($activated->deactivatedAt);
    }

    #[Test]
    public function deactivate_clears_active_and_sets_deactivatedAt(): void
    {
        $theme = $this->createTheme(isActive: true);
        $now = new DateTimeImmutable();
        $deactivated = $theme->deactivate($now);

        self::assertFalse($deactivated->isActive);
        self::assertSame($now, $deactivated->deactivatedAt);
    }

    #[Test]
    public function softDelete_sets_deletedAt(): void
    {
        $theme = $this->createTheme();
        $now = new DateTimeImmutable();
        $deleted = $theme->softDelete($now);

        self::assertSame($now, $deleted->deletedAt);
        self::assertTrue($deleted->isDeleted());
    }

    #[Test]
    public function isDeleted_returns_false_when_no_deletedAt(): void
    {
        $theme = $this->createTheme();

        self::assertFalse($theme->isDeleted());
    }
}
