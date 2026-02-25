<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Internal\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Internal\Service\ReferrerParser;

final class ReferrerParserTest extends TestCase
{
    private ReferrerParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ReferrerParser();
    }

    #[Test]
    public function emptyReferrerIsDirect(): void
    {
        $result = $this->parser->parse('', 'example.com');

        self::assertTrue($result->isDirect());
        self::assertSame('Direct / None', $result->source);
    }

    #[Test]
    public function whitespaceOnlyReferrerIsDirect(): void
    {
        $result = $this->parser->parse('   ', 'example.com');

        self::assertTrue($result->isDirect());
    }

    #[Test]
    public function invalidUrlIsDirect(): void
    {
        $result = $this->parser->parse('not-a-url', 'example.com');

        self::assertTrue($result->isDirect());
    }

    #[Test]
    public function sameDomainIsDirect(): void
    {
        $result = $this->parser->parse('https://example.com/about', 'example.com');

        self::assertTrue($result->isDirect());
    }

    #[Test]
    public function sameDomainWithWwwIsDirect(): void
    {
        $result = $this->parser->parse('https://www.example.com/page', 'example.com');

        self::assertTrue($result->isDirect());
    }

    #[Test]
    public function googleIsOrganic(): void
    {
        $result = $this->parser->parse('https://www.google.com/search?q=test', 'example.com');

        self::assertSame('Google', $result->source);
        self::assertSame('organic', $result->medium);
    }

    #[Test]
    public function bingIsOrganic(): void
    {
        $result = $this->parser->parse('https://www.bing.com/search?q=test', 'example.com');

        self::assertSame('Bing', $result->source);
        self::assertSame('organic', $result->medium);
    }

    #[Test]
    public function duckDuckGoIsOrganic(): void
    {
        $result = $this->parser->parse('https://duckduckgo.com/?q=test', 'example.com');

        self::assertSame('DuckDuckGo', $result->source);
        self::assertSame('organic', $result->medium);
    }

    #[Test]
    public function twitterShortDomainIsSocial(): void
    {
        $result = $this->parser->parse('https://t.co/abc123', 'example.com');

        self::assertSame('Twitter', $result->source);
        self::assertSame('social', $result->medium);
    }

    #[Test]
    public function xDotComIsSocialTwitter(): void
    {
        $result = $this->parser->parse('https://x.com/user/status/123', 'example.com');

        self::assertSame('Twitter', $result->source);
        self::assertSame('social', $result->medium);
    }

    #[Test]
    public function facebookIsSocial(): void
    {
        $result = $this->parser->parse('https://www.facebook.com/share', 'example.com');

        self::assertSame('Facebook', $result->source);
        self::assertSame('social', $result->medium);
    }

    #[Test]
    public function redditIsSocial(): void
    {
        $result = $this->parser->parse('https://www.reddit.com/r/php', 'example.com');

        self::assertSame('Reddit', $result->source);
        self::assertSame('social', $result->medium);
    }

    #[Test]
    public function utmParametersTakePriority(): void
    {
        $result = $this->parser->parse(
            'https://www.google.com/search?q=test&utm_source=newsletter&utm_medium=email&utm_campaign=spring',
            'example.com',
        );

        self::assertSame('newsletter', $result->source);
        self::assertSame('email', $result->medium);
        self::assertSame('spring', $result->campaign);
    }

    #[Test]
    public function unknownDomainIsReferral(): void
    {
        $result = $this->parser->parse('https://blog.somesite.org/post', 'example.com');

        self::assertSame('blog.somesite.org', $result->source);
        self::assertSame('referral', $result->medium);
    }

    #[Test]
    public function referrerUrlIsPreservedInRawUrl(): void
    {
        $url = 'https://www.google.com/search?q=test';
        $result = $this->parser->parse($url, 'example.com');

        self::assertSame($url, $result->rawUrl);
    }

    #[Test]
    public function yahooIsOrganic(): void
    {
        $result = $this->parser->parse('https://search.yahoo.com/search?p=test', 'example.com');

        self::assertSame('Yahoo', $result->source);
        self::assertSame('organic', $result->medium);
    }

    #[Test]
    public function youtubeIsSocial(): void
    {
        $result = $this->parser->parse('https://www.youtube.com/watch?v=abc', 'example.com');

        self::assertSame('YouTube', $result->source);
        self::assertSame('social', $result->medium);
    }
}
