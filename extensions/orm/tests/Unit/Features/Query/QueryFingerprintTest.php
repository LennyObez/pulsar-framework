<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Features\Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Features\Query\QueryFingerprint;

final class QueryFingerprintTest extends TestCase
{
    #[Test]
    public function producesConsistentHash(): void
    {
        $sql = 'SELECT * FROM users WHERE id = :p0';

        $a = QueryFingerprint::of($sql);
        $b = QueryFingerprint::of($sql);

        self::assertSame($a, $b);
    }

    #[Test]
    public function differentQueriesProduceDifferentHashes(): void
    {
        $a = QueryFingerprint::of('SELECT * FROM users');
        $b = QueryFingerprint::of('SELECT * FROM posts');

        self::assertNotSame($a, $b);
    }

    #[Test]
    public function returnsHexString(): void
    {
        $hash = QueryFingerprint::of('SELECT 1');

        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $hash);
    }
}
