<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Reaction;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Reaction\Reaction;
use Pulsar\Extension\Forum\Reaction\ReactionSummary;
use Pulsar\Extension\Forum\Reaction\ReactionType;

final class ReactionTest extends TestCase
{
    #[Test]
    public function reactionStoresAllProperties(): void
    {
        $reaction = new Reaction(
            id: 'r-1',
            postId: 'post-1',
            userId: 'user-1',
            type: ReactionType::Heart,
        );

        self::assertSame('r-1', $reaction->id);
        self::assertSame('post-1', $reaction->postId);
        self::assertSame('user-1', $reaction->userId);
        self::assertSame(ReactionType::Heart, $reaction->type);
    }

    #[Test]
    #[DataProvider('emojiProvider')]
    public function reactionTypeReturnsCorrectEmoji(ReactionType $type, string $expected): void
    {
        self::assertSame($expected, $type->emoji());
    }

    /**
     * @return iterable<string, array{ReactionType, string}>
     */
    public static function emojiProvider(): iterable
    {
        yield 'thumbs up' => [ReactionType::ThumbsUp, "\u{1F44D}"];
        yield 'heart' => [ReactionType::Heart, "\u{2764}\u{FE0F}"];
        yield 'laugh' => [ReactionType::Laugh, "\u{1F604}"];
        yield 'rocket' => [ReactionType::Rocket, "\u{1F680}"];
        yield 'fire' => [ReactionType::Fire, "\u{1F525}"];
    }

    #[Test]
    public function allReactionTypesHaveEmojis(): void
    {
        foreach (ReactionType::cases() as $type) {
            $emoji = $type->emoji();
            self::assertNotEmpty($emoji, "Reaction type {$type->name} has empty emoji");
        }

        self::assertCount(10, ReactionType::cases());
    }

    #[Test]
    public function reactionSummaryCountsCorrectly(): void
    {
        $summary = new ReactionSummary(
            postId: 'post-1',
            counts: [
                'thumbs_up' => 10,
                'heart' => 5,
                'laugh' => 3,
            ],
            total: 18,
        );

        self::assertSame(10, $summary->countFor(ReactionType::ThumbsUp));
        self::assertSame(5, $summary->countFor(ReactionType::Heart));
        self::assertSame(3, $summary->countFor(ReactionType::Laugh));
        self::assertSame(0, $summary->countFor(ReactionType::Fire));
        self::assertSame(18, $summary->total);
    }

    #[Test]
    public function reactionSummaryHandlesEmptyCounts(): void
    {
        $summary = new ReactionSummary(
            postId: 'post-2',
            counts: [],
            total: 0,
        );

        self::assertSame(0, $summary->countFor(ReactionType::ThumbsUp));
        self::assertSame(0, $summary->total);
    }
}
