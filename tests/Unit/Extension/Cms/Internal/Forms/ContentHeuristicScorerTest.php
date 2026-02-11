<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Internal\Forms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Internal\Forms\ContentHeuristicScorer;

#[CoversClass(ContentHeuristicScorer::class)]
final class ContentHeuristicScorerTest extends TestCase
{
    #[Test]
    public function detectReturnsCleanForNormalContent(): void
    {
        $scorer = new ContentHeuristicScorer();
        $result = $scorer->detect(['body' => 'This is a normal message.'], []);

        self::assertFalse($result->isSpam);
        self::assertSame(0.0, $result->score);
        self::assertNull($result->reason);
    }

    #[Test]
    public function detectFlagsExcessiveUrls(): void
    {
        $body = 'Visit https://a.com https://b.com https://c.com https://d.com and more!';
        $scorer = new ContentHeuristicScorer();
        $result = $scorer->detect(['body' => $body], []);

        self::assertTrue($result->isSpam);
        self::assertSame(2.0, $result->score);
        assert(is_string($result->reason));
        self::assertStringContainsString('Excessive URLs', $result->reason);
    }

    #[Test]
    public function detectAllowsThreeOrFewerUrls(): void
    {
        $body = 'See https://a.com and https://b.com and https://c.com';
        $scorer = new ContentHeuristicScorer();
        $result = $scorer->detect(['body' => $body], []);

        self::assertFalse($result->isSpam);
        self::assertSame(0.0, $result->score);
    }

    #[Test]
    public function detectFlagsRepeatedCharacters(): void
    {
        $scorer = new ContentHeuristicScorer();
        $result = $scorer->detect(['body' => 'aaaaaaaaaaaaaa spam'], []);

        self::assertTrue($result->isSpam);
        self::assertSame(1.5, $result->score);
        assert(is_string($result->reason));
        self::assertStringContainsString('Repeated characters', $result->reason);
    }

    #[Test]
    public function detectAllowsTenOrFewerRepeatedCharacters(): void
    {
        $scorer = new ContentHeuristicScorer();
        $result = $scorer->detect(['body' => 'aaaaaaaaaa ok'], []);

        self::assertFalse($result->isSpam);
    }

    #[Test]
    public function detectFlagsAllCapsText(): void
    {
        $scorer = new ContentHeuristicScorer();
        $result = $scorer->detect(['body' => 'THIS IS ALL CAPS TEXT'], []);

        self::assertTrue($result->isSpam);
        self::assertSame(1.0, $result->score);
        assert(is_string($result->reason));
        self::assertStringContainsString('All-caps', $result->reason);
    }

    #[Test]
    public function detectIgnoresShortAllCapsText(): void
    {
        $scorer = new ContentHeuristicScorer();
        $result = $scorer->detect(['body' => 'HELLO OK'], []);

        self::assertFalse($result->isSpam);
    }

    #[Test]
    public function detectFlagsEmptyRequiredFields(): void
    {
        $scorer = new ContentHeuristicScorer(requiredFields: ['name', 'email']);
        $result = $scorer->detect(['name' => '', 'email' => ''], []);

        self::assertTrue($result->isSpam);
        self::assertSame(6.0, $result->score);
        assert(is_string($result->reason));
        self::assertStringContainsString('Required field empty: name', $result->reason);
        self::assertStringContainsString('Required field empty: email', $result->reason);
    }

    #[Test]
    public function detectFlagsMissingRequiredFields(): void
    {
        $scorer = new ContentHeuristicScorer(requiredFields: ['name']);
        $result = $scorer->detect([], []);

        self::assertTrue($result->isSpam);
        self::assertSame(3.0, $result->score);
        assert(is_string($result->reason));
        self::assertStringContainsString('Required field empty: name', $result->reason);
    }

    #[Test]
    public function detectAcceptsFilledRequiredFields(): void
    {
        $scorer = new ContentHeuristicScorer(requiredFields: ['name']);
        $result = $scorer->detect(['name' => 'John'], []);

        self::assertFalse($result->isSpam);
    }

    #[Test]
    public function detectAggregatesMultipleSignals(): void
    {
        $body = 'AAAAAAAAAAAAAAA https://a.com https://b.com https://c.com https://d.com';
        $scorer = new ContentHeuristicScorer(requiredFields: ['email']);
        $result = $scorer->detect(['body' => $body], []);

        // Repeated chars (1.5) + excessive URLs (2.0) + missing email (3.0) = 6.5
        // (all-caps doesn't fire because URLs are lowercase, so $trimmed !== mb_strtoupper($trimmed))
        self::assertTrue($result->isSpam);
        self::assertSame(6.5, $result->score);
    }

    #[Test]
    public function detectSkipsNonStringValues(): void
    {
        $scorer = new ContentHeuristicScorer();
        $result = $scorer->detect(['count' => 42, 'active' => true, 'tags' => ['a', 'b']], []);

        self::assertFalse($result->isSpam);
    }
}
