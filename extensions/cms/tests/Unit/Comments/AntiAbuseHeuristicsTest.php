<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Comments;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Extension\Cms\Comments\AntiAbuseHeuristics;

#[CoversClass(AntiAbuseHeuristics::class)]
final class AntiAbuseHeuristicsTest extends TestCase
{
    private TaggedCacheInterface&Stub $cache;
    private AntiAbuseHeuristics $heuristics;

    protected function setUp(): void
    {
        $this->cache = $this->createStub(TaggedCacheInterface::class);
        $this->heuristics = new AntiAbuseHeuristics($this->cache);
    }

    #[Test]
    public function isDuplicate_returns_true_when_cache_hit(): void
    {
        $this->cache->method('get')->willReturn('1');

        self::assertTrue($this->heuristics->isDuplicate('bodyhash', 'iphash'));
    }

    #[Test]
    public function isDuplicate_returns_false_when_cache_miss(): void
    {
        $this->cache->method('get')->willReturn(null);

        self::assertFalse($this->heuristics->isDuplicate('bodyhash', 'iphash'));
    }

    #[Test]
    public function recordSubmission_stores_in_cache(): void
    {
        $cache = $this->createMock(TaggedCacheInterface::class);
        $cache->expects(self::once())
            ->method('set')
            ->with(
                self::stringContains('cms_comment_dedup:'),
                '1',
                ['cms_comment_dedup'],
                300,
            );

        $heuristics = new AntiAbuseHeuristics($cache);
        $heuristics->recordSubmission('bodyhash', 'iphash');
    }

    /**
     * @return iterable<string, array{string, int, bool}>
     */
    public static function linkSpamProvider(): iterable
    {
        yield 'no links' => ['Hello world', 3, false];
        yield 'within limit' => ['Visit https://a.com and https://b.com', 3, false];
        yield 'at limit' => ['https://a.com https://b.com https://c.com', 3, false];
        yield 'over limit' => ['https://a.com https://b.com https://c.com https://d.com', 3, true];
        yield 'html links' => ['<a href="x">1</a> <a href="y">2</a> <a href="z">3</a> <a href="w">4</a>', 3, true];
    }

    #[Test]
    #[DataProvider('linkSpamProvider')]
    public function isLinkSpam_detects_excessive_links(string $body, int $maxLinks, bool $expected): void
    {
        self::assertSame($expected, $this->heuristics->isLinkSpam($body, $maxLinks));
    }

    #[Test]
    public function isTooLong_returns_true_for_oversized_body(): void
    {
        self::assertTrue($this->heuristics->isTooLong(str_repeat('a', 10001), 10000));
        self::assertFalse($this->heuristics->isTooLong(str_repeat('a', 10000), 10000));
        self::assertFalse($this->heuristics->isTooLong('short', 10000));
    }

    #[Test]
    public function hasExcessiveRepetition_detects_repeated_chars(): void
    {
        self::assertTrue($this->heuristics->hasExcessiveRepetition('aaaaaaaaaa'));
        self::assertTrue($this->heuristics->hasExcessiveRepetition('Hello!!!!!!!!!!!'));
        self::assertFalse($this->heuristics->hasExcessiveRepetition('Normal text'));
        self::assertFalse($this->heuristics->hasExcessiveRepetition('aaaaaaaaa')); // 9 repeats, threshold is 10
    }

    #[Test]
    public function hashBody_returns_consistent_hash(): void
    {
        $hash1 = $this->heuristics->hashBody('test body');
        $hash2 = $this->heuristics->hashBody('test body');

        self::assertSame($hash1, $hash2);
        self::assertNotEmpty($hash1);
    }

    #[Test]
    public function hashBody_produces_different_hashes_for_different_input(): void
    {
        $hash1 = $this->heuristics->hashBody('body one');
        $hash2 = $this->heuristics->hashBody('body two');

        self::assertNotSame($hash1, $hash2);
    }
}
