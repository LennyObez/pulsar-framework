<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\ImportExport;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Tools\ExportOptions;
use Pulsar\Extension\Cms\Tools\ImportExportServiceInterface;
use Pulsar\ImportExport\ExportRequest;
use Pulsar\ImportExport\ExportResult;
use Pulsar\ImportExport\ImportExportProviderInterface;
use Pulsar\ImportExport\ImportRequest;
use Pulsar\ImportExport\ImportResult;

use function array_values;

/**
 * CMS import/export provider.
 *
 * Delegates to the existing CMS ImportExportServiceInterface for
 * content, taxonomies, menus, settings, media, comments, users,
 * and configuration data.
 */
#[Internal(reason: 'Wired in CmsExtension::postBoot()')]
final readonly class CmsImportExportProvider implements ImportExportProviderInterface
{
    private const array ENTITY_TYPE_MAP = [
        'content' => 'content',
        'taxonomies' => 'taxonomies',
        'menus' => 'menus',
        'settings' => 'settings',
        'media' => 'media_refs',
        'comments' => 'comments',
        'users' => 'users',
        'media_files' => 'media_files',
        'configuration' => 'configuration',
    ];

    public function __construct(
        private ImportExportServiceInterface $importExportService,
    ) {}

    #[Override]
    public function name(): string
    {
        return 'cms';
    }

    #[Override]
    public function label(): string
    {
        return 'Content Management';
    }

    #[Override]
    public function supportedFormats(): array
    {
        return ['json'];
    }

    #[Override]
    public function export(ExportRequest $request): ExportResult
    {
        $scope = $request->entityTypes !== []
            ? array_values(array_filter(
                array_map(
                    fn(string $type) => self::ENTITY_TYPE_MAP[$type] ?? null,
                    $request->entityTypes,
                ),
                fn(?string $v) => $v !== null,
            ))
            : array_values(self::ENTITY_TYPE_MAP);

        if ($scope === []) {
            $scope = array_values(self::ENTITY_TYPE_MAP);
        }

        $options = new ExportOptions(
            scope: $scope,
            includePii: $request->includePii,
        );

        $bundle = $this->importExportService->exportBundle($options);

        return new ExportResult(
            providerName: 'cms',
            data: $bundle->data,
            format: $request->format,
            evidenceHash: $bundle->evidenceHash,
            entityTypes: $bundle->scope,
            createdAt: $bundle->createdAt,
        );
    }

    #[Override]
    public function import(ImportRequest $request): ImportResult
    {
        // Use importUnifiedFile which auto-detects the format:
        // - Site definition format (version + site keys) -> SiteDefinitionParser (handles forum delegation)
        // - Bundle format -> ImportParser
        $cmsResult = $this->importExportService->importUnifiedFile(
            $request->content,
            $request->dryRun,
        );

        return new ImportResult(
            providerName: 'cms',
            created: $cmsResult->created,
            updated: $cmsResult->updated,
            skipped: $cmsResult->skipped,
            warnings: $cmsResult->warnings,
            errors: $cmsResult->errors,
            dryRun: $cmsResult->dryRun,
        );
    }

    #[Override]
    public function schema(): array
    {
        return [
            'content' => [
                'id' => 'string (UUIDv7)',
                'title' => 'string',
                'slug' => 'string',
                'body' => 'string (HTML or block JSON)',
                'type' => 'string (page, article, doc)',
                'status' => 'string (draft, published, archived)',
                'author_id' => 'string (UUIDv7)',
                'seo_title' => 'string',
                'seo_description' => 'string',
                'og_image' => 'string (URL)',
                'created_at' => 'string (ISO 8601)',
                'updated_at' => 'string (ISO 8601)',
            ],
            'taxonomies' => [
                'id' => 'string (UUIDv7)',
                'name' => 'string',
                'slug' => 'string',
                'type' => 'string (category, tag)',
                'parent_id' => 'string|null (UUIDv7)',
            ],
            'menus' => [
                'id' => 'string (UUIDv7)',
                'name' => 'string',
                'location' => 'string',
                'items' => 'array<MenuItem>',
            ],
            'settings' => [
                'key' => 'string',
                'value' => 'mixed',
                'group' => 'string',
            ],
            'media' => [
                'id' => 'string (UUIDv7)',
                'filename' => 'string',
                'mime_type' => 'string',
                'path' => 'string',
                'alt' => 'string',
                'title' => 'string',
                'caption' => 'string',
                'description' => 'string',
                'size_bytes' => 'int',
            ],
            'comments' => [
                'id' => 'string (UUIDv7)',
                'content_id' => 'string (UUIDv7)',
                'author_name' => 'string',
                'body' => 'string',
                'status' => 'string (approved, pending, spam)',
                'created_at' => 'string (ISO 8601)',
            ],
            'users' => [
                'id' => 'string (UUIDv7)',
                'email' => 'string',
                'name' => 'string',
                'role' => 'string',
                'password_hash' => 'string (bcrypt/argon2id)',
            ],
            'configuration' => [
                'key' => 'string',
                'value' => 'mixed',
            ],
        ];
    }
}
