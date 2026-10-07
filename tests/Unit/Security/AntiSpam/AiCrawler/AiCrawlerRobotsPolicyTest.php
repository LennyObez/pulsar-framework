<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam\AiCrawler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\AntiSpam\AiCrawler\AiCrawlerAction;
use Pulsar\Security\AntiSpam\AiCrawler\AiCrawlerCategory;
use Pulsar\Security\AntiSpam\AiCrawler\AiCrawlerConfig;
use Pulsar\Security\AntiSpam\AiCrawler\AiCrawlerRobotsPolicy;

#[CoversClass(AiCrawlerRobotsPolicy::class)]
final class AiCrawlerRobotsPolicyTest extends TestCase
{
    #[Test]
    public function returnsNothingWhenDisabled(): void
    {
        $policy = new AiCrawlerRobotsPolicy(new AiCrawlerConfig(enabled: false));

        self::assertSame('', $policy->directives());
    }

    #[Test]
    public function blocksTrainingCrawlersByDefaultWhenEnabled(): void
    {
        // Default training action is Block.
        $policy = new AiCrawlerRobotsPolicy(new AiCrawlerConfig(enabled: true));

        $directives = $policy->directives();

        self::assertStringContainsString("User-agent: GPTBot\nDisallow: /", $directives);
        self::assertStringContainsString("User-agent: CCBot\nDisallow: /", $directives);
    }

    #[Test]
    public function coversTheRobotsOnlyOptOutTokens(): void
    {
        // Google-Extended / Applebot-Extended are never request User-Agents,
        // so robots.txt is their only control point — they must appear.
        $policy = new AiCrawlerRobotsPolicy(new AiCrawlerConfig(enabled: true));

        $directives = $policy->directives();

        self::assertStringContainsString('User-agent: Google-Extended', $directives);
        self::assertStringContainsString('User-agent: Applebot-Extended', $directives);
    }

    #[Test]
    public function doesNotBlockAllowedAssistantOrSearchCrawlers(): void
    {
        $policy = new AiCrawlerRobotsPolicy(new AiCrawlerConfig(enabled: true));

        $directives = $policy->directives();

        // Assistant + search default to Allow → no Disallow block for them.
        self::assertStringNotContainsString('User-agent: ChatGPT-User', $directives);
        self::assertStringNotContainsString('User-agent: OAI-SearchBot', $directives);
    }

    #[Test]
    public function rateLimitedCrawlersAreNotDisallowed(): void
    {
        $policy = new AiCrawlerRobotsPolicy(new AiCrawlerConfig(
            enabled: true,
            trainingAction: AiCrawlerAction::RateLimit,
        ));

        // RateLimit is enforced at the middleware, not expressible in robots.txt.
        self::assertSame('', $policy->directives());
    }

    #[Test]
    public function perCrawlerOverrideWins(): void
    {
        $policy = new AiCrawlerRobotsPolicy(new AiCrawlerConfig(
            enabled: true,
            overrides: ['GPTBot' => AiCrawlerAction::Allow],
        ));

        $directives = $policy->directives();

        self::assertStringNotContainsString('User-agent: GPTBot', $directives);
        self::assertStringContainsString('User-agent: CCBot', $directives, 'other training crawlers still blocked');
    }

    #[Test]
    public function includesBlockedCustomCrawlers(): void
    {
        $policy = new AiCrawlerRobotsPolicy(new AiCrawlerConfig(
            enabled: true,
            customCrawlers: ['MyScraperBot' => AiCrawlerCategory::Training],
        ));

        self::assertStringContainsString("User-agent: MyScraperBot\nDisallow: /", $policy->directives());
    }

    #[Test]
    public function carriesTheManagedHeaderComment(): void
    {
        $policy = new AiCrawlerRobotsPolicy(new AiCrawlerConfig(enabled: true));

        self::assertStringContainsString('# AI crawlers (managed by Pulsar AntiSpam)', $policy->directives());
    }
}
