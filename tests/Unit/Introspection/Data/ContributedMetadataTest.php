<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Introspection\Data;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Introspection\Data\ContributedMetadata;

#[CoversClass(ContributedMetadata::class)]
final class ContributedMetadataTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $sections = ['info' => ['version' => '1.0']];
        $metadata = new ContributedMetadata(
            contributorId: 'router',
            sections: $sections,
            sizeBytes: 42,
        );

        self::assertSame('router', $metadata->contributorId);
        self::assertSame($sections, $metadata->sections);
        self::assertSame(42, $metadata->sizeBytes());
    }

    #[Test]
    public function toArrayReturnsExpectedStructure(): void
    {
        $metadata = new ContributedMetadata(
            contributorId: 'cache',
            sections: ['stats' => ['hits' => 100, 'misses' => 5]],
            sizeBytes: 128,
        );

        $array = $metadata->toArray();

        self::assertSame('cache', $array['contributor_id']);
        self::assertArrayHasKey('stats', $array['sections']);
        self::assertSame(100, $array['sections']['stats']['hits']);
    }

    #[Test]
    public function toArrayOmitsSizeBytes(): void
    {
        $metadata = new ContributedMetadata(
            contributorId: 'test',
            sections: [],
            sizeBytes: 99,
        );

        $array = $metadata->toArray();

        self::assertArrayNotHasKey('sizeBytes', $array);
        self::assertArrayNotHasKey('size_bytes', $array);
    }
}
