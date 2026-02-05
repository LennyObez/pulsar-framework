<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Features\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Features\Query\QueryFingerprint;

#[CoversClass(QueryFingerprint::class)]
final class QueryFingerprintTest extends TestCase
{
    #[Test]
    public function ofReturnsDeterministicHash(): void
    {
        $fp1 = QueryFingerprint::of('SELECT * FROM users WHERE id = :p0');
        $fp2 = QueryFingerprint::of('SELECT * FROM users WHERE id = :p0');

        self::assertSame($fp1, $fp2);
    }

    #[Test]
    public function ofReturnsDifferentHashForDifferentSql(): void
    {
        $fp1 = QueryFingerprint::of('SELECT * FROM users');
        $fp2 = QueryFingerprint::of('SELECT * FROM orders');

        self::assertNotSame($fp1, $fp2);
    }

    #[Test]
    public function ofReturnsNonEmptyString(): void
    {
        $fp = QueryFingerprint::of('SELECT 1');

        self::assertNotEmpty($fp);
    }

    #[Test]
    public function ofReturnsHexString(): void
    {
        $fp = QueryFingerprint::of('SELECT * FROM users WHERE active = true');

        // xxh128 returns a 32-character hex string
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $fp);
    }

    #[Test]
    public function ofHandlesEmptyString(): void
    {
        $fp = QueryFingerprint::of('');

        self::assertNotEmpty($fp);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $fp);
    }
}
