<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Tag;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Tag\TagStrategyInterface;

#[CoversClass(TagStrategyInterface::class)]
final class TagStrategyInterfaceTest extends TestCase
{
    private function createVersioningStrategy(): TagStrategyInterface
    {
        return new class implements TagStrategyInterface {
            /** @var array<string, int> */
            private array $versions = [];

            public function getTagVersions(array $tags): array
            {
                $result = [];
                foreach ($tags as $tag) {
                    $this->versions[$tag] ??= 1;
                    $result[$tag] = (string) $this->versions[$tag];
                }

                return $result;
            }

            public function invalidateTag(string $tag): void
            {
                $this->versions[$tag] = ($this->versions[$tag] ?? 0) + 1;
            }

            public function invalidateTags(array $tags): void
            {
                foreach ($tags as $tag) {
                    $this->invalidateTag($tag);
                }
            }
        };
    }

    #[Test]
    public function getTagVersionsReturnsVersionMap(): void
    {
        $strategy = $this->createVersioningStrategy();

        $versions = $strategy->getTagVersions(['users', 'posts']);

        self::assertArrayHasKey('users', $versions);
        self::assertArrayHasKey('posts', $versions);
        self::assertSame('1', $versions['users']);
        self::assertSame('1', $versions['posts']);
    }

    #[Test]
    public function invalidateTagBumpsVersion(): void
    {
        $strategy = $this->createVersioningStrategy();

        $before = $strategy->getTagVersions(['users']);
        $strategy->invalidateTag('users');
        $after = $strategy->getTagVersions(['users']);

        self::assertNotSame($before['users'], $after['users']);
    }

    #[Test]
    public function invalidateTagsAffectsMultipleTags(): void
    {
        $strategy = $this->createVersioningStrategy();

        $before = $strategy->getTagVersions(['a', 'b', 'c']);
        $strategy->invalidateTags(['a', 'c']);
        $after = $strategy->getTagVersions(['a', 'b', 'c']);

        self::assertNotSame($before['a'], $after['a']);
        self::assertSame($before['b'], $after['b']);
        self::assertNotSame($before['c'], $after['c']);
    }

    #[Test]
    public function getTagVersionsWithEmptyArrayReturnsEmptyMap(): void
    {
        $strategy = $this->createVersioningStrategy();

        $versions = $strategy->getTagVersions([]);

        self::assertSame([], $versions);
    }

    #[Test]
    public function invalidateTagOnUntrackedTagInitializesVersion(): void
    {
        $strategy = $this->createVersioningStrategy();

        $strategy->invalidateTag('new-tag');
        $versions = $strategy->getTagVersions(['new-tag']);

        self::assertArrayHasKey('new-tag', $versions);
        self::assertNotEmpty($versions['new-tag']);
    }
}
