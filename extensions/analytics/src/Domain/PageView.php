<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Domain;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * A single page view event recorded by the analytics tracker.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PageView
{
    public function __construct(
        public string $id,
        public string $siteId,
        public string $visitorId,
        public string $sessionId,
        public string $pathname,
        public string $referrerSource = '',
        public string $utmSource = '',
        public string $utmMedium = '',
        public string $utmCampaign = '',
        public string $utmTerm = '',
        public string $utmContent = '',
        public string $countryCode = '',
        public DeviceType $deviceType = DeviceType::Unknown,
        public string $browser = '',
        public string $os = '',
        public int $screenWidth = 0,
        public bool $isBounce = true,
        public DateTimeImmutable $createdAt = new DateTimeImmutable(),
    ) {}
}
