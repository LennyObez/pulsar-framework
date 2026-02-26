<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Tracks a customer's download entitlement for a purchased digital asset.
 */
#[Api(since: '1.0.0')]
final readonly class DigitalDownload
{
    /**
     * @param string $id UUIDv7
     * @param string $orderItemId UUIDv7 of the order line item granting access
     * @param string $digitalAssetId UUIDv7 of the downloadable asset
     * @param string $downloadToken Secure token for download URL generation
     * @param int $downloadsRemaining Number of downloads still available
     * @param DateTimeImmutable $expiresAt When the download entitlement expires
     */
    public function __construct(
        public string $id,
        public string $orderItemId,
        public string $digitalAssetId,
        public string $downloadToken,
        public int $downloadsRemaining,
        public DateTimeImmutable $expiresAt,
    ) {}

    /**
     * Whether this download entitlement is still valid.
     */
    public function isValid(?DateTimeImmutable $now = null): bool
    {
        $now ??= new DateTimeImmutable();

        return $this->downloadsRemaining > 0 && $now < $this->expiresAt;
    }
}
