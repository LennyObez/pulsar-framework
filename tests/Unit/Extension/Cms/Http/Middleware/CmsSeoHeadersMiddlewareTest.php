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
use Pulsar\Extension\Cms\Config\SeoConfig;
use Pulsar\Extension\Cms\Http\Middleware\CmsSeoHeadersMiddleware;
use Pulsar\Http\Message\Response;

#[CoversClass(CmsSeoHeadersMiddleware::class)]
final class CmsSeoHeadersMiddlewareTest extends TestCase
{
    #[Test]
    public function processAddsXRobotsTagHeader(): void
    {
        $config = new CmsConfig(seo: new SeoConfig(defaultRobots: 'noindex, nofollow'));
        $middleware = new CmsSeoHeadersMiddleware($config);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn(null);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::json(['ok' => true]));

        $response = $middleware->process($request, $handler);

        self::assertSame('noindex, nofollow', $response->getHeaderLine('X-Robots-Tag'));
    }

    #[Test]
    public function processAddsContentLanguageWhenLocalePresent(): void
    {
        $config = new CmsConfig();
        $middleware = new CmsSeoHeadersMiddleware($config);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name): mixed => $name === 'locale' ? 'fr' : null,
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::json(['ok' => true]));

        $response = $middleware->process($request, $handler);

        self::assertSame('fr', $response->getHeaderLine('Content-Language'));
    }

    #[Test]
    public function processOmitsContentLanguageWhenNoLocale(): void
    {
        $config = new CmsConfig();
        $middleware = new CmsSeoHeadersMiddleware($config);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn(null);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::json(['ok' => true]));

        $response = $middleware->process($request, $handler);

        self::assertFalse($response->hasHeader('Content-Language'));
    }

    #[Test]
    public function processUsesDefaultRobotsFromConfig(): void
    {
        $config = new CmsConfig(seo: new SeoConfig(defaultRobots: 'index, follow'));
        $middleware = new CmsSeoHeadersMiddleware($config);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn(null);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::json(['ok' => true]));

        $response = $middleware->process($request, $handler);

        self::assertSame('index, follow', $response->getHeaderLine('X-Robots-Tag'));
    }
}
