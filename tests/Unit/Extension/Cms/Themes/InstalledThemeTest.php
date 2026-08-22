<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Themes;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Themes\InstalledTheme;

#[CoversClass(InstalledTheme::class)]
final class InstalledThemeTest extends TestCase
{
    #[Test]
    public function activateSetsIsActiveAndTimestamps(): void
    {
        $theme = $this->createTheme(isActive: false);
        $now = new DateTimeImmutable('2025-01-15 10:00:00');

        $activated = $theme->activate('admin-01', $now);

        self::assertTrue($activated->isActive);
        self::assertSame('admin-01', $activated->activatedBy);
        self::assertSame($now, $activated->activatedAt);
        self::assertNull($activated->deactivatedAt);
        self::assertFalse($theme->isActive);
    }

    #[Test]
    public function deactivateSetsIsInactiveAndTimestamp(): void
    {
        $theme = $this->createTheme(isActive: true);
        $now = new DateTimeImmutable('2025-01-15 11:00:00');

        $deactivated = $theme->deactivate($now);

        self::assertFalse($deactivated->isActive);
        self::assertSame($now, $deactivated->deactivatedAt);
        self::assertTrue($theme->isActive);
    }

    #[Test]
    public function softDeleteSetsDeletedAt(): void
    {
        $theme = $this->createTheme();
        $now = new DateTimeImmutable('2025-01-15 12:00:00');

        $deleted = $theme->softDelete($now);

        self::assertSame($now, $deleted->deletedAt);
        self::assertTrue($deleted->isDeleted());
        self::assertFalse($theme->isDeleted());
    }

    #[Test]
    public function isDeletedReturnsFalseWhenDeletedAtIsNull(): void
    {
        $theme = $this->createTheme();

        self::assertFalse($theme->isDeleted());
    }

    #[Test]
    public function isDeletedReturnsTrueWhenDeletedAtIsSet(): void
    {
        $now = new DateTimeImmutable();
        $theme = new InstalledTheme(
            id: 't1',
            tenantId: null,
            slug: 'my-theme',
            displayName: 'My Theme',
            version: '1.0.0',
            description: null,
            authorName: null,
            authorUrl: null,
            license: null,
            manifestHash: 'abc',
            packageHash: 'def',
            provenanceVerified: true,
            signatureVerified: false,
            isActive: false,
            storagePath: '/tmp/themes/t1',
            installedAt: $now,
            installedBy: 'admin',
            activatedAt: null,
            activatedBy: null,
            deactivatedAt: null,
            deletedAt: $now,
        );

        self::assertTrue($theme->isDeleted());
    }

    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $now = new DateTimeImmutable();
        $theme = new InstalledTheme(
            id: 't1',
            tenantId: 'tenant-01',
            slug: 'modern-theme',
            displayName: 'Modern Theme',
            version: '2.0.0',
            description: 'A modern theme',
            authorName: 'Designer',
            authorUrl: 'https://example.com',
            license: 'MIT',
            manifestHash: 'hash1',
            packageHash: 'hash2',
            provenanceVerified: true,
            signatureVerified: true,
            isActive: true,
            storagePath: '/themes/modern',
            installedAt: $now,
            installedBy: 'admin',
            activatedAt: $now,
            activatedBy: 'admin',
            deactivatedAt: null,
            deletedAt: null,
        );

        self::assertSame('t1', $theme->id);
        self::assertSame('tenant-01', $theme->tenantId);
        self::assertSame('modern-theme', $theme->slug);
        self::assertSame('Modern Theme', $theme->displayName);
        self::assertSame('2.0.0', $theme->version);
        self::assertSame('A modern theme', $theme->description);
        self::assertSame('Designer', $theme->authorName);
        self::assertTrue($theme->provenanceVerified);
        self::assertTrue($theme->signatureVerified);
        self::assertTrue($theme->isActive);
    }

    private function createTheme(bool $isActive = false): InstalledTheme
    {
        $now = new DateTimeImmutable();

        return new InstalledTheme(
            id: 't1',
            tenantId: null,
            slug: 'theme-t1',
            displayName: 'Theme T1',
            version: '1.0.0',
            description: null,
            authorName: null,
            authorUrl: null,
            license: null,
            manifestHash: 'abc',
            packageHash: 'def',
            provenanceVerified: true,
            signatureVerified: false,
            isActive: $isActive,
            storagePath: '/tmp/themes/t1',
            installedAt: $now,
            installedBy: 'admin',
            activatedAt: $isActive ? $now : null,
            activatedBy: $isActive ? 'admin' : null,
            deactivatedAt: null,
            deletedAt: null,
        );
    }
}
