<?php

declare(strict_types=1);

namespace Pulsar\Extension\Eidas\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Eidas\Domain\TimestampToken;

final class TimestampTokenTest extends TestCase
{
    #[Test]
    public function toArrayContainsAllFields(): void
    {
        $token = new TimestampToken(
            tokenId: 'ts_001',
            dataHash: 'deadbeef',
            hashAlgorithm: 'sha256',
            timestamp: new DateTimeImmutable('2026-01-15T10:00:00+00:00'),
            tsaName: 'Test TSA',
            isQualified: true,
            encodedToken: 'encoded_data',
        );

        $array = $token->toArray();

        self::assertSame('ts_001', $array['token_id']);
        self::assertSame('deadbeef', $array['data_hash']);
        self::assertSame('sha256', $array['hash_algorithm']);
        self::assertSame('Test TSA', $array['tsa_name']);
        self::assertTrue($array['is_qualified']);
        self::assertArrayHasKey('timestamp', $array);
        // encodedToken is intentionally excluded from toArray (sensitive)
        self::assertArrayNotHasKey('encoded_token', $array);
    }
}
