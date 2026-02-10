<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Cache\QueryCacheConfig;

#[CoversClass(QueryCacheConfig::class)]
final class QueryCacheConfigTest extends TestCase
{
    #[Test]
    public function fromArrayWithDefaults(): void
    {
        $config = QueryCacheConfig::fromArray([]);

        self::assertTrue($config->enabled);
        self::assertSame(60, $config->defaultTtlSeconds);
        self::assertSame([], $config->sensitiveTableNames);
        self::assertSame(['user_id', 'tenant_id'], $config->authorizationColumns);
    }

    #[Test]
    public function fromArrayWithCustomValues(): void
    {
        $config = QueryCacheConfig::fromArray([
            'enabled' => false,
            'default_ttl_seconds' => 300,
            'sensitive_table_names' => ['audit_logs', 'credentials'],
            'authorization_columns' => ['org_id'],
        ]);

        self::assertFalse($config->enabled);
        self::assertSame(300, $config->defaultTtlSeconds);
        self::assertSame(['audit_logs', 'credentials'], $config->sensitiveTableNames);
        self::assertSame(['org_id'], $config->authorizationColumns);
    }

    #[Test]
    public function regulatedPresetDefaultsDisabled(): void
    {
        $config = QueryCacheConfig::fromArray([
            'enabled' => false,
            'sensitive_table_names' => ['audit_logs', 'credentials', 'sessions'],
        ]);

        self::assertFalse($config->enabled);
        self::assertCount(3, $config->sensitiveTableNames);
    }
}
