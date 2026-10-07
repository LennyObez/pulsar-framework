<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

/**
 * Service interface for managing digital product download entitlements.
 * @api
 */
#[Api(since: '1.0.0')]
interface DigitalDeliveryServiceInterface
{
    /**
     * Create download tokens for all digital items in an order.
     *
     * @return list<DigitalDownload>
     */
    public function createDownloadTokens(string $orderId): array;

    /**
     * Validate whether a download token is still valid.
     */
    public function validateToken(string $token): ?DigitalDownload;

    /**
     * Process a download request, decrementing the remaining count.
     */
    public function processDownload(string $token): DownloadResult;
}
