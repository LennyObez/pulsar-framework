<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Http\Middleware\CmsLocaleMiddleware;
use Pulsar\Extension\Cms\I18n\LocaleResolver;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(CmsLocaleMiddleware::class)]
final class CmsLocaleMiddlewareTest extends TestCase
{
    #[Test]
    public function processSetsCmsLocaleAttribute(): void
    {
        $config = new CmsConfig(defaultLocale: 'en', supportedLocales: ['en', 'fr']);
        $resolver = new LocaleResolver();
        $middleware = new CmsLocaleMiddleware($resolver, $config);

        $request = new ServerRequest(method: 'GET', uri: '/fr/about');
        $capturedLocale = null;

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(
            function (ServerRequestInterface $req) use (&$capturedLocale): ResponseInterface {
                $capturedLocale = $req->getAttribute('cms_locale');
                $response = $this->createStub(ResponseInterface::class);
                $response->method('getStatusCode')->willReturn(200);
                return $response;
            },
        );

        $response = $middleware->process($request, $handler);

        self::assertSame('fr', $capturedLocale);
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function processUsesDefaultLocaleForUnprefixedPath(): void
    {
        $config = new CmsConfig(defaultLocale: 'en', supportedLocales: ['en', 'fr']);
        $resolver = new LocaleResolver();
        $middleware = new CmsLocaleMiddleware($resolver, $config);

        $request = new ServerRequest(method: 'GET', uri: '/about');
        $capturedLocale = null;

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(
            function (ServerRequestInterface $req) use (&$capturedLocale): ResponseInterface {
                $capturedLocale = $req->getAttribute('cms_locale');
                $response = $this->createStub(ResponseInterface::class);
                $response->method('getStatusCode')->willReturn(200);
                return $response;
            },
        );

        $middleware->process($request, $handler);

        self::assertSame('en', $capturedLocale);
    }

    #[Test]
    public function processReturns404ForUnsupportedLocale(): void
    {
        $config = new CmsConfig(defaultLocale: 'zz', supportedLocales: []);
        $resolver = new LocaleResolver();
        $middleware = new CmsLocaleMiddleware($resolver, $config);

        $request = new ServerRequest(method: 'GET', uri: '/some-page');
        $handler = $this->createStub(RequestHandlerInterface::class);

        $response = $middleware->process($request, $handler);

        self::assertSame(404, $response->getStatusCode());
    }
}
