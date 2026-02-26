<?php

declare(strict_types=1);

namespace Pulsar\Database\Pool;

use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;

/**
 * Internal DTO tracking a pooled connection and its timestamps.
 */
#[Internal(reason: 'Pool implementation detail — not part of the public API')]
final readonly class PooledEntry
{
    public function __construct(
        public ConnectionInterface $connection,
        public int $createdAt,
        public int $lastUsedAt,
    ) {}
}
