<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Comments;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Extension\Cms\Comments\AntiAbuseHeuristics;

#[CoversClass(AntiAbuseHeuristics::class)]
final class AntiAbuseHeuristicsTest extends TestCase
{
    private AntiAbuseHeuristics $heuristics;

    /** @var array<string, mixed> */
    private array $cacheStore = [];

    protected function setUp(): void
    {
        $cache = $this->createStub(TaggedCacheInterface::class);

        $cache->method('get')->willReturnCallback(
            fn(string $key): mixed => $this->cacheStore[$key] ?? null,
        );

        $cache->method('set')->willReturnCallback(
            function (string $key, mixed $value): bool {
                $this->cacheStore[$key] = $value;

                return true;
            },
        );

        $this->heuristics = new AntiAbuseHeuristics($cache);
    }

    // -- Duplicate detection ----------------------------------------------

    #[Test]
    public function isDuplicateReturnsFalseForFreshSubmission(): void
    {
        $bodyHash = $this->heuristics->hashBody('Hello world');
        $ipHash = 'ip-hash-123';

        self::assertFalse($this->heuristics->isDuplicate($bodyHash, $ipHash));
    }

    #[Test]
    public function isDuplicateReturnsTrueAfterRecording(): void
    {
        $bodyHash = $this->heuristics->hashBody('Hello world');
        $ipHash = 'ip-hash-123';

        $this->heuristics->recordSubmission($bodyHash, $ipHash);

        self::assertTrue($this->heuristics->isDuplicate($bodyHash, $ipHash));
    }

    #[Test]
    public function duplicateDetectionIsIpSpecific(): void
    {
        $bodyHash = $this->heuristics->hashBody('Same body');

        $this->heuristics->recordSubmission($bodyHash, 'ip-A');

        self::assertTrue($this->heuristics->isDuplicate($bodyHash, 'ip-A'));
        self::assertFalse($this->heuristics->isDuplicate($bodyHash, 'ip-B'));
    }

    // -- Link spam detection ----------------------------------------------

    #[Test]
    public function zeroLinksPasses(): void
    {
        self::assertFalse($this->heuristics->isLinkSpam('No links here'));
    }

    #[Test]
    public function threeLinksPassesDefaultMax(): void
    {
        $body = 'Visit https://a.com and https://b.com and https://c.com for info';
        self::assertFalse($this->heuristics->isLinkSpam($body));
    }

    #[Test]
    public function fourLinksFailsDefaultMax(): void
    {
        $body = 'See https://a.com https://b.com https://c.com https://d.com now';
        self::assertTrue($this->heuristics->isLinkSpam($body));
    }

    #[Test]
    public function anchorTagsCountAsLinks(): void
    {
        $body = '<a href="x">1</a> <a href="y">2</a> <a href="z">3</a> <a href="w">4</a>';
        self::assertTrue($this->heuristics->isLinkSpam($body));
    }

    #[Test]
    public function customMaxLinksParameter(): void
    {
        $body = 'https://a.com and https://b.com';
        self::assertFalse($this->heuristics->isLinkSpam($body, maxLinks: 5));
        self::assertTrue($this->heuristics->isLinkSpam($body, maxLinks: 1));
    }

    // -- Content length ---------------------------------------------------

    #[Test]
    public function bodyAt10000CharsPasses(): void
    {
        $body = str_repeat('a', 10000);
        self::assertFalse($this->heuristics->isTooLong($body));
    }

    #[Test]
    public function bodyAt10001CharsFails(): void
    {
        $body = str_repeat('a', 10001);
        self::assertTrue($this->heuristics->isTooLong($body));
    }

    #[Test]
    public function customMaxLengthParameter(): void
    {
        $body = str_repeat('x', 500);
        self::assertFalse($this->heuristics->isTooLong($body, maxLength: 1000));
        self::assertTrue($this->heuristics->isTooLong($body, maxLength: 100));
    }

    // -- Excessive repetition ---------------------------------------------

    #[Test]
    public function normalTextPassesRepetitionCheck(): void
    {
        self::assertFalse($this->heuristics->hasExcessiveRepetition('This is a normal comment.'));
    }

    #[Test]
    public function tenRepeatedCharsDetected(): void
    {
        self::assertTrue($this->heuristics->hasExcessiveRepetition('aaaaaaaaaa'));
    }

    #[Test]
    public function exclamationSpamDetected(): void
    {
        self::assertTrue($this->heuristics->hasExcessiveRepetition('Buy now!!!!!!!!!!'));
    }

    #[Test]
    public function nineRepeatedCharsPasses(): void
    {
        self::assertFalse($this->heuristics->hasExcessiveRepetition('aaaaaaaaa'));
    }

    // -- Edge cases -------------------------------------------------------

    #[Test]
    public function emptyBodyPassesAllChecks(): void
    {
        self::assertFalse($this->heuristics->isLinkSpam(''));
        self::assertFalse($this->heuristics->isTooLong(''));
        self::assertFalse($this->heuristics->hasExcessiveRepetition(''));
    }

    #[Test]
    public function singleCharacterPasses(): void
    {
        self::assertFalse($this->heuristics->isLinkSpam('a'));
        self::assertFalse($this->heuristics->isTooLong('a'));
        self::assertFalse($this->heuristics->hasExcessiveRepetition('a'));
    }

    // -- hashBody consistency ---------------------------------------------

    #[Test]
    public function hashBodyReturnsConsistentHash(): void
    {
        $hash1 = $this->heuristics->hashBody('test body');
        $hash2 = $this->heuristics->hashBody('test body');

        self::assertSame($hash1, $hash2);
    }

    #[Test]
    public function hashBodyReturnsDifferentHashForDifferentInput(): void
    {
        $hash1 = $this->heuristics->hashBody('first');
        $hash2 = $this->heuristics->hashBody('second');

        self::assertNotSame($hash1, $hash2);
    }

    // -- Mixed URL and anchor detection -----------------------------------

    #[Test]
    public function mixedUrlsAndAnchorsCombined(): void
    {
        $body = '<a href="x">link</a> https://example.com https://other.com https://third.com';
        self::assertTrue($this->heuristics->isLinkSpam($body));
    }

    // -- Multibyte content length -----------------------------------------

    #[Test]
    public function multibyteLengthCountedCorrectly(): void
    {
        // Each emoji is 1 mb_strlen character
        $body = str_repeat("\u{1F600}", 10001);
        self::assertTrue($this->heuristics->isTooLong($body));
    }
}
