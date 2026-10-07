<?php

declare(strict_types=1);

namespace Pulsar\Security\Canary;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Immutable DTO representing a canary token embedded in exported data.
 *
 * Canary tokens are invisible markers placed in sensitive data exports.
 * If a canary token appears in an unexpected location, it indicates a data leak.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CanaryToken
{
    public function __construct(
        public string $id,
        public string $label,
        public string $marker,
        public string $context,
        public DateTimeImmutable $createdAt,
        public ?string $createdBy = null,
    ) {}
}
