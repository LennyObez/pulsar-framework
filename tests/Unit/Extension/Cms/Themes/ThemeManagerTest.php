<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Themes;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Cms\Config\ThemesConfig;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Internal\Themes\ThemeManager;
use Pulsar\Extension\Cms\Themes\InstalledTheme;
use Pulsar\Extension\Cms\Themes\PreviewSessionRepositoryInterface;
use Pulsar\Extension\Cms\Themes\ThemeArchiveExtractorInterface;
use Pulsar\Extension\Cms\Themes\ThemeManifestValidatorInterface;
use Pulsar\Extension\Cms\Themes\ThemeProvenanceVerifierInterface;
use Pulsar\Extension\Cms\Themes\ThemeRepositoryInterface;

#[CoversClass(ThemeManager::class)]
final class ThemeManagerTest extends TestCase
{
    private ThemeRepositoryInterface&Stub $repo;
    private ThemeManager $manager;

    protected function setUp(): void
    {
        $this->repo = $this->createStub(ThemeRepositoryInterface::class);
        $manifestValidator = $this->createStub(ThemeManifestValidatorInterface::class);
        $provenanceVerifier = $this->createStub(ThemeProvenanceVerifierInterface::class);
        $archiveExtractor = $this->createStub(ThemeArchiveExtractorInterface::class);
        $config = new ThemesConfig();
        $events = $this->createStub(EventDispatcherInterface::class);
        $auditLogger = $this->createStub(AuditLoggerInterface::class);
        $logger = $this->createStub(LoggerInterface::class);
        $previewRepo = $this->createStub(PreviewSessionRepositoryInterface::class);

        $this->manager = new ThemeManager(
            $this->repo,
            $manifestValidator,
            $provenanceVerifier,
            $archiveExtractor,
            $config,
            $events,
            $auditLogger,
            $logger,
            $previewRepo,
        );
    }

    private function createTheme(
        string $id = 't1',
        bool $isActive = false,
        ?DateTimeImmutable $deactivatedAt = null,
    ): InstalledTheme {
        $now = new DateTimeImmutable();

        return new InstalledTheme(
            id: $id,
            tenantId: null,
            slug: "theme-{$id}",
            displayName: "Theme {$id}",
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
            storagePath: '/tmp/themes/' . $id,
            installedAt: $now,
            installedBy: 'admin',
            activatedAt: $isActive ? $now : null,
            activatedBy: $isActive ? 'admin' : null,
            deactivatedAt: $deactivatedAt,
            deletedAt: null,
        );
    }

    // ── activate ─────────────────────────────────────────────────────

    #[Test]
    public function activateThrowsWhenThemeNotFound(): void
    {
        $this->repo->method('findById')->willReturn(null);

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains('not found');

        $this->manager->activate('nonexistent', 'admin');
    }

    #[Test]
    public function activateThrowsWhenAlreadyActive(): void
    {
        $theme = $this->createTheme(isActive: true);
        $this->repo->method('findById')->willReturn($theme);

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains('already active');

        $this->manager->activate('t1', 'admin');
    }

    #[Test]
    public function activateSucceedsWhenInactive(): void
    {
        $theme = $this->createTheme(isActive: false);
        $this->repo->method('findById')->willReturn($theme);
        $this->repo->method('findActive')->willReturn(null);

        $result = $this->manager->activate('t1', 'admin');

        self::assertTrue($result->isActive);
        self::assertSame('admin', $result->activatedBy);
    }

    #[Test]
    public function activateDeactivatesCurrentActiveTheme(): void
    {
        $current = $this->createTheme(id: 'current', isActive: true);
        $newTheme = $this->createTheme(id: 'new', isActive: false);

        $this->repo->method('findById')->willReturn($newTheme);
        $this->repo->method('findActive')->willReturn($current);

        $result = $this->manager->activate('new', 'admin');

        self::assertTrue($result->isActive);
    }

    // ── deactivate ───────────────────────────────────────────────────

    #[Test]
    public function deactivateThrowsWhenThemeNotFound(): void
    {
        $this->repo->method('findById')->willReturn(null);

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains('not found');

        $this->manager->deactivate('nonexistent', 'admin');
    }

