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

    #[Test]
    public function commaInValueIsEscapedInKey(): void
    {
        // Without escaping, a literal comma in a value would collide with the
        // pair separator and corrupt round-tripping in the OpenMetrics exporter.
        $labels = new LabelSet(['url' => 'https://example.com/a,b']);

        self::assertSame('url=https://example.com/a%2Cb', $labels->key());
    }

    #[Test]
    public function escapeRoundTripsValuesContainingCommaAndPercent(): void
    {
        foreach (['plain', 'a,b', '100%', 'a,b%2Cc', '%2C', 'x=y,z'] as $value) {
            self::assertSame(
                $value,
                LabelSet::unescapeValue(self::encodedValueOf($value)),
                'round-trip failed for: ' . $value,
            );
        }
    }

    /**
     * Extract the encoded value portion of a single-label key (`v=<encoded>`).
     */
    private static function encodedValueOf(string $value): string
    {
        $key = new LabelSet(['v' => $value])->key();

        // A single-label key always begins with the literal prefix "v=".
        return explode('=', $key, 2)[1] ?? '';
    }
}
