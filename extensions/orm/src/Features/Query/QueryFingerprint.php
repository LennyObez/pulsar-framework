<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Query;

use function hash;

use NoDiscard;
use Pulsar\Api\Internal;

/**
 * Generates a stable fingerprint for a query (SQL text without bindings).
 *
 * Used for query analytics, caching, and deduplication.
 */
#[Internal]
final readonly class QueryFingerprint
{
    #[NoDiscard]
    public static function of(string $sql): string
    {
        return hash('xxh128', $sql);
    }
}
