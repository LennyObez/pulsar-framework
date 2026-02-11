<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Tools;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Tools\Backup;
use Pulsar\Extension\Cms\Tools\BackupScope;
use Pulsar\Extension\Cms\Tools\ExportOptions;
use Pulsar\Extension\Cms\Tools\ImportConfig;
use Pulsar\Extension\Cms\Tools\ImportResult;
use Pulsar\Extension\Cms\Tools\RestoreResult;
use Pulsar\Extension\Cms\Tools\SiteDefinition;

use function assert;
use function is_array;

#[CoversClass(Backup::class)]
#[CoversClass(BackupScope::class)]
#[CoversClass(ExportOptions::class)]
#[CoversClass(ImportConfig::class)]
#[CoversClass(ImportResult::class)]
#[CoversClass(RestoreResult::class)]
#[CoversClass(SiteDefinition::class)]
final class ToolsEntitiesTest extends TestCase
{
    // -- Backup ---------------------------------------------------------------

    #[Test]
    public function backupConstructor(): void
    {
        $now = new DateTimeImmutable('2025-03-07T09:00:00+00:00');
        $scope = new BackupScope();

        $backup = new Backup(
            id: 'backup-01',
            scope: $scope,
            storagePath: 'backups/2025/03/backup-01.zip',
            hash: 'blake2b-hash-value',
            size: 52_428_800,
            createdAt: $now,
            createdBy: 'user-admin',
        );

        self::assertSame('backup-01', $backup->id);
        self::assertSame($scope, $backup->scope);
        self::assertSame('backups/2025/03/backup-01.zip', $backup->storagePath);
        self::assertSame('blake2b-hash-value', $backup->hash);
        self::assertSame(52_428_800, $backup->size);
        self::assertSame('user-admin', $backup->createdBy);
    }

    // -- BackupScope ----------------------------------------------------------

    #[Test]
    public function backupScopeDefaults(): void
    {
        $scope = new BackupScope();

        self::assertTrue($scope->includeContent);
        self::assertFalse($scope->includeMedia);
        self::assertTrue($scope->includeTaxonomies);
        self::assertTrue($scope->includeMenus);
        self::assertTrue($scope->includeSettings);
        self::assertTrue($scope->includeCommerce);
        self::assertNull($scope->tenantId);
    }

    #[Test]
    public function backupScopeFromArray(): void
    {
        $scope = BackupScope::fromArray([
            'include_content' => false,
            'include_media' => true,
            'include_taxonomies' => false,
            'tenant_id' => 'tenant-42',
        ]);

        self::assertFalse($scope->includeContent);
        self::assertTrue($scope->includeMedia);
        self::assertFalse($scope->includeTaxonomies);
        self::assertSame('tenant-42', $scope->tenantId);
    }

    #[Test]
    public function backupScopeFromEmptyArray(): void
    {
        $scope = BackupScope::fromArray([]);

        self::assertTrue($scope->includeContent);
        self::assertFalse($scope->includeMedia);
    }

    #[Test]
    public function backupScopeToArray(): void
    {
        $scope = new BackupScope(includeMedia: true, tenantId: 'tenant-01');

        $array = $scope->toArray();

        self::assertTrue($array['include_content']);
        self::assertTrue($array['include_media']);
        self::assertSame('tenant-01', $array['tenant_id']);
    }

    // -- ExportOptions --------------------------------------------------------

    #[Test]
    public function exportOptionsConstructor(): void
    {
        $options = new ExportOptions(
            scope: ['content', 'taxonomies'],
            locales: ['en', 'fr'],
            includePii: true,
            tenantId: 'tenant-01',
        );

        self::assertSame(['content', 'taxonomies'], $options->scope);
        self::assertSame(['en', 'fr'], $options->locales);
        self::assertTrue($options->includePii);
        self::assertSame('tenant-01', $options->tenantId);
    }

    #[Test]
    public function exportOptionsFromArrayValid(): void
    {
        $options = ExportOptions::fromArray([
            'scope' => ['content', 'menus'],
            'locales' => ['en'],
            'include_pii' => true,
            'tenant_id' => 'tenant-02',
        ]);

        self::assertSame(['content', 'menus'], $options->scope);
        self::assertSame(['en'], $options->locales);
        self::assertTrue($options->includePii);
    }

    #[Test]
    public function exportOptionsFromArrayInvalidScope(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid export scope');

        ExportOptions::fromArray(['scope' => ['invalid_type']]);
    }

    #[Test]
    public function exportOptionsFromArrayEmptyScope(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ExportOptions::fromArray(['scope' => []]);
    }

    #[Test]
    public function exportOptionsToArray(): void
    {
        $options = new ExportOptions(
            scope: ['content'],
            locales: null,
            includePii: false,
        );

        $array = $options->toArray();

        self::assertSame(['content'], $array['scope']);
        self::assertNull($array['locales']);
        self::assertFalse($array['include_pii']);
    }

