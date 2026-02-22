<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Cache\QueryCacheConfig;
use Pulsar\Database\Cache\SensitivityMetadata;

#[CoversClass(SensitivityMetadata::class)]
final class SensitivityMetadataTest extends TestCase
{
    #[Test]
    public function sensitiveTableDetected(): void
    {
        $metadata = new SensitivityMetadata(
            new QueryCacheConfig(sensitiveTableNames: ['audit_logs', 'credentials']),
        );

        self::assertTrue($metadata->isTableSensitive('audit_logs'));
        self::assertTrue($metadata->isTableSensitive('credentials'));
    }

    #[Test]
    public function nonSensitiveTablePasses(): void
    {
        $metadata = new SensitivityMetadata(
            new QueryCacheConfig(sensitiveTableNames: ['audit_logs']),
        );

        self::assertFalse($metadata->isTableSensitive('users'));
    }

    #[Test]
    public function authorizationShapedQueryDetected(): void
    {
        $metadata = new SensitivityMetadata(new QueryCacheConfig());

        self::assertTrue(
            $metadata->isAuthorizationShaped('SELECT * FROM users WHERE user_id = ?', [':user_id' => 1]),
        );
    }

    #[Test]
    public function nonAuthorizationQueryPasses(): void
    {
        $metadata = new SensitivityMetadata(new QueryCacheConfig());

        self::assertFalse(
            $metadata->isAuthorizationShaped('SELECT * FROM products WHERE price > ?', [':price' => 10]),
        );
    }

    #[Test]
    public function shouldCacheReturnsFalseForSensitive(): void
    {
        $metadata = new SensitivityMetadata(
            new QueryCacheConfig(sensitiveTableNames: ['audit_logs']),
        );

        self::assertFalse(
            $metadata->shouldCache('SELECT * FROM audit_logs', [], ['audit_logs']),
        );
    }

    #[Test]
    public function shouldCacheReturnsFalseForAuthShaped(): void
    {
        $metadata = new SensitivityMetadata(new QueryCacheConfig());

        self::assertFalse(
            $metadata->shouldCache(
                'SELECT * FROM orders WHERE user_id = ?',
                [':user_id' => 1],
                ['orders'],
            ),
        );
    }

    #[Test]
    public function shouldCacheReturnsTrueForNormalQuery(): void
    {
        $metadata = new SensitivityMetadata(new QueryCacheConfig());

        self::assertTrue(
            $metadata->shouldCache(
                'SELECT * FROM products WHERE price > ?',
                [':price' => 10],
                ['products'],
            ),
        );
    }
}
