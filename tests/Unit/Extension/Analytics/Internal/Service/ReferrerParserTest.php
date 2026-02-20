<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Analytics\Internal\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Internal\Service\ReferrerParser;

#[CoversClass(ReferrerParser::class)]
final class ReferrerParserTest extends TestCase
{
    private ReferrerParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ReferrerParser();
    }

    #[Test]
    public function emptyReferrerReturnsDirect(): void
    {
        $result = $this->parser->parse('', 'example.com');

        self::assertTrue($result->isDirect());
        self::assertSame('Direct / None', $result->source);
        self::assertSame('none', $result->medium);
    }

    #[Test]
    public function sameDomainReturnsDirect(): void
    {
        $result = $this->parser->parse('https://example.com/about', 'example.com');

        self::assertTrue($result->isDirect());
    }

    #[Test]
    public function sameDomainWithWwwReturnsDirect(): void
    {
        $result = $this->parser->parse('https://www.example.com/about', 'example.com');

        self::assertTrue($result->isDirect());
    }

    #[Test]
    #[DataProvider('searchEngineReferrers')]
    public function detectsSearchEngines(string $referrerUrl, string $expectedSource): void
    {
        $result = $this->parser->parse($referrerUrl, 'example.com');

        self::assertSame('organic', $result->medium);
        self::assertSame($expectedSource, $result->source);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function searchEngineReferrers(): iterable
    {
        yield 'Google' => ['https://www.google.com/search?q=test', 'Google'];
        yield 'Bing' => ['https://www.bing.com/search?q=test', 'Bing'];
        yield 'Yahoo' => ['https://search.yahoo.com/search?p=test', 'Yahoo'];
        yield 'DuckDuckGo' => ['https://duckduckgo.com/?q=test', 'DuckDuckGo'];
        yield 'Baidu' => ['https://www.baidu.com/s?wd=test', 'Baidu'];
        yield 'Yandex' => ['https://yandex.ru/search/?text=test', 'Yandex'];
    }

    #[Test]
    #[DataProvider('socialNetworkReferrers')]
    public function detectsSocialNetworks(string $referrerUrl, string $expectedSource): void
    {
        $result = $this->parser->parse($referrerUrl, 'example.com');

        self::assertSame('social', $result->medium);
        self::assertSame($expectedSource, $result->source);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function socialNetworkReferrers(): iterable
    {
        yield 'Facebook' => ['https://www.facebook.com/share/abc', 'Facebook'];
        yield 'Twitter (t.co)' => ['https://t.co/abc123', 'Twitter'];
        yield 'Twitter (x.com)' => ['https://x.com/user/status/123', 'Twitter'];
        yield 'LinkedIn' => ['https://www.linkedin.com/feed/', 'LinkedIn'];
        yield 'Reddit' => ['https://www.reddit.com/r/php/', 'Reddit'];
        yield 'YouTube' => ['https://www.youtube.com/watch?v=abc', 'YouTube'];
        yield 'Pinterest' => ['https://www.pinterest.com/pin/123/', 'Pinterest'];
        yield 'Instagram' => ['https://www.instagram.com/p/abc/', 'Instagram'];
    }

    #[Test]
    public function detectsUtmParameters(): void
    {
        $result = $this->parser->parse(
            'https://partner.com/article?utm_source=newsletter&utm_medium=email&utm_campaign=spring2024',
            'example.com',
        );

        self::assertSame('newsletter', $result->source);
        self::assertSame('email', $result->medium);
        self::assertSame('spring2024', $result->campaign);
    }

    #[Test]
    public function utmSourceOnlyWithoutMediumOrCampaign(): void
    {
        $result = $this->parser->parse(
            'https://partner.com/page?utm_source=affiliate',
            'example.com',
        );

        self::assertSame('affiliate', $result->source);
        self::assertSame('', $result->medium);
        self::assertSame('', $result->campaign);
    }

    #[Test]
    public function unknownDomainReturnsReferral(): void
    {
        $result = $this->parser->parse('https://some-blog.org/article', 'example.com');

        self::assertSame('referral', $result->medium);
        self::assertSame('some-blog.org', $result->source);
    }

    #[Test]
    public function invalidUrlReturnsDirect(): void
    {
        $result = $this->parser->parse('not-a-valid-url', 'example.com');

        self::assertTrue($result->isDirect());
    }

    #[Test]
    public function rawUrlIsPreserved(): void
    {
        $url = 'https://www.google.com/search?q=pulsar+framework';
        $result = $this->parser->parse($url, 'example.com');

        self::assertSame($url, $result->rawUrl);
    }
}
