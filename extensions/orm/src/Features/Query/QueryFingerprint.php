<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Query;

use NoDiscard;
use Pulsar\Api\Internal;

use function hash;

/**
 * Generates a stable fingerprint for a query (SQL text without bindings).
 *
 * Used for query analytics, caching, and deduplication.
 */
#[Internal]
final class QueryFingerprint
{
    #[NoDiscard]
    public static function of(string $sql): string
    {
        return hash('xxh128', $sql);
    }
}
