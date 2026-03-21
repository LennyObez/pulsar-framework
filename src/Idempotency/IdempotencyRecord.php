<?php

declare(strict_types=1);

namespace Pulsar\Idempotency;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Stored idempotency record.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class IdempotencyRecord
{
    public function __construct(
        public string $key,
        public string $parametersHash,
        public string $operation,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
        public ?string $resultPayload = null,
    ) {}
}
