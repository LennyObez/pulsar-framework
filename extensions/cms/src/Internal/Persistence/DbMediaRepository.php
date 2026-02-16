<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Media\MediaAsset;
use Pulsar\Extension\Cms\Media\MediaAssetTranslation;
use Pulsar\Extension\Cms\Media\MediaDerivative;
use Pulsar\Extension\Cms\Media\MediaRepositoryInterface;
use Pulsar\Extension\Cms\Media\MediaVisibility;

use function ceil;
use function json_decode;
use function json_encode;
use function max;

use const JSON_THROW_ON_ERROR;

#[Internal(reason: 'Raw-DB repository; use MediaRepositoryInterface for public API')]
final readonly class DbMediaRepository implements MediaRepositoryInterface
{
    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT * FROM cms_media_assets WHERE id = :id AND deleted_at IS NULL
        SQL;

    private const string SQL_FIND_BY_HASH = <<<'SQL'
        SELECT * FROM cms_media_assets WHERE file_hash = :hash AND deleted_at IS NULL LIMIT 1
        SQL;

    private const string SQL_COUNT_ASSETS = <<<'SQL'
        SELECT COUNT(*) AS total FROM cms_media_assets WHERE deleted_at IS NULL
        SQL;

    private const string SQL_LIST_ASSETS = <<<'SQL'
        SELECT * FROM cms_media_assets WHERE deleted_at IS NULL
        SQL;

    private const array UPSERT_ASSET_COLUMNS = [
        'id', 'tenant_id', 'uploader_id', 'filename', 'storage_path', 'disk',
        'mime_type', 'file_size', 'file_hash', 'width', 'height', 'exif_data',
        'alt_text_default', 'visibility', 'data_classification',
        'created_at', 'updated_at', 'deleted_at',
    ];

    private const array UPSERT_ASSET_UPDATE = [
        'filename', 'storage_path', 'mime_type', 'file_size',
        'alt_text_default', 'visibility', 'data_classification',
        'updated_at', 'deleted_at',
    ];

    private const string SQL_SOFT_DELETE_ASSET = <<<'SQL'
        UPDATE cms_media_assets
        SET deleted_at = :deleted_at, updated_at = :updated_at
        WHERE id = :id
        SQL;

    private const string SQL_FIND_DERIVATIVES = <<<'SQL'
        SELECT * FROM cms_media_derivatives WHERE media_asset_id = :asset_id ORDER BY variant, format
        SQL;

    private const array UPSERT_DERIVATIVE_COLUMNS = [
        'id', 'media_asset_id', 'variant', 'format', 'storage_path',
        'file_size', 'width', 'height', 'file_hash', 'created_at',
    ];

    private const array UPSERT_DERIVATIVE_UPDATE = [
        'storage_path', 'file_size', 'width', 'height', 'file_hash', 'created_at',
    ];

    private const array UPSERT_TRANSLATION_COLUMNS = ['media_asset_id', 'locale', 'alt_text', 'caption', 'title'];
    private const array UPSERT_TRANSLATION_UPDATE = ['alt_text', 'caption', 'title'];

    private const string SQL_FIND_TRANSLATIONS = <<<'SQL'
        SELECT * FROM cms_media_asset_translations WHERE media_asset_id = :asset_id ORDER BY locale
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function findById(string $id): ?MediaAsset
    {
        $result = $this->connection->query(self::SQL_FIND_BY_ID, ['id' => $id]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrateAsset($row);
    }

    public function findByHash(string $hash): ?MediaAsset
    {
        $result = $this->connection->query(self::SQL_FIND_BY_HASH, ['hash' => $hash]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrateAsset($row);
    }

    public function listAssets(
        ?string $tenantId,
        int $page,
        int $perPage,
        ?string $mimeType = null,
        ?string $visibility = null,
    ): PaginationResult {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $bindings = [];

        $countSql = self::SQL_COUNT_ASSETS;
        $selectSql = self::SQL_LIST_ASSETS;

        if ($tenantId !== null) {
            $tenantFilter = ' AND tenant_id = :tenant_id';
            $countSql .= $tenantFilter;
            $selectSql .= $tenantFilter;
            $bindings['tenant_id'] = $tenantId;
        }

        if ($mimeType !== null) {
            $mimeFilter = ' AND mime_type = :mime_type';
            $countSql .= $mimeFilter;
            $selectSql .= $mimeFilter;
            $bindings['mime_type'] = $mimeType;
        }

        if ($visibility !== null) {
            $visibilityFilter = ' AND visibility = :visibility';
            $countSql .= $visibilityFilter;
            $selectSql .= $visibilityFilter;
            $bindings['visibility'] = $visibility;
        }

        $countResult = $this->connection->query($countSql, $bindings);
        $total = $countResult->first()?->getInt('total') ?? 0;

        $selectSql .= ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset';
        $bindings['limit'] = $perPage;
        $bindings['offset'] = $offset;

        $dataResult = $this->connection->query($selectSql, $bindings);
        $items = $dataResult->map(self::hydrateAsset(...));
        $lastPage = $total > 0 ? (int) ceil($total / $perPage) : 1;

        return new PaginationResult(
            items: $items,
            total: $total,
            hasMore: $page < $lastPage,
            perPage: $perPage,
            currentPage: $page,
            lastPage: $lastPage,
        );
    }

    public function save(MediaAsset $asset): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'cms_media_assets',
            self::UPSERT_ASSET_COLUMNS,
            ['id'],
            self::UPSERT_ASSET_UPDATE,
        );

        $this->connection->execute($sql, [
            'id' => $asset->id,
            'tenant_id' => $asset->tenantId,
            'uploader_id' => $asset->uploaderId,
            'filename' => $asset->filename,
            'storage_path' => $asset->storagePath,
            'disk' => $asset->disk,
            'mime_type' => $asset->mimeType,
            'file_size' => $asset->fileSize,
            'file_hash' => $asset->fileHash,
            'width' => $asset->width,
            'height' => $asset->height,
            'exif_data' => $asset->exifData !== null ? json_encode($asset->exifData, JSON_THROW_ON_ERROR) : null,
            'alt_text_default' => $asset->altTextDefault,
            'visibility' => $asset->visibility->value,
            'data_classification' => $asset->dataClassification->value,
            'created_at' => $asset->createdAt->format('c'),
            'updated_at' => $asset->updatedAt->format('c'),
            'deleted_at' => $asset->deletedAt?->format('c'),
        ]);
    }

    public function delete(MediaAsset $asset): void
    {
        $now = new DateTimeImmutable();

        $this->connection->execute(self::SQL_SOFT_DELETE_ASSET, [
            'id' => $asset->id,
            'deleted_at' => $now->format('c'),
            'updated_at' => $now->format('c'),
        ]);
    }

    public function findDerivatives(string $assetId): array
    {
        $result = $this->connection->query(self::SQL_FIND_DERIVATIVES, ['asset_id' => $assetId]);

        return $result->map(self::hydrateDerivative(...));
    }

    public function saveDerivative(MediaDerivative $derivative): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'cms_media_derivatives',
            self::UPSERT_DERIVATIVE_COLUMNS,
            ['media_asset_id', 'variant', 'format'],
            self::UPSERT_DERIVATIVE_UPDATE,
        );

        $this->connection->execute($sql, [
            'id' => $derivative->id,
            'media_asset_id' => $derivative->mediaAssetId,
            'variant' => $derivative->variant,
            'format' => $derivative->format,
            'storage_path' => $derivative->storagePath,
            'file_size' => $derivative->fileSize,
            'width' => $derivative->width,
            'height' => $derivative->height,
            'file_hash' => $derivative->fileHash,
            'created_at' => $derivative->createdAt->format('c'),
        ]);
    }

    public function saveTranslation(MediaAssetTranslation $translation): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'cms_media_asset_translations',
            self::UPSERT_TRANSLATION_COLUMNS,
            ['media_asset_id', 'locale'],
            self::UPSERT_TRANSLATION_UPDATE,
        );

        $this->connection->execute($sql, [
            'media_asset_id' => $translation->mediaAssetId,
            'locale' => $translation->locale,
            'alt_text' => $translation->altText,
            'caption' => $translation->caption,
            'title' => $translation->title,
        ]);
    }

    public function findTranslations(string $assetId): array
    {
        $result = $this->connection->query(self::SQL_FIND_TRANSLATIONS, ['asset_id' => $assetId]);

        return $result->map(self::hydrateTranslation(...));
    }

    private static function hydrateAsset(Row $row): MediaAsset
    {
        $exifJson = $row->getNullableString('exif_data');

        /** @var array<string, mixed>|null $exifData */
        $exifData = $exifJson !== null ? json_decode($exifJson, true, flags: JSON_THROW_ON_ERROR) : null;

        return new MediaAsset(
            id: $row->getString('id'),
            tenantId: $row->getNullableString('tenant_id'),
            uploaderId: $row->getString('uploader_id'),
            filename: $row->getString('filename'),
            storagePath: $row->getString('storage_path'),
            disk: $row->getString('disk'),
            mimeType: $row->getString('mime_type'),
            fileSize: $row->getInt('file_size'),
            fileHash: $row->getString('file_hash'),
            width: $row->getNullableInt('width'),
            height: $row->getNullableInt('height'),
            exifData: $exifData,
            altTextDefault: $row->getNullableString('alt_text_default'),
            visibility: MediaVisibility::from($row->getString('visibility')),
            dataClassification: DataClassification::from($row->getString('data_classification')),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
            updatedAt: new DateTimeImmutable($row->getString('updated_at')),
            deletedAt: self::toDateTime($row->getNullableString('deleted_at')),
        );
    }

    private static function hydrateDerivative(Row $row): MediaDerivative
    {
        return new MediaDerivative(
            id: $row->getString('id'),
            mediaAssetId: $row->getString('media_asset_id'),
            variant: $row->getString('variant'),
            format: $row->getString('format'),
            storagePath: $row->getString('storage_path'),
            fileSize: $row->getInt('file_size'),
            width: $row->getInt('width'),
            height: $row->getInt('height'),
            fileHash: $row->getString('file_hash'),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
        );
    }

    private static function hydrateTranslation(Row $row): MediaAssetTranslation
    {
        return new MediaAssetTranslation(
            mediaAssetId: $row->getString('media_asset_id'),
            locale: $row->getString('locale'),
            altText: $row->getNullableString('alt_text'),
            caption: $row->getNullableString('caption'),
            title: $row->getNullableString('title'),
        );
    }

    private static function toDateTime(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        return new DateTimeImmutable($value);
    }
}
