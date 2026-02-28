<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Commerce;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Cms\Commerce\CommerceConfig;
use Pulsar\Extension\Cms\Commerce\DigitalAssetRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\DigitalDeliveryServiceInterface;
use Pulsar\Extension\Cms\Commerce\DigitalDownload;
use Pulsar\Extension\Cms\Commerce\DownloadResult;
use Pulsar\Extension\Cms\Commerce\OrderItemRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\ProductRepositoryInterface;
use Pulsar\Extension\Cms\Internal\Security\CmsKeyManager;
use Pulsar\Extension\Cms\Support\UuidGenerator;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function hash_hmac;
use function sprintf;

/**
 * Manages digital product download entitlements with HMAC-signed tokens.
 */
#[Internal(reason: 'Use DigitalDeliveryServiceInterface for public API')]
final readonly class DigitalDeliveryService implements DigitalDeliveryServiceInterface
{
    public function __construct(
        private DigitalAssetRepositoryInterface $assets,
        private OrderItemRepositoryInterface $orderItems,
        private ProductRepositoryInterface $products,
        private CmsKeyManager $keyManager,
        private ConnectionInterface $db,
        private CommerceConfig $config,
        private ?AuditLoggerInterface $auditLogger = null,
    ) {}

    public function createDownloadTokens(string $orderId): array
    {
        $items = $this->orderItems->findByOrder($orderId);
        $downloads = [];

        foreach ($items as $item) {
            $product = $this->products->findById($item->productId);

            if ($product === null || !$product->isDigital()) {
                continue;
            }

            $digitalAssets = $this->assets->findByProduct($item->productId);

            foreach ($digitalAssets as $asset) {
                $timestamp = new DateTimeImmutable()->format('U');
                $payload = sprintf('%s:%s:%s', $item->id, $asset->id, $timestamp);
                $token = hash_hmac('sha256', $payload, $this->keyManager->downloadKey());

                $download = new DigitalDownload(
                    id: UuidGenerator::v7(),
                    orderItemId: $item->id,
                    digitalAssetId: $asset->id,
                    downloadToken: $token,
                    downloadsRemaining: $asset->maxDownloads,
                    expiresAt: new DateTimeImmutable()->modify(sprintf('+%d days', $this->config->downloadTokenExpiryDays)),
                );

                $this->assets->saveDownload($download);
                $downloads[] = $download;
            }
        }

        return $downloads;
    }

    public function validateToken(string $token): ?DigitalDownload
    {
        $download = $this->assets->findByToken($token);

        if ($download === null) {
            return null;
        }

        if (!$download->isValid()) {
            return null;
        }

        return $download;
    }

    public function processDownload(string $token): DownloadResult
    {
        $download = $this->assets->findByToken($token);

        if ($download === null || !$download->isValid()) {
            return new DownloadResult(
                success: false,
                filePath: null,
                fileName: null,
                downloadsRemaining: null,
            );
        }

        // Atomically decrement — returns affected rows count.
        // This is the authoritative check: if it returns 0, another concurrent
        // request already consumed the last download.
        $decremented = $this->assets->decrementDownloads($download->id);

        if ($decremented === 0) {
            return new DownloadResult(
                success: false,
                filePath: null,
                fileName: null,
                downloadsRemaining: 0,
            );
        }

        // Resolve file info from the digital asset record
        $assetRow = $this->db->query(
            'SELECT file_storage_path, file_name FROM cms_digital_assets WHERE id = :id',
            ['id' => $download->digitalAssetId],
        )->first();

        $filePath = $assetRow?->getString('file_storage_path');
        $fileName = $assetRow?->getString('file_name');
        $remainingAfter = $download->downloadsRemaining - 1;

        $this->auditLogger?->log(
            AuditEvent::DataAccess,
            AuditOutcome::Success,
            null,
            'cms.commerce.download.processed',
            "download:$download->id",
            [
                'orderItemId' => $download->orderItemId,
                'assetId' => $download->digitalAssetId,
                'remainingDownloads' => $remainingAfter,
            ],
        );

        return new DownloadResult(
            success: true,
            filePath: $filePath,
            fileName: $fileName,
            downloadsRemaining: $remainingAfter,
        );
    }

}
