<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Themes;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Themes\Event\ThemeActivated;
use Pulsar\Extension\Cms\Themes\Event\ThemeDeleted;
use Pulsar\Extension\Cms\Themes\Event\ThemeInstalled;
use Pulsar\Extension\Cms\Themes\ExtractResult;
use Pulsar\Extension\Cms\Themes\PreviewSession;
use Pulsar\Extension\Cms\Themes\ProvenanceResult;
use Pulsar\Extension\Cms\Themes\ThemeManifest;
use Pulsar\Extension\Cms\Themes\ValidationResult;

#[CoversClass(ExtractResult::class)]
#[CoversClass(PreviewSession::class)]
#[CoversClass(ProvenanceResult::class)]
#[CoversClass(ThemeManifest::class)]
#[CoversClass(ValidationResult::class)]
#[CoversClass(ThemeActivated::class)]
#[CoversClass(ThemeDeleted::class)]
#[CoversClass(ThemeInstalled::class)]
final class ThemeEntitiesTest extends TestCase
{
    // -- ExtractResult --------------------------------------------------------

    #[Test]
    public function extractResultSuccess(): void
    {
        $result = new ExtractResult(
            success: true,
            fileCount: 42,
        );

        self::assertTrue($result->success);
        self::assertSame(42, $result->fileCount);
        self::assertSame([], $result->errors);
        self::assertSame([], $result->warnings);
    }

    #[Test]
    public function extractResultWithErrorsAndWarnings(): void
    {
        $result = new ExtractResult(
            success: false,
            fileCount: 0,
            errors: ['Failed to extract archive'],
            warnings: ['Large file detected'],
        );

        self::assertFalse($result->success);
        self::assertCount(1, $result->errors);
        self::assertCount(1, $result->warnings);
    }

    // -- PreviewSession -------------------------------------------------------

    #[Test]
    public function previewSessionNotExpiredWhenFuture(): void
    {
        $session = new PreviewSession(
            themeId: 'theme-01',
            token: bin2hex(random_bytes(16)),
            userId: 'user-42',
            expiresAt: new DateTimeImmutable('+1 hour'),
        );

        self::assertFalse($session->isExpired());
        self::assertSame('theme-01', $session->themeId);
        self::assertSame('user-42', $session->userId);
    }

    #[Test]
    public function previewSessionExpiredWhenPast(): void
    {
        $session = new PreviewSession(
            themeId: 'theme-02',
            token: bin2hex(random_bytes(16)),
            userId: 'user-01',
            expiresAt: new DateTimeImmutable('-1 minute'),
        );

        self::assertTrue($session->isExpired());
    }

    // -- ProvenanceResult -----------------------------------------------------

    #[Test]
    public function provenanceResultVerified(): void
    {
        $result = ProvenanceResult::verified();

        self::assertTrue($result->hashValid);
        self::assertTrue($result->signatureValid);
        self::assertTrue($result->signaturePresent);
        self::assertNull($result->error);
        self::assertTrue($result->isAcceptable(requireSigned: true));
        self::assertTrue($result->isAcceptable(requireSigned: false));
    }

    #[Test]
    public function provenanceResultUnsigned(): void
    {
        $result = ProvenanceResult::unsigned();

        self::assertTrue($result->hashValid);
        self::assertFalse($result->signatureValid);
        self::assertFalse($result->signaturePresent);
        self::assertNull($result->error);
        self::assertFalse($result->isAcceptable(requireSigned: true));
        self::assertTrue($result->isAcceptable(requireSigned: false));
    }

    #[Test]
    public function provenanceResultFailed(): void
    {
        $result = ProvenanceResult::failed('hash mismatch');

        self::assertFalse($result->hashValid);
        self::assertFalse($result->signatureValid);
        self::assertFalse($result->signaturePresent);
        self::assertSame('hash mismatch', $result->error);
        self::assertFalse($result->isAcceptable(requireSigned: true));
        self::assertFalse($result->isAcceptable(requireSigned: false));
    }

    // -- ThemeManifest --------------------------------------------------------

