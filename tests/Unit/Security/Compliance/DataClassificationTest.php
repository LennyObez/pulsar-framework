<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\DataClassification;

#[CoversClass(DataClassification::class)]
final class DataClassificationTest extends TestCase
{
    /**
     * @return iterable<string, array{DataClassification, string}>
     */
    public static function classificationProvider(): iterable
    {
        yield 'Public' => [DataClassification::Public, 'public'];
        yield 'Internal' => [DataClassification::Internal, 'internal'];
        yield 'Confidential' => [DataClassification::Confidential, 'confidential'];
        yield 'Restricted' => [DataClassification::Restricted, 'restricted'];
    }

    #[Test]
    #[DataProvider('classificationProvider')]
    public function backingValueMatchesExpected(DataClassification $classification, string $expected): void
    {
        self::assertSame($expected, $classification->value);
    }

    #[Test]
    public function hasFourLevels(): void
    {
        self::assertCount(4, DataClassification::cases());
    }

    #[Test]
    public function canBeCreatedFromString(): void
    {
        self::assertSame(DataClassification::Restricted, DataClassification::from('restricted'));
        self::assertSame(DataClassification::Confidential, DataClassification::from('confidential'));
    }

    #[Test]
    public function tryFromReturnsNullForUnknown(): void
    {
        self::assertNull(DataClassification::tryFrom('secret'));
    }
}
