<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Commerce\DigitalAsset;
use Pulsar\Extension\Cms\Commerce\DigitalAssetRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\DigitalDownload;

/**
 * Database-backed digital asset and download entitlement repository.
 *
 * @psalm-api Bound to DigitalAssetRepositoryInterface in the CMS service provider;
 *            resolved from the DI container, never instantiated by name.
 */
#[Internal(reason: 'Use DigitalAssetRepositoryInterface for public API')]
final readonly class DbDigitalAssetRepository implements DigitalAssetRepositoryInterface
{
    private const string SQL_FIND_BY_PRODUCT = <<<'SQL'
        SELECT * FROM cms_digital_assets WHERE product_id = :product_id
        SQL;

    private const string SQL_FIND_BY_TOKEN = <<<'SQL'
        SELECT * FROM cms_digital_downloads WHERE download_token = :token LIMIT 1
        SQL;

    private const array UPSERT_ASSET_COLUMNS = [
        'id', 'product_id', 'file_storage_path', 'file_hash', 'file_name', 'file_size', 'max_downloads',
    ];

    private const array UPSERT_ASSET_UPDATE = [
        'file_storage_path', 'file_hash', 'file_name', 'file_size', 'max_downloads',
    ];

    private const string SQL_INSERT_DOWNLOAD = <<<'SQL'
        INSERT INTO cms_digital_downloads (id, order_item_id, digital_asset_id, download_token, downloads_remaining, expires_at)
        VALUES (:id, :order_item_id, :digital_asset_id, :download_token, :downloads_remaining, :expires_at)
        SQL;

    private const string SQL_DECREMENT_DOWNLOADS = <<<'SQL'
        UPDATE cms_digital_downloads
        SET downloads_remaining = downloads_remaining - 1
        WHERE id = :id AND downloads_remaining > 0
        SQL;

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function findByProduct(string $productId): array
    {
        return $this->db->query(self::SQL_FIND_BY_PRODUCT, ['product_id' => $productId])
            ->map(self::hydrateAsset(...));
    }

    public function findByToken(string $token): ?DigitalDownload
    {
        $row = $this->db->query(self::SQL_FIND_BY_TOKEN, ['token' => $token])->first();

        return $row !== null ? self::hydrateDownload($row) : null;
    }

    public function save(DigitalAsset $digitalAsset): void
    {
        $sql = UpsertBuilder::compile(
            $this->db->driver(),
            'cms_digital_assets',
            self::UPSERT_ASSET_COLUMNS,
            ['id'],
            self::UPSERT_ASSET_UPDATE,
        );

        $this->db->execute($sql, [
            'id' => $digitalAsset->id,
            'product_id' => $digitalAsset->productId,
            'file_storage_path' => $digitalAsset->fileStoragePath,
            'file_hash' => $digitalAsset->fileHash,
            'file_name' => $digitalAsset->fileName,
            'file_size' => $digitalAsset->fileSize,
            'max_downloads' => $digitalAsset->maxDownloads,
        ]);
    }

    public function saveDownload(DigitalDownload $download): void
    {
        $this->db->execute(self::SQL_INSERT_DOWNLOAD, [
            'id' => $download->id,
            'order_item_id' => $download->orderItemId,
            'digital_asset_id' => $download->digitalAssetId,
            'download_token' => $download->downloadToken,
            'downloads_remaining' => $download->downloadsRemaining,
            'expires_at' => $download->expiresAt->format('c'),
        ]);
    }

    public function decrementDownloads(string $downloadId): int
    {
        return $this->db->execute(self::SQL_DECREMENT_DOWNLOADS, ['id' => $downloadId]);
    }

    private static function hydrateAsset(Row $row): DigitalAsset
    {
        return new DigitalAsset(
            id: $row->getString('id'),
            productId: $row->getString('product_id'),
            fileStoragePath: $row->getString('file_storage_path'),
            fileHash: $row->getString('file_hash'),
            fileName: $row->getString('file_name'),
            fileSize: $row->getInt('file_size'),
            maxDownloads: $row->getInt('max_downloads'),
        );
    }

    private static function hydrateDownload(Row $row): DigitalDownload
    {
        return new DigitalDownload(
            id: $row->getString('id'),
            orderItemId: $row->getString('order_item_id'),
            digitalAssetId: $row->getString('digital_asset_id'),
            downloadToken: $row->getString('download_token'),
            downloadsRemaining: $row->getInt('downloads_remaining'),
            expiresAt: new DateTimeImmutable($row->getString('expires_at')),
        );
    }
}
