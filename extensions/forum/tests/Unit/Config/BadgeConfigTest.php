<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Config\BadgeConfig;

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
    public function fromArrayWithDefaults(): void
    {
        $config = BadgeConfig::fromArray([]);

        self::assertTrue($config->enabled);
        self::assertSame(10, $config->helpfulUpvoteThreshold);
    }

    #[Test]
    public function fromArrayWithCustomValues(): void
    {
        $config = BadgeConfig::fromArray([
            'enabled' => false,
            'helpful_upvote_threshold' => 20,
            'popular_thread_view_threshold' => 100,
            'solver_accepted_answer_threshold' => 5,
            'bug_hunter_confirmed_threshold' => 3,
            'multilingual_locale_threshold' => 4,
        ]);

        self::assertFalse($config->enabled);
        self::assertSame(20, $config->helpfulUpvoteThreshold);
        self::assertSame(100, $config->popularThreadViewThreshold);
        self::assertSame(5, $config->solverAcceptedAnswerThreshold);
        self::assertSame(3, $config->bugHunterConfirmedThreshold);
        self::assertSame(4, $config->multilingualLocaleThreshold);
    }

    #[Test]
    public function fromArrayIgnoresNonIntegerThresholds(): void
    {
        $config = BadgeConfig::fromArray([
            'helpful_upvote_threshold' => 'many',
        ]);

        self::assertSame(10, $config->helpfulUpvoteThreshold);
    }
}
