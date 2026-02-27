<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Feed;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Http\Controller\Api\FeedController;
use Pulsar\Extension\Cms\Seo\FeedGeneratorInterface;

#[CoversClass(FeedController::class)]
final class FeedControllerTest extends TestCase
{
    private FeedGeneratorInterface&Stub $feedGenerator;
    private CmsConfig $config;

    protected function setUp(): void
    {
        $this->feedGenerator = $this->createStub(FeedGeneratorInterface::class);
        $this->config = new CmsConfig(defaultLocale: 'en', supportedLocales: ['en', 'fr']);
    }

    #[Test]
    public function rssReturnsXmlWithCorrectContentType(): void
    {
        $this->feedGenerator->method('generateRss')
            ->willReturn('<?xml version="1.0"?><rss version="2.0"><channel></channel></rss>');

        $controller = new FeedController($this->feedGenerator, $this->config);
        $request = $this->buildRequest(locale: null);

        $response = $controller->rss($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString(
            'application/rss+xml',
            $response->getHeaderLine('Content-Type'),
        );
        self::assertStringContainsString('<rss version="2.0"', (string) $response->getBody());
    }

    #[Test]
    public function atomReturnsXmlWithCorrectContentType(): void
    {
        $this->feedGenerator->method('generateAtom')
            ->willReturn('<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"></feed>');

        $controller = new FeedController($this->feedGenerator, $this->config);
        $request = $this->buildRequest(locale: 'en');

        $response = $controller->atom($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString(
            'application/atom+xml',
            $response->getHeaderLine('Content-Type'),
        );
        self::assertStringContainsString('<feed xmlns', (string) $response->getBody());
    }

    #[Test]
    public function rssReturns404ForUnsupportedLocale(): void
    {
        $controller = new FeedController($this->feedGenerator, $this->config);
        $request = $this->buildRequest(locale: 'de');

        $response = $controller->rss($request);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('Unsupported locale', (string) $response->getBody());
    }

    #[Test]
    public function atomReturns404ForUnsupportedLocale(): void
    {
        $controller = new FeedController($this->feedGenerator, $this->config);
        $request = $this->buildRequest(locale: 'de');

        $response = $controller->atom($request);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function rssUsesDefaultLocaleWhenNoneProvided(): void
    {
        $this->feedGenerator->method('generateRss')
            ->willReturn('<?xml version="1.0"?><rss></rss>');

        $controller = new FeedController($this->feedGenerator, $this->config);
        $request = $this->buildRequest(locale: null);

        $response = $controller->rss($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function responsesIncludeCacheControlHeader(): void
    {
        $this->feedGenerator->method('generateRss')
            ->willReturn('<?xml version="1.0"?><rss></rss>');

        $controller = new FeedController($this->feedGenerator, $this->config);
        $request = $this->buildRequest(locale: 'en');

        $response = $controller->rss($request);

        self::assertStringContainsString('public', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('max-age=', $response->getHeaderLine('Cache-Control'));
    }

    private function buildRequest(?string $locale): ServerRequestInterface&Stub
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getScheme')->willReturn('https');
        $uri->method('getHost')->willReturn('example.com');
        $uri->method('getPort')->willReturn(null);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name) => match ($name) {
                'locale' => $locale,
                default => null,
            },
        );
        $request->method('getUri')->willReturn($uri);

        return $request;
    }
}
