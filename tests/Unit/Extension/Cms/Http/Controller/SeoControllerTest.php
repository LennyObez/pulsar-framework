<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Extension\Cms\Config\SeoConfig;
use Pulsar\Extension\Cms\Http\Controller\SeoController;
use Pulsar\Extension\Cms\Seo\RobotsTxtGeneratorInterface;

#[CoversClass(SeoController::class)]
final class SeoControllerTest extends TestCase
{
    #[Test]
    public function googleVerificationReturnsHtmlWhenConfigured(): void
    {
        $seoConfig = new SeoConfig(googleSiteVerification: 'abc123xyz');
        $robotsTxt = $this->createStub(RobotsTxtGeneratorInterface::class);
        $controller = new SeoController($robotsTxt, $seoConfig);
        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller->googleVerification();

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('google-site-verification:', $body);
        self::assertStringContainsString('googleabc123xyz.html', $body);
    }

    #[Test]
    public function googleVerificationReturns404WhenNotConfigured(): void
    {
        $seoConfig = new SeoConfig(googleSiteVerification: null);
        $robotsTxt = $this->createStub(RobotsTxtGeneratorInterface::class);
        $controller = new SeoController($robotsTxt, $seoConfig);
        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller->googleVerification();

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function googleVerificationReturns404WhenEmpty(): void
    {
        $seoConfig = new SeoConfig(googleSiteVerification: '');
        $robotsTxt = $this->createStub(RobotsTxtGeneratorInterface::class);
        $controller = new SeoController($robotsTxt, $seoConfig);
        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller->googleVerification();

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function bingVerificationReturnsXmlWhenConfigured(): void
    {
        $seoConfig = new SeoConfig(bingSiteVerification: 'BING_CODE_456');
        $robotsTxt = $this->createStub(RobotsTxtGeneratorInterface::class);
        $controller = new SeoController($robotsTxt, $seoConfig);
        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller->bingVerification();

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('<user>BING_CODE_456</user>', $body);
        self::assertStringContainsString('<?xml version="1.0"?>', $body);

        $contentType = $response->getHeaderLine('Content-Type');
        self::assertStringContainsString('application/xml', $contentType);
    }

    #[Test]
    public function bingVerificationReturns404WhenNotConfigured(): void
    {
        $seoConfig = new SeoConfig(bingSiteVerification: null);
        $robotsTxt = $this->createStub(RobotsTxtGeneratorInterface::class);
        $controller = new SeoController($robotsTxt, $seoConfig);
        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller->bingVerification();

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function bingVerificationReturns404WhenEmpty(): void
    {
        $seoConfig = new SeoConfig(bingSiteVerification: '');
        $robotsTxt = $this->createStub(RobotsTxtGeneratorInterface::class);
        $controller = new SeoController($robotsTxt, $seoConfig);
        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller->bingVerification();

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function googleVerificationEscapesHtmlInCode(): void
    {
        $seoConfig = new SeoConfig(googleSiteVerification: '<script>alert("xss")</script>');
        $robotsTxt = $this->createStub(RobotsTxtGeneratorInterface::class);
        $controller = new SeoController($robotsTxt, $seoConfig);
        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller->googleVerification();
        $body = (string) $response->getBody();

        self::assertStringNotContainsString('<script>', $body);
    }

    #[Test]
    public function bingVerificationEscapesXmlInCode(): void
    {
        $seoConfig = new SeoConfig(bingSiteVerification: '<script>alert("xss")</script>');
        $robotsTxt = $this->createStub(RobotsTxtGeneratorInterface::class);
        $controller = new SeoController($robotsTxt, $seoConfig);
        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller->bingVerification();
        $body = (string) $response->getBody();

        self::assertStringNotContainsString('<script>alert', $body);
    }

    #[Test]
    public function verificationResponsesHaveCacheControlHeader(): void
    {
        $seoConfig = new SeoConfig(
            googleSiteVerification: 'test',
            bingSiteVerification: 'test',
        );
        $robotsTxt = $this->createStub(RobotsTxtGeneratorInterface::class);
        $controller = new SeoController($robotsTxt, $seoConfig);
        $request = $this->createStub(ServerRequestInterface::class);

        $googleResponse = $controller->googleVerification();
        self::assertSame('public, max-age=86400', $googleResponse->getHeaderLine('Cache-Control'));

        $bingResponse = $controller->bingVerification();
        self::assertSame('public, max-age=86400', $bingResponse->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function robotsTxtDelegatesToGenerator(): void
    {
        $seoConfig = new SeoConfig();
        $robotsTxt = $this->createStub(RobotsTxtGeneratorInterface::class);
        $robotsTxt->method('generate')->willReturn("User-agent: *\nDisallow:");

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getScheme')->willReturn('https');
        $uri->method('getHost')->willReturn('example.com');
        $uri->method('getPort')->willReturn(null);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $controller = new SeoController($robotsTxt, $seoConfig);
        $response = $controller->robotsTxt($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('User-agent: *', (string) $response->getBody());
    }
}
