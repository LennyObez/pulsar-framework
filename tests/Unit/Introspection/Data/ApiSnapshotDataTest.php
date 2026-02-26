<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Introspection\Data;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Introspection\Data\ApiSnapshotData;

#[CoversClass(ApiSnapshotData::class)]
final class ApiSnapshotDataTest extends TestCase
{
    #[Test]
    public function constructorDefaultsToEmptyClasses(): void
    {
        $snapshot = new ApiSnapshotData();

        self::assertSame([], $snapshot->classes);
    }

    #[Test]
    public function constructorAcceptsClasses(): void
    {
        $classes = [
            'Pulsar\\Http\\Method' => [
                'since' => '1.0.0',
                'methods' => ['isSafe'],
                'constants' => ['GET'],
            ],
        ];

        $snapshot = new ApiSnapshotData(classes: $classes);

        self::assertSame($classes, $snapshot->classes);
    }

    #[Test]
    public function toArrayReturnsExpectedStructure(): void
    {
        $classes = [
            'Pulsar\\Core\\Version' => [
                'since' => '1.0.0',
                'methods' => ['full', 'major'],
                'constants' => [],
            ],
        ];

        $snapshot = new ApiSnapshotData(classes: $classes);
        $array = $snapshot->toArray();

        self::assertArrayHasKey('classes', $array);
        self::assertSame($classes, $array['classes']);
    }

    #[Test]
    public function toArrayWithEmptyClassesReturnsEmptyArray(): void
    {
        $snapshot = new ApiSnapshotData();

        self::assertSame(['classes' => []], $snapshot->toArray());
    }
}
