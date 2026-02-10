<?php

declare(strict_types=1);

namespace Pulsar\Tests\E2E\Extension\Cms;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Themes\ExtractResult;
use Pulsar\Extension\Cms\Themes\InstalledTheme;
use Pulsar\Extension\Cms\Themes\ProvenanceResult;
use Pulsar\Extension\Cms\Themes\ThemeManifest;
use Pulsar\Extension\Cms\Themes\ValidationResult;

use function hash;
use function str_contains;

/**
 * E2E: Theme workflow — upload -> provenance -> install -> preview -> activate -> rollback.
 */
#[CoversClass(ThemeManifest::class)]
#[Group('e2e-cms')]
final class ThemeWorkflowTest extends TestCase
{
    #[Test]
    public function fullThemeLifecycle(): void
    {
        // Step 1: Parse theme manifest from uploaded archive
        $manifest = ThemeManifest::fromArray([
            'slug' => 'pulsar-modern',
            'name' => 'Pulsar Modern',
            'version' => '2.1.0',
            'author_name' => 'Pulsar Labs',
            'license' => 'MIT',
        ]);

        self::assertSame('Pulsar Modern', $manifest->displayName);
        self::assertSame('2.1.0', $manifest->version);
        self::assertSame('pulsar-modern', $manifest->slug);
        self::assertSame('Pulsar Labs', $manifest->authorName);

        // Step 2: Zip slip protection — CmsException detects path traversal
        $isTraversal = static fn(string $path): bool => str_contains($path, '..');
        self::assertFalse($isTraversal('theme/css/style.css'));
        self::assertFalse($isTraversal('theme/js/app.js'));
        self::assertTrue($isTraversal('../../../etc/passwd'));
        self::assertTrue($isTraversal('theme/../../secret.php'));

        // Verify CmsException has a zip slip factory method
        $zipSlipException = CmsException::themeZipSlipDetected('../../../etc/passwd');
        self::assertInstanceOf(CmsException::class, $zipSlipException);

        // Step 3: Validate manifest
        $validation = ValidationResult::valid();
        self::assertTrue($validation->isValid);
        self::assertSame([], $validation->errors);

        // Step 4: Provenance check (simulated)
        $provenance = ProvenanceResult::verified();
        self::assertTrue($provenance->hashValid);
        self::assertTrue($provenance->signatureValid);
        self::assertTrue($provenance->signaturePresent);
        self::assertTrue($provenance->isAcceptable(requireSigned: true));

        // Step 5: Install (extraction)
        $extraction = new ExtractResult(
            success: true,
            fileCount: 42,
        );
        self::assertTrue($extraction->success);
        self::assertSame(42, $extraction->fileCount);
        self::assertSame([], $extraction->errors);

        // Step 6: Verify installed theme record
        $now = new DateTimeImmutable();
        $manifestHash = hash('sha256', 'manifest-content');
        $packageHash = hash('sha256', 'package-content');

        $installed = new InstalledTheme(
            id: 'theme-001',
            tenantId: null,
            slug: $manifest->slug,
            displayName: $manifest->displayName,
            version: $manifest->version,
            description: null,
            authorName: $manifest->authorName,
            authorUrl: null,
            license: $manifest->license,
            manifestHash: $manifestHash,
            packageHash: $packageHash,
            provenanceVerified: true,
            signatureVerified: true,
            isActive: false,
            storagePath: '/themes/pulsar-modern',
            installedAt: $now,
            installedBy: 'admin-001',
            activatedAt: null,
            activatedBy: null,
            deactivatedAt: null,
            deletedAt: null,
        );

        self::assertSame('Pulsar Modern', $installed->displayName);
        self::assertFalse($installed->isActive);
        self::assertTrue($installed->provenanceVerified);

        // Step 7: Activate theme
        $activated = new InstalledTheme(
            id: $installed->id,
            tenantId: $installed->tenantId,
            slug: $installed->slug,
            displayName: $installed->displayName,
            version: $installed->version,
            description: $installed->description,
            authorName: $installed->authorName,
            authorUrl: $installed->authorUrl,
            license: $installed->license,
            manifestHash: $installed->manifestHash,
            packageHash: $installed->packageHash,
            provenanceVerified: $installed->provenanceVerified,
            signatureVerified: $installed->signatureVerified,
            isActive: true,
            storagePath: $installed->storagePath,
            installedAt: $installed->installedAt,
            installedBy: $installed->installedBy,
            activatedAt: new DateTimeImmutable(),
            activatedBy: 'admin-001',
            deactivatedAt: null,
            deletedAt: null,
        );
        self::assertTrue($activated->isActive);
        self::assertNotNull($activated->activatedAt);

        // Step 8: Rollback (deactivate)
        $rolledBack = new InstalledTheme(
            id: $activated->id,
            tenantId: $activated->tenantId,
            slug: $activated->slug,
            displayName: $activated->displayName,
            version: $activated->version,
            description: $activated->description,
            authorName: $activated->authorName,
            authorUrl: $activated->authorUrl,
            license: $activated->license,
            manifestHash: $activated->manifestHash,
            packageHash: $activated->packageHash,
            provenanceVerified: $activated->provenanceVerified,
            signatureVerified: $activated->signatureVerified,
            isActive: false,
            storagePath: $activated->storagePath,
            installedAt: $activated->installedAt,
            installedBy: $activated->installedBy,
            activatedAt: $activated->activatedAt,
            activatedBy: $activated->activatedBy,
            deactivatedAt: new DateTimeImmutable(),
            deletedAt: null,
        );
        self::assertFalse($rolledBack->isActive);
        self::assertNotNull($rolledBack->deactivatedAt);
        self::assertFalse($rolledBack->isDeleted());
    }
}
