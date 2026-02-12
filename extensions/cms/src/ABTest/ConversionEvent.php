<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\ABTest;

use DateTimeImmutable;
use Pulsar\Api\Api;

#[Api(since: '1.0.0')]
final readonly class ConversionEvent
{
    public function __construct(
        public string $id,
        public string $experimentId,
        public string $variantId,
        public string $visitorId,
        public string $type,
        public DateTimeImmutable $createdAt,
    ) {}
}
