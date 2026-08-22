<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam\AiCrawler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\AntiSpam\AiCrawler\AiCrawlerAction;
use Pulsar\Security\AntiSpam\AiCrawler\AiCrawlerCategory;
use Pulsar\Security\AntiSpam\AiCrawler\AiCrawlerConfig;

#[CoversClass(AiCrawlerConfig::class)]
final class AiCrawlerConfigTest extends TestCase
{
    #[Test]
    public function defaultsAreSafeAndOptIn(): void
    {
        $config = AiCrawlerConfig::fromArray([]);

        self::assertFalse($config->enabled, 'AI-crawler defense is opt-in');
        self::assertSame(AiCrawlerAction::Block, $config->trainingAction);
        self::assertSame(AiCrawlerAction::Allow, $config->assistantAction);
        self::assertSame(AiCrawlerAction::Allow, $config->searchAction);
        self::assertTrue($config->sendTdmReservation);
        self::assertSame([], $config->overrides);
        self::assertSame([], $config->customCrawlers);
    }

    #[Test]
    public function parsesCustomValues(): void
    {
        $config = AiCrawlerConfig::fromArray([
            'enabled' => true,
            'training_action' => 'rate_limit',
            'assistant_action' => 'block',
            'search_action' => 'block',
            'overrides' => ['GPTBot' => 'allow', 'CCBot' => 'block', 'Bogus' => 'not-an-action'],
            'custom_crawlers' => ['MyScraper' => 'training', 'Bad' => 'not-a-category'],
            'send_tdm_reservation' => false,
            'rate_limit_max_requests' => 10,
            'rate_limit_window_seconds' => 30,
        ]);

        self::assertTrue($config->enabled);
        self::assertSame(AiCrawlerAction::RateLimit, $config->trainingAction);
        self::assertSame(AiCrawlerAction::Block, $config->assistantAction);
        self::assertSame(['GPTBot' => AiCrawlerAction::Allow, 'CCBot' => AiCrawlerAction::Block], $config->overrides, 'invalid actions are dropped');
        self::assertSame(['MyScraper' => AiCrawlerCategory::Training], $config->customCrawlers, 'invalid categories are dropped');
        self::assertFalse($config->sendTdmReservation);
        self::assertSame(10, $config->rateLimitMaxRequests);
        self::assertSame(30, $config->rateLimitWindowSeconds);
    }

    #[Test]
    public function resolveActionPrefersOverrideThenCategoryDefault(): void
    {
        $config = AiCrawlerConfig::fromArray([
            'training_action' => 'block',
            'overrides' => ['GPTBot' => 'allow'],
        ]);

        // Override wins.
        self::assertSame(AiCrawlerAction::Allow, $config->resolveAction('GPTBot', AiCrawlerCategory::Training));
        // No override -> category default.
        self::assertSame(AiCrawlerAction::Block, $config->resolveAction('CCBot', AiCrawlerCategory::Training));
        self::assertSame(AiCrawlerAction::Allow, $config->resolveAction('ChatGPT-User', AiCrawlerCategory::Assistant));
    }
}
