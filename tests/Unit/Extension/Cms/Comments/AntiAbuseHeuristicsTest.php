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
    public function test_is_duplicate_returns_false_for_fresh_submission(): void
    {
        $bodyHash = $this->heuristics->hashBody('Hello world');
        $ipHash = 'ip-hash-123';

        self::assertFalse($this->heuristics->isDuplicate($bodyHash, $ipHash));
    }

    #[Test]
    public function test_is_duplicate_returns_true_after_recording(): void
    {
        $bodyHash = $this->heuristics->hashBody('Hello world');
        $ipHash = 'ip-hash-123';

        $this->heuristics->recordSubmission($bodyHash, $ipHash);

        self::assertTrue($this->heuristics->isDuplicate($bodyHash, $ipHash));
    }

    #[Test]
    public function test_duplicate_detection_is_ip_specific(): void
    {
        $bodyHash = $this->heuristics->hashBody('Same body');

        $this->heuristics->recordSubmission($bodyHash, 'ip-A');

        self::assertTrue($this->heuristics->isDuplicate($bodyHash, 'ip-A'));
        self::assertFalse($this->heuristics->isDuplicate($bodyHash, 'ip-B'));
    }

    // -- Link spam detection ----------------------------------------------

    #[Test]
    public function test_zero_links_passes(): void
    {
        self::assertFalse($this->heuristics->isLinkSpam('No links here'));
    }

    #[Test]
    public function test_three_links_passes_default_max(): void
    {
        $body = 'Visit https://a.com and https://b.com and https://c.com for info';
        self::assertFalse($this->heuristics->isLinkSpam($body));
    }

    #[Test]
    public function test_four_links_fails_default_max(): void
    {
        $body = 'See https://a.com https://b.com https://c.com https://d.com now';
        self::assertTrue($this->heuristics->isLinkSpam($body));
    }

    #[Test]
    public function test_anchor_tags_count_as_links(): void
    {
        $body = '<a href="x">1</a> <a href="y">2</a> <a href="z">3</a> <a href="w">4</a>';
        self::assertTrue($this->heuristics->isLinkSpam($body));
    }

    #[Test]
    public function test_custom_max_links_parameter(): void
    {
        $body = 'https://a.com and https://b.com';
        self::assertFalse($this->heuristics->isLinkSpam($body, maxLinks: 5));
        self::assertTrue($this->heuristics->isLinkSpam($body, maxLinks: 1));
    }

    // -- Content length ---------------------------------------------------

    #[Test]
    public function test_body_at_10000_chars_passes(): void
    {
        $body = str_repeat('a', 10000);
        self::assertFalse($this->heuristics->isTooLong($body));
    }

    #[Test]
    public function test_body_at_10001_chars_fails(): void
    {
        $body = str_repeat('a', 10001);
        self::assertTrue($this->heuristics->isTooLong($body));
    }

    #[Test]
    public function test_custom_max_length_parameter(): void
    {
        $body = str_repeat('x', 500);
        self::assertFalse($this->heuristics->isTooLong($body, maxLength: 1000));
        self::assertTrue($this->heuristics->isTooLong($body, maxLength: 100));
    }

    // -- Excessive repetition ---------------------------------------------

    #[Test]
    public function test_normal_text_passes_repetition_check(): void
    {
        self::assertFalse($this->heuristics->hasExcessiveRepetition('This is a normal comment.'));
    }

    #[Test]
    public function test_ten_repeated_chars_detected(): void
    {
        self::assertTrue($this->heuristics->hasExcessiveRepetition('aaaaaaaaaa'));
    }

    #[Test]
    public function test_exclamation_spam_detected(): void
    {
        self::assertTrue($this->heuristics->hasExcessiveRepetition('Buy now!!!!!!!!!!'));
    }

    #[Test]
    public function test_nine_repeated_chars_passes(): void
    {
        self::assertFalse($this->heuristics->hasExcessiveRepetition('aaaaaaaaa'));
    }

    // -- Edge cases -------------------------------------------------------

    #[Test]
    public function test_empty_body_passes_all_checks(): void
    {
        self::assertFalse($this->heuristics->isLinkSpam(''));
        self::assertFalse($this->heuristics->isTooLong(''));
        self::assertFalse($this->heuristics->hasExcessiveRepetition(''));
    }

    #[Test]
    public function test_single_character_passes(): void
    {
        self::assertFalse($this->heuristics->isLinkSpam('a'));
        self::assertFalse($this->heuristics->isTooLong('a'));
        self::assertFalse($this->heuristics->hasExcessiveRepetition('a'));
    }

    // -- hashBody consistency ---------------------------------------------

    #[Test]
    public function test_hash_body_returns_consistent_hash(): void
    {
        $hash1 = $this->heuristics->hashBody('test body');
        $hash2 = $this->heuristics->hashBody('test body');

        self::assertSame($hash1, $hash2);
    }

    #[Test]
    public function test_hash_body_returns_different_hash_for_different_input(): void
    {
        $hash1 = $this->heuristics->hashBody('first');
        $hash2 = $this->heuristics->hashBody('second');

        self::assertNotSame($hash1, $hash2);
    }

    // -- Mixed URL and anchor detection -----------------------------------

    #[Test]
    public function test_mixed_urls_and_anchors_combined(): void
    {
        $body = '<a href="x">link</a> https://example.com https://other.com https://third.com';
        self::assertTrue($this->heuristics->isLinkSpam($body));
    }

    // -- Multibyte content length -----------------------------------------

    #[Test]
    public function test_multibyte_length_counted_correctly(): void
    {
        // Each emoji is 1 mb_strlen character
        $body = str_repeat("\u{1F600}", 10001);
        self::assertTrue($this->heuristics->isTooLong($body));
    }
}
