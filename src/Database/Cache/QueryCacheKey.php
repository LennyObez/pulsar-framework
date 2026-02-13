<?php

declare(strict_types=1);

namespace Pulsar\Database\Cache;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Database\Routing\ConnectionRole;

use function hash;
use function json_encode;
use function ksort;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Deterministic cache key generation for query results.
 *
 * Keys are built from a hash of the normalized SQL, sorted bindings,
 * tenant ID, connection role, and schema version to ensure correctness
 * across tenants and schema migrations.
 */
#[Api(since: '1.0.0')]
final readonly class QueryCacheKey
{
    /**
     * Build a deterministic cache key for a query result.
     *
     * @param array<string, mixed> $bindings
     */
    #[NoDiscard]
    public static function build(
        string $normalizedSql,
        array $bindings,
        ?string $tenantId,
        ConnectionRole $role,
        ?string $schemaVersion,
    ): string {
        ksort($bindings);

        $payload = json_encode([
            'sql' => $normalizedSql,
            'bindings' => $bindings,
            'tenant' => $tenantId,
            'role' => $role->value,
            'schema' => $schemaVersion,
        ], JSON_THROW_ON_ERROR);

        return sprintf('qc:%s', hash('xxh128', $payload));
    }
}
