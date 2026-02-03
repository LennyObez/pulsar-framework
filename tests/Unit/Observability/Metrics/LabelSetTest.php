<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Metrics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Metrics\LabelSet;

#[CoversClass(LabelSet::class)]
final class LabelSetTest extends TestCase
{
    #[Test]
    public function emptyLabelSetHasEmptyKey(): void
    {
        $labels = new LabelSet();

        self::assertSame('', $labels->key());
        self::assertTrue($labels->isEmpty());
    }

    #[Test]
    public function keyIsDeterministicRegardlessOfInsertOrder(): void
    {
        $a = new LabelSet(['z' => '1', 'a' => '2']);
        $b = new LabelSet(['a' => '2', 'z' => '1']);

        self::assertSame($a->key(), $b->key());
        self::assertSame('a=2,z=1', $a->key());
    }

    #[Test]
    public function toArrayReturnsLabels(): void
    {
        $labels = new LabelSet(['method' => 'GET', 'path' => '/']);

        self::assertSame(['method' => 'GET', 'path' => '/'], $labels->toArray());
        self::assertFalse($labels->isEmpty());
    }
}
