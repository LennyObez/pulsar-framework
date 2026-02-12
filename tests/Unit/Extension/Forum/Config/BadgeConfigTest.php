<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Config\BadgeConfig;

#[CoversClass(BadgeConfig::class)]
final class BadgeConfigTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $config = new BadgeConfig();
        self::assertTrue($config->enabled);
        self::assertSame(10, $config->helpfulUpvoteThreshold);
        self::assertSame(50, $config->popularThreadViewThreshold);
        self::assertSame(10, $config->solverAcceptedAnswerThreshold);
        self::assertSame(5, $config->bugHunterConfirmedThreshold);
        self::assertSame(2, $config->multilingualLocaleThreshold);
    }

    #[Test]
    public function fromArrayWithEmptyArray(): void
    {
        $config = BadgeConfig::fromArray([]);
        self::assertTrue($config->enabled);
        self::assertSame(10, $config->helpfulUpvoteThreshold);
    }

    #[Test]
    public function fromArrayWithFullData(): void
    {
        $config = BadgeConfig::fromArray([
            'enabled' => false,
            'helpful_upvote_threshold' => 20,
            'popular_thread_view_threshold' => 100,
            'solver_accepted_answer_threshold' => 20,
            'bug_hunter_confirmed_threshold' => 10,
            'multilingual_locale_threshold' => 3,
        ]);
        self::assertFalse($config->enabled);
        self::assertSame(20, $config->helpfulUpvoteThreshold);
        self::assertSame(100, $config->popularThreadViewThreshold);
    }

    #[Test]
    public function fromArrayIgnoresNonIntValues(): void
    {
        $config = BadgeConfig::fromArray(['helpful_upvote_threshold' => 'nope']);
        self::assertSame(10, $config->helpfulUpvoteThreshold);
    }

    #[Test]
    public function fromArrayEnabledCastsToBool(): void
    {
        $config = BadgeConfig::fromArray(['enabled' => 0]);
        self::assertFalse($config->enabled);
    }
}