    // -- ImportConfig ---------------------------------------------------------

    #[Test]
    public function importConfigDefaults(): void
    {
        $config = new ImportConfig();

        self::assertSame(50 * 1024 * 1024, $config->maxImportSizeBytes);
        self::assertTrue($config->allowExternalMediaDownload);
        self::assertTrue($config->dryRunDefault);
    }

    #[Test]
    public function importConfigFromArray(): void
    {
        $config = ImportConfig::fromArray([
            'max_import_size_bytes' => 100_000_000,
            'allow_external_media_download' => false,
            'dry_run_default' => false,
        ]);

        self::assertSame(100_000_000, $config->maxImportSizeBytes);
        self::assertFalse($config->allowExternalMediaDownload);
        self::assertFalse($config->dryRunDefault);
    }

    #[Test]
    public function importConfigFromEmptyArray(): void
    {
        $config = ImportConfig::fromArray([]);

        self::assertSame(50 * 1024 * 1024, $config->maxImportSizeBytes);
        self::assertTrue($config->allowExternalMediaDownload);
        self::assertTrue($config->dryRunDefault);
    }

    // -- ImportResult ---------------------------------------------------------

    #[Test]
    public function importResultToArray(): void
    {
        $result = new ImportResult(
            created: ['content' => 10, 'taxonomies' => 5],
            updated: ['content' => 3],
            skipped: ['menus' => 1],
            warnings: ['Duplicate slug detected'],
            errors: [],
            dryRun: false,
        );

        $array = $result->toArray();

        $created = $array['created'];
        assert(is_array($created));
        self::assertSame(10, $created['content']);
        $updated = $array['updated'];
        assert(is_array($updated));
        self::assertSame(3, $updated['content']);
        $skipped = $array['skipped'];
        assert(is_array($skipped));
        self::assertSame(1, $skipped['menus']);
        $warnings = $array['warnings'];
        assert(is_array($warnings));
        self::assertCount(1, $warnings);
        self::assertSame([], $array['errors']);
        self::assertFalse($array['dry_run']);
    }

    // -- RestoreResult --------------------------------------------------------

    #[Test]
    public function restoreResultConstructor(): void
    {
        $result = new RestoreResult(
            restoredCounts: ['content' => 100, 'media' => 50],
            warnings: ['Some media files missing from storage'],
        );

        self::assertSame(100, $result->restoredCounts['content']);
        self::assertSame(50, $result->restoredCounts['media']);
        self::assertCount(1, $result->warnings);
    }

    // -- SiteDefinition -------------------------------------------------------

    #[Test]
    public function siteDefinitionFromJsonValid(): void
    {
        $json = json_encode([
            'version' => '1.0',
            'site' => ['name' => 'My CMS Site', 'locales' => ['en', 'fr']],
            'taxonomies' => [['slug' => 'category', 'name' => 'Categories']],
            'content' => [['title' => 'Home']],
            'menus' => [],
            'media' => [],
            'redirects' => [],
            'seo' => ['robots' => 'index, follow'],
        ], JSON_THROW_ON_ERROR);

        $def = SiteDefinition::fromJson($json);

        self::assertSame('My CMS Site', $def->site['name']);
        self::assertCount(1, $def->taxonomies);
        self::assertCount(1, $def->content);
        self::assertSame([], $def->menus);
        self::assertSame('index, follow', $def->seo['robots']);
    }

    #[Test]
    public function siteDefinitionFromJsonInvalidJson(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid JSON');

        SiteDefinition::fromJson('{invalid}');
    }

    #[Test]
    public function siteDefinitionFromJsonMissingRequiredKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required keys');

        SiteDefinition::fromJson(json_encode(['version' => '1.0'], JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function siteDefinitionFromJsonInvalidVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported site definition version');

        SiteDefinition::fromJson(json_encode([
            'version' => '2.0',
            'site' => ['name' => 'Test'],
        ], JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function siteDefinitionFromJsonSiteNotArray(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"site" key must be an object');

        SiteDefinition::fromJson(json_encode([
            'version' => '1.0',
            'site' => 'not-an-object',
        ], JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function siteDefinitionFromJsonMissingOptionalKeys(): void
    {
        $json = json_encode([
            'version' => '1.0',
            'site' => ['name' => 'Minimal'],
        ], JSON_THROW_ON_ERROR);

        $def = SiteDefinition::fromJson($json);

        self::assertSame([], $def->taxonomies);
        self::assertSame([], $def->content);
        self::assertSame([], $def->menus);
        self::assertSame([], $def->media);
        self::assertSame([], $def->redirects);
        self::assertSame([], $def->seo);
    }
}
