<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n\Locale;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Config\I18nConfig;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\I18n\Locale\LocalePrefixMiddleware;
use Pulsar\I18n\Locale\LocaleUrlStrategy;
use Pulsar\I18n\Locale\UrlPrefixExtractor;
use Pulsar\I18n\LocaleNegotiatorInterface;
use Pulsar\I18n\TranslatorInterface;

#[CoversClass(LocalePrefixMiddleware::class)]
final class LocalePrefixMiddlewareTest extends TestCase
{
    private UrlPrefixExtractor $extractor;

    /** @var TranslatorInterface&object{locale: string} */
    private TranslatorInterface $translator;

    protected function setUp(): void
    {
        $this->extractor = new UrlPrefixExtractor();

        $this->translator = new class implements TranslatorInterface {
            public string $locale = '';

            public function translate(string $key, array $parameters = [], ?string $locale = null, string $domain = 'messages'): string
            {
                return $key;
            }

            public function has(string $key, ?string $locale = null, string $domain = 'messages'): bool
            {
                return false;
            }
        };
    }

    /**
     * @param list<string> $supportedLocales
     */
    private function makeConfig(
        string $defaultLocale = 'en',
        array $supportedLocales = ['en', 'fr', 'de'],
        bool $canonicalRedirect = true,
        bool $defaultLocaleInUrl = false,
    ): I18nConfig {
        return new I18nConfig(
            defaultLocale: $defaultLocale,
            supportedLocales: $supportedLocales,
            fallbackLocales: ['en'],
            catalogPath: null,
            regulated: false,
            maxSupportedLocales: 50,
            strictMode: false,
            urlStrategy: LocaleUrlStrategy::PathPrefix,
            defaultLocaleInUrl: $defaultLocaleInUrl,
            canonicalRedirect: $canonicalRedirect,
        );
    }

    private function makeMiddleware(
        I18nConfig $config,
        ?LocaleNegotiatorInterface $negotiator = null,
    ): LocalePrefixMiddleware {
        $negotiator ??= $this->createStub(LocaleNegotiatorInterface::class);

        return new LocalePrefixMiddleware(
            extractor: $this->extractor,
            negotiator: $negotiator,
            config: $config,
            translator: $this->translator,
        );
    }

    #[Test]
    public function stripsPrefixAndSetsLocaleForSupportedLocale(): void
    {
        $middleware = $this->makeMiddleware($this->makeConfig());
        $request = new ServerRequest(method: 'GET', uri: '/fr/docs');

        $capturedLocale = null;
        $capturedPath = null;
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->willReturnCallback(static function (ServerRequestInterface $r) use (&$capturedLocale, &$capturedPath): ResponseInterface {
                $capturedLocale = $r->getAttribute('_locale');
                $capturedPath = $r->getUri()->getPath();

                return Response::text('OK');
            });

        $middleware->process($request, $handler);

        self::assertSame('fr', $capturedLocale);
        self::assertSame('/docs', $capturedPath);
        self::assertSame('fr', $this->translator->locale);
    }

