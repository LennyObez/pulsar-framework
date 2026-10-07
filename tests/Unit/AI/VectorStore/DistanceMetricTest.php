<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\VectorStore;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\VectorStore\DistanceMetric;
use ValueError;

#[CoversNothing]
final class DistanceMetricTest extends TestCase
{
    #[Test]
    public function allCasesHaveStringValues(): void
    {
        self::assertSame('cosine', DistanceMetric::Cosine->value);
        self::assertSame('l2', DistanceMetric::L2->value);
        self::assertSame('inner_product', DistanceMetric::InnerProduct->value);
    }

    #[Test]
    public function enumHasExactlyThreeCases(): void
    {
        self::assertCount(3, DistanceMetric::cases());
    }

    #[Test]
    #[DataProvider('validStringValues')]
    public function fromCreatesEnumFromValidString(string $value, DistanceMetric $expected): void
    {
        self::assertSame($expected, DistanceMetric::from($value));
    }

    /**
     * @return iterable<string, array{string, DistanceMetric}>
     */
    public static function validStringValues(): iterable
    {
        yield 'cosine' => ['cosine', DistanceMetric::Cosine];
        yield 'l2' => ['l2', DistanceMetric::L2];
        yield 'inner_product' => ['inner_product', DistanceMetric::InnerProduct];
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(DistanceMetric::tryFrom('euclidean'));
        self::assertNull(DistanceMetric::tryFrom(''));
        self::assertNull(DistanceMetric::tryFrom('COSINE'));
    }

    #[Test]
    public function fromThrowsForInvalidValue(): void
    {
        $this->expectException(ValueError::class);

        DistanceMetric::from('manhattan');
    }
}