    #[Test]
    public function deactivateThrowsWhenNotActive(): void
    {
        $theme = $this->createTheme(isActive: false);
        $this->repo->method('findById')->willReturn($theme);

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains('not active');

        $this->manager->deactivate('t1', 'admin');
    }

    #[Test]
    public function deactivateSucceedsWhenActive(): void
    {
        $theme = $this->createTheme(isActive: true);
        $this->repo->method('findById')->willReturn($theme);

        $result = $this->manager->deactivate('t1', 'admin');

        self::assertFalse($result->isActive);
        self::assertNotNull($result->deactivatedAt);
    }

    // ── delete ───────────────────────────────────────────────────────

    #[Test]
    public function deleteThrowsWhenThemeNotFound(): void
    {
        $this->repo->method('findById')->willReturn(null);

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains('not found');

        $this->manager->delete('nonexistent', 'admin', 'cleanup');
    }

    #[Test]
    public function deleteThrowsWhenThemeIsActive(): void
    {
        $theme = $this->createTheme(isActive: true);
        $this->repo->method('findById')->willReturn($theme);

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains('active');

        $this->manager->delete('t1', 'admin', 'cleanup');
    }

    #[Test]
    public function deleteSucceedsWhenInactive(): void
    {
        $theme = $this->createTheme(isActive: false);
        $this->repo->method('findById')->willReturn($theme);

        $this->expectNotToPerformAssertions();

        $this->manager->delete('t1', 'admin', 'cleanup');
    }

    // ── preview ──────────────────────────────────────────────────────

    #[Test]
    public function previewThrowsWhenThemeNotFound(): void
    {
        $this->repo->method('findById')->willReturn(null);

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains('not found');

        $this->manager->preview('nonexistent', 'user-01');
    }

    #[Test]
    public function previewReturnsSessionForExistingTheme(): void
    {
        $theme = $this->createTheme();
        $this->repo->method('findById')->willReturn($theme);

        $session = $this->manager->preview('t1', 'user-01');

        self::assertSame('t1', $session->themeId);
        self::assertSame('user-01', $session->userId);
        self::assertNotEmpty($session->token);
    }

    // ── rollback ─────────────────────────────────────────────────────

    #[Test]
    public function rollbackThrowsWhenNoPreviousTheme(): void
    {
        $this->repo->method('findAll')->willReturn([]);

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains('previous');

        $this->manager->rollback('admin');
    }

    #[Test]
    public function rollbackThrowsWhenAllThemesActiveOrNeverDeactivated(): void
    {
        $this->repo->method('findAll')->willReturn([
            $this->createTheme(id: 'a', isActive: true),
            $this->createTheme(id: 'b', isActive: false, deactivatedAt: null),
        ]);

        $this->expectException(CmsException::class);

        $this->manager->rollback('admin');
    }

    #[Test]
    public function rollbackActivatesMostRecentlyDeactivatedTheme(): void
    {
        $older = $this->createTheme(id: 'old', isActive: false, deactivatedAt: new DateTimeImmutable('2025-01-10'));
        $newer = $this->createTheme(id: 'new', isActive: false, deactivatedAt: new DateTimeImmutable('2025-01-15'));

        $this->repo->method('findAll')->willReturn([$older, $newer]);
        $this->repo->method('findById')->willReturn($newer);
        $this->repo->method('findActive')->willReturn(null);

        $result = $this->manager->rollback('admin');

        self::assertTrue($result->isActive);
    }

    // ── getActive / getInstalled ─────────────────────────────────────

    #[Test]
    public function getActiveReturnsNullWhenNoActiveTheme(): void
    {
        $this->repo->method('findActive')->willReturn(null);

        self::assertNull($this->manager->getActive());
    }

    #[Test]
    public function getActiveReturnsActiveTheme(): void
    {
        $theme = $this->createTheme(isActive: true);
        $this->repo->method('findActive')->willReturn($theme);

        $result = $this->manager->getActive();

        self::assertNotNull($result);
        self::assertTrue($result->isActive);
    }

    #[Test]
    public function getInstalledReturnsAllThemes(): void
    {
        $themes = [$this->createTheme(id: 'a'), $this->createTheme(id: 'b')];
        $this->repo->method('findAll')->willReturn($themes);

        $result = $this->manager->getInstalled();

        self::assertCount(2, $result);
    }
}
