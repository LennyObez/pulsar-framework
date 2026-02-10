<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce\Event;

use Pulsar\Api\Api;

/**
 * Dispatched when digital download links are ready after purchase.
 */
#[Api(since: '1.0.0')]
final readonly class DigitalDownloadReady
{
    /**
     * @param list<array{token: string, fileName: string}> $downloadLinks Secure download tokens with filenames
     */
    public function __construct(
        public string $orderId,
        public array $downloadLinks,
    ) {}
}
