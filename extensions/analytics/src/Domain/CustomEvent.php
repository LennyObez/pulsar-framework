<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Domain;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * A custom event tracked by the analytics system.
 */
#[Api(since: '1.0.0')]
final readonly class CustomEvent
{
    /**
     * @param array<string, mixed> $eventProps Arbitrary event properties
     */
    public function __construct(
        public string $id,
        public string $siteId,
        public string $visitorId,
        public string $sessionId,
        public string $eventName,
        public array $eventProps = [],
        public ?float $revenueValue = null,
        public string $pathname = '',
        public DateTimeImmutable $createdAt = new DateTimeImmutable(),
    ) {}
}