    #[Test]
    public function canonicalRedirectForDefaultLocaleOnGet(): void
    {
        $middleware = $this->makeMiddleware($this->makeConfig());
        $request = new ServerRequest(method: 'GET', uri: '/en/docs');

        $handler = $this->createStub(RequestHandlerInterface::class);

        $response = $middleware->process($request, $handler);

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/docs', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function canonicalRedirectForDefaultLocaleOnHead(): void
    {
        $middleware = $this->makeMiddleware($this->makeConfig());
        $request = new ServerRequest(method: 'HEAD', uri: '/en/docs');

        $handler = $this->createStub(RequestHandlerInterface::class);

        $response = $middleware->process($request, $handler);

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/docs', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function noRedirectForPostWithDefaultLocale(): void
    {
        $middleware = $this->makeMiddleware($this->makeConfig());
        $request = new ServerRequest(method: 'POST', uri: '/en/docs');

        $capturedLocale = null;
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->willReturnCallback(static function (ServerRequestInterface $r) use (&$capturedLocale): ResponseInterface {
                $capturedLocale = $r->getAttribute('_locale');

                return Response::text('OK');
            });

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('en', $capturedLocale);
    }

    #[Test]
    public function noRedirectWhenCanonicalRedirectDisabled(): void
    {
        $middleware = $this->makeMiddleware($this->makeConfig(canonicalRedirect: false));
        $request = new ServerRequest(method: 'GET', uri: '/en/docs');

        $capturedLocale = null;
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->willReturnCallback(static function (ServerRequestInterface $r) use (&$capturedLocale): ResponseInterface {
                $capturedLocale = $r->getAttribute('_locale');

                return Response::text('OK');
            });

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('en', $capturedLocale);
    }

    #[Test]
    public function noRedirectWhenDefaultLocaleInUrl(): void
    {
        $middleware = $this->makeMiddleware($this->makeConfig(defaultLocaleInUrl: true));
        $request = new ServerRequest(method: 'GET', uri: '/en/docs');

        $capturedLocale = null;
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->willReturnCallback(static function (ServerRequestInterface $r) use (&$capturedLocale): ResponseInterface {
                $capturedLocale = $r->getAttribute('_locale');

                return Response::text('OK');
            });

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('en', $capturedLocale);
    }

    #[Test]
    public function fallsBackToNegotiatorWhenNoPrefix(): void
    {
        $negotiator = $this->createStub(LocaleNegotiatorInterface::class);
        $negotiator->method('negotiate')->willReturn('de');

        $middleware = $this->makeMiddleware($this->makeConfig(), $negotiator);
        $request = new ServerRequest(method: 'GET', uri: '/docs/intro');

        $capturedLocale = null;
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->willReturnCallback(static function (ServerRequestInterface $r) use (&$capturedLocale): ResponseInterface {
                $capturedLocale = $r->getAttribute('_locale');

                return Response::text('OK');
            });

        $middleware->process($request, $handler);

        self::assertSame('de', $capturedLocale);
        self::assertSame('de', $this->translator->locale);
    }

    #[Test]
    public function setsLocalePrefixAttribute(): void
    {
        $middleware = $this->makeMiddleware($this->makeConfig());
        $request = new ServerRequest(method: 'GET', uri: '/fr/about');

        $capturedPrefix = null;
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->willReturnCallback(static function (ServerRequestInterface $r) use (&$capturedPrefix): ResponseInterface {
                $capturedPrefix = $r->getAttribute('_locale_prefix');

                return Response::text('OK');
            });

        $middleware->process($request, $handler);

        self::assertSame('fr', $capturedPrefix);
    }

    #[Test]
    public function invalidLocaleInPathPassesToRouter(): void
    {
        $negotiator = $this->createStub(LocaleNegotiatorInterface::class);
        $negotiator->method('negotiate')->willReturn('en');

        $middleware = $this->makeMiddleware($this->makeConfig(), $negotiator);
        $request = new ServerRequest(method: 'GET', uri: '/it/page');

        $capturedPath = null;
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->willReturnCallback(static function (ServerRequestInterface $r) use (&$capturedPath): ResponseInterface {
                $capturedPath = $r->getUri()->getPath();

                return Response::text('OK');
            });

        $middleware->process($request, $handler);

        self::assertSame('/it/page', $capturedPath);
    }

    #[Test]
    public function pathTraversalDoesNotExtractLocale(): void
    {
        $negotiator = $this->createStub(LocaleNegotiatorInterface::class);
        $negotiator->method('negotiate')->willReturn('en');

        $middleware = $this->makeMiddleware($this->makeConfig(), $negotiator);
        $request = new ServerRequest(method: 'GET', uri: '/fr/../etc/passwd');

        $capturedLocale = null;
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->willReturnCallback(static function (ServerRequestInterface $r) use (&$capturedLocale): ResponseInterface {
                $capturedLocale = $r->getAttribute('_locale');

                return Response::text('OK');
            });

        $middleware->process($request, $handler);

        self::assertSame('en', $capturedLocale);
    }

    #[Test]
    public function noPrefixAttributeWhenNoPrefixFound(): void
    {
        $negotiator = $this->createStub(LocaleNegotiatorInterface::class);
        $negotiator->method('negotiate')->willReturn('en');

        $middleware = $this->makeMiddleware($this->makeConfig(), $negotiator);
        $request = new ServerRequest(method: 'GET', uri: '/docs');

        $capturedPrefix = null;
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->willReturnCallback(static function (ServerRequestInterface $r) use (&$capturedPrefix): ResponseInterface {
                $capturedPrefix = $r->getAttribute('_locale_prefix');

                return Response::text('OK');
            });

        $middleware->process($request, $handler);

        self::assertNull($capturedPrefix);
    }

    #[Test]
    public function canonicalRedirectForRootDefaultLocale(): void
    {
        $middleware = $this->makeMiddleware($this->makeConfig());
        $request = new ServerRequest(method: 'GET', uri: '/en');

        $handler = $this->createStub(RequestHandlerInterface::class);

        $response = $middleware->process($request, $handler);

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function canonicalRedirectPreservesQueryString(): void
    {
        $middleware = $this->makeMiddleware($this->makeConfig());
        $request = new ServerRequest(method: 'GET', uri: '/en/docs?page=2&sort=title');

        $handler = $this->createStub(RequestHandlerInterface::class);

        $response = $middleware->process($request, $handler);

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/docs?page=2&sort=title', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function canonicalRedirectWithEmptyQueryStringOmitsQuestionMark(): void
    {
        $middleware = $this->makeMiddleware($this->makeConfig());
        $request = new ServerRequest(method: 'GET', uri: '/en/docs');

        $handler = $this->createStub(RequestHandlerInterface::class);

        $response = $middleware->process($request, $handler);

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/docs', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function doubleSlashAfterPrefixCollapsedInUriRewrite(): void
    {
        $middleware = $this->makeMiddleware($this->makeConfig());
        $request = new ServerRequest(method: 'GET', uri: '/fr//evil.com');

        $capturedPath = null;
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->willReturnCallback(static function (ServerRequestInterface $r) use (&$capturedPath): ResponseInterface {
                $capturedPath = $r->getUri()->getPath();

                return Response::text('OK');
            });

        $middleware->process($request, $handler);

        // Collapsed from //evil.com to /evil.com — no protocol-relative path
        self::assertSame('/evil.com', $capturedPath);
    }
}