    #[Test]
    public function themeManifestConstructor(): void
    {
        $manifest = new ThemeManifest(
            slug: 'aurora',
            displayName: 'Aurora Theme',
            version: '2.1.0',
            description: 'A modern theme for content-heavy sites',
            authorName: 'Pulsar Labs',
            authorUrl: 'https://pulsar.dev',
            license: 'MIT',
            pulsarVersionConstraint: '>=1.0.0',
            parentTheme: null,
            regions: ['header', 'sidebar', 'footer'],
            supportedContentTypes: ['article', 'page'],
            settings: ['primaryColor' => '#1a73e8'],
            assets: ['css' => 'assets/main.css', 'js' => 'assets/main.js'],
        );

        self::assertSame('aurora', $manifest->slug);
        self::assertSame('Aurora Theme', $manifest->displayName);
        self::assertSame('2.1.0', $manifest->version);
        self::assertSame('MIT', $manifest->license);
        self::assertCount(3, $manifest->regions);
        self::assertSame('#1a73e8', $manifest->settings['primaryColor']);
    }

    #[Test]
    public function themeManifestFromArrayWithFullData(): void
    {
        $manifest = ThemeManifest::fromArray([
            'slug' => 'midnight',
            'display_name' => 'Midnight Dark',
            'version' => '1.0.0',
            'description' => 'A dark theme',
            'author_name' => 'Dark Labs',
            'author_url' => 'https://darklabs.io',
            'license' => 'Apache-2.0',
            'pulsar_version' => '>=1.0.0',
            'parent_theme' => 'base',
            'regions' => ['header', 'content', 'footer'],
            'supported_content_types' => ['article'],
            'settings' => ['darkMode' => true],
            'assets' => ['css' => 'dark.css'],
        ]);

        self::assertSame('midnight', $manifest->slug);
        self::assertSame('Midnight Dark', $manifest->displayName);
        self::assertSame('Apache-2.0', $manifest->license);
        self::assertSame('base', $manifest->parentTheme);
        self::assertCount(3, $manifest->regions);
    }

    #[Test]
    public function themeManifestFromArrayFallsBackToName(): void
    {
        $manifest = ThemeManifest::fromArray([
            'slug' => 'simple',
            'name' => 'Simple Theme',
            'version' => '1.0.0',
        ]);

        self::assertSame('Simple Theme', $manifest->displayName);
    }

    #[Test]
    public function themeManifestFromArrayWithEmptyData(): void
    {
        $manifest = ThemeManifest::fromArray([]);

        self::assertSame('', $manifest->slug);
        self::assertSame('', $manifest->displayName);
        self::assertSame('0.0.0', $manifest->version);
        self::assertNull($manifest->description);
        self::assertNull($manifest->authorName);
        self::assertNull($manifest->parentTheme);
        self::assertSame([], $manifest->regions);
    }

    // -- ValidationResult -----------------------------------------------------

    #[Test]
    public function validationResultValid(): void
    {
        $result = ValidationResult::valid();

        self::assertTrue($result->isValid);
        self::assertSame([], $result->errors);
        self::assertSame([], $result->warnings);
    }

    #[Test]
    public function validationResultValidWithWarnings(): void
    {
        $result = ValidationResult::valid(['Deprecated feature used']);

        self::assertTrue($result->isValid);
        self::assertSame([], $result->errors);
        self::assertCount(1, $result->warnings);
    }

    #[Test]
    public function validationResultInvalid(): void
    {
        $result = ValidationResult::invalid(['Missing slug'], ['No license specified']);

        self::assertFalse($result->isValid);
        self::assertCount(1, $result->errors);
        self::assertCount(1, $result->warnings);
    }

    // -- Theme Events ---------------------------------------------------------

    #[Test]
    public function themeActivatedEvent(): void
    {
        $event = new ThemeActivated(
            themeId: 'theme-01',
            activatedBy: 'user-admin',
        );

        self::assertSame('theme-01', $event->themeId);
        self::assertSame('user-admin', $event->activatedBy);
    }

    #[Test]
    public function themeDeletedEvent(): void
    {
        $event = new ThemeDeleted(
            themeId: 'theme-02',
            deletedBy: 'user-01',
            reason: 'Replaced by new theme',
        );

        self::assertSame('theme-02', $event->themeId);
        self::assertSame('user-01', $event->deletedBy);
        self::assertSame('Replaced by new theme', $event->reason);
    }

    #[Test]
    public function themeInstalledEvent(): void
    {
        $event = new ThemeInstalled(
            themeId: 'theme-03',
            name: 'Corporate Theme',
            version: '3.2.1',
            installedBy: 'user-01',
        );

        self::assertSame('theme-03', $event->themeId);
        self::assertSame('Corporate Theme', $event->name);
        self::assertSame('3.2.1', $event->version);
        self::assertSame('user-01', $event->installedBy);
    }
}
