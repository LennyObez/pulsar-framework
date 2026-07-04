<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam\AiCrawler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Security\AntiSpam\AiCrawler\AiCrawlerCategory;
use Pulsar\Security\AntiSpam\AiCrawler\AiCrawlerConfig;
use Pulsar\Security\AntiSpam\AiCrawler\AiCrawlerDetector;

#[CoversClass(AiCrawlerDetector::class)]
final class AiCrawlerDetectorTest extends TestCase
{
    #[Test]
    public function detectsTrainingCrawler(): void
    {
        $detected = $this->detector()->detect($this->requestWithUserAgent('Mozilla/5.0 (compatible; GPTBot/1.2; +https://openai.com/gptbot)'));

        self::assertNotNull($detected);
        self::assertSame('GPTBot', $detected->token);
        self::assertSame(AiCrawlerCategory::Training, $detected->category);
    }

    #[Test]
    public function classifiesAssistantAndSearchCrawlers(): void
    {
        self::assertSame(AiCrawlerCategory::Assistant, $this->detector()->detect($this->requestWithUserAgent('ChatGPT-User/1.0'))?->category);
        self::assertSame(AiCrawlerCategory::Search, $this->detector()->detect($this->requestWithUserAgent('Mozilla/5.0 (compatible; OAI-SearchBot/1.0)'))?->category);
    }

    #[Test]
    public function isCaseInsensitive(): void
    {
        self::assertSame('CCBot', $this->detector()->detect($this->requestWithUserAgent('ccbot/2.0'))?->token);
    }

    #[Test]
    public function ignoresRegularBrowsersAndEmptyUserAgent(): void
    {
        self::assertNull($this->detector()->detect($this->requestWithUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64)')));
        self::assertNull($this->detector()->detect($this->requestWithUserAgent('')));
    }

    #[Test]
    public function doesNotMatchRobotsTxtOnlyTokens(): void
    {
        // Google-Extended is a robots.txt opt-out token, never a request UA.
        self::assertNull($this->detector()->detect($this->requestWithUserAgent('Google-Extended')));
    }

    #[Test]
    public function detectsOperatorRegisteredCustomCrawler(): void
    {
        $detector = new AiCrawlerDetector(AiCrawlerConfig::fromArray([
            'custom_crawlers' => ['AcmeAiBot' => 'training'],
        ]));

        $detected = $detector->detect($this->requestWithUserAgent('AcmeAiBot/3.0 crawler'));

        self::assertNotNull($detected);
        self::assertSame('AcmeAiBot', $detected->token);
        self::assertSame(AiCrawlerCategory::Training, $detected->category);
    }

    private function detector(): AiCrawlerDetector
    {
        return new AiCrawlerDetector(AiCrawlerConfig::fromArray([]));
    }

    private function requestWithUserAgent(string $userAgent): ServerRequest
    {
        return new ServerRequest(method: 'GET', uri: '/', headers: ['User-Agent' => $userAgent]);
    }
}
