<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Dlp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Dlp\DlpMatch;
use Pulsar\Security\Dlp\SensitiveDataType;

#[CoversClass(DlpMatch::class)]
final class DlpMatchTest extends TestCase
{
    public function testConstructorAssignsAllProperties(): void
    {
        $match = new DlpMatch(
            type: SensitiveDataType::CreditCard,
            pattern: '/\d{16}/',
            offset: 15,
            length: 16,
            maskedValue: '************1111',
        );

        self::assertSame(SensitiveDataType::CreditCard, $match->type);
        self::assertSame('/\d{16}/', $match->pattern);
        self::assertSame(15, $match->offset);
        self::assertSame(16, $match->length);
        self::assertSame('************1111', $match->maskedValue);
    }

    public function testDifferentDataTypes(): void
    {
        $ssn = new DlpMatch(
            type: SensitiveDataType::Ssn,
            pattern: '/\d{3}-\d{2}-\d{4}/',
            offset: 5,
            length: 11,
            maskedValue: '*******6789',
        );

        self::assertSame(SensitiveDataType::Ssn, $ssn->type);

        $api = new DlpMatch(
            type: SensitiveDataType::ApiKey,
            pattern: '/sk_/',
            offset: 0,
            length: 32,
            maskedValue: '****************************xxxx',
        );

        self::assertSame(SensitiveDataType::ApiKey, $api->type);
    }
}
