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
        bool $negotiateUnprefixedLocale = true,
        bool $courtesyRedirect = false,
        string $courtesyFallbackLocale = '',
        bool $localeCookieEnabled = false,
        string $localeCookieName = 'pulsar_locale',
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
            negotiateUnprefixedLocale: $negotiateUnprefixedLocale,
            courtesyRedirect: $courtesyRedirect,
            courtesyFallbackLocale: $courtesyFallbackLocale,
            localeCookieEnabled: $localeCookieEnabled,
            localeCookieName: $localeCookieName,
        );
    }

    private function echoDefaultNegotiator(): LocaleNegotiatorInterface
    {
        // Returns whatever default it is given — models "no supported preference
        // detected", so a courtesy redirect resolves to courtesy_fallback_locale.
        $negotiator = $this->createStub(LocaleNegotiatorInterface::class);
        $negotiator->method('negotiate')->willReturnCallback(
            static fn(ServerRequestInterface $r, array $supported, string $default): string => $default,
        );

        return $negotiator;
    }

    private function makeNegotiator(string $returns): LocaleNegotiatorInterface
    {
        $negotiator = $this->createStub(LocaleNegotiatorInterface::class);
        $negotiator->method('negotiate')->willReturn($returns);

        return $negotiator;
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
    public function recordsTheOriginalPrefixedUriBeforeStrippingIt(): void
    {
        $middleware = $this->makeMiddleware($this->makeConfig());
        $request = new ServerRequest(method: 'GET', uri: '/fr/docs');

        $originalPath = null;
        $rewrittenPath = null;
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->willReturnCallback(static function (ServerRequestInterface $r) use (&$originalPath, &$rewrittenPath): ResponseInterface {
                /** @var \Psr\Http\Message\UriInterface $original */
                $original = $r->getAttribute('_original_uri');
                $originalPath = $original->getPath();
                $rewrittenPath = $r->getUri()->getPath();

                return Response::text('OK');
            });

        $middleware->process($request, $handler);

        self::assertSame('/fr/docs', $originalPath, 'The original attribute must keep the locale-prefixed path');
        self::assertSame('/docs', $rewrittenPath, 'The live URI is stripped, but the original is preserved');
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

    #[Test]
    public function unprefixedLocaleIsDefaultWhenNegotiationDisabled(): void
    {
        $middleware = $this->makeMiddleware(
            $this->makeConfig(negotiateUnprefixedLocale: false),
            $this->makeNegotiator('fr'),
        );
        $request = new ServerRequest(method: 'GET', uri: '/docs');

        $capturedLocale = null;
        $capturedNegotiated = null;
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->willReturnCallback(static function (ServerRequestInterface $r) use (&$capturedLocale, &$capturedNegotiated): ResponseInterface {
                $capturedLocale = $r->getAttribute('_locale');
                $capturedNegotiated = $r->getAttribute('_negotiated_locale');

                return Response::text('OK');
            });

        $middleware->process($request, $handler);

        // Active locale is the default (URL-authoritative), not the negotiated one.
        self::assertSame('en', $capturedLocale);
        self::assertSame('en', $this->translator->locale);
        // The negotiated preference is still exposed for a courtesy redirect at /.
        self::assertSame('fr', $capturedNegotiated);
    }

    #[Test]
    public function unprefixedLocaleIsNegotiatedByDefault(): void
    {
        // Default (negotiate_unprefixed_locale = true) preserves prior behaviour.
        $middleware = $this->makeMiddleware($this->makeConfig(), $this->makeNegotiator('fr'));
        $request = new ServerRequest(method: 'GET', uri: '/docs');

        $capturedLocale = null;
        $capturedNegotiated = null;
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->willReturnCallback(static function (ServerRequestInterface $r) use (&$capturedLocale, &$capturedNegotiated): ResponseInterface {
                $capturedLocale = $r->getAttribute('_locale');
                $capturedNegotiated = $r->getAttribute('_negotiated_locale');

                return Response::text('OK');
            });

        $middleware->process($request, $handler);

        self::assertSame('fr', $capturedLocale);
        self::assertSame('fr', $capturedNegotiated);
    }

    #[Test]
    public function courtesyRedirectsNonDefaultLocaleToPrefix(): void
    {
        $middleware = $this->makeMiddleware(
            $this->makeConfig(defaultLocale: 'en', courtesyRedirect: true),
            $this->makeNegotiator('fr'),
        );
        $request = new ServerRequest(method: 'GET', uri: '/about');

        $response = $middleware->process($request, $this->createStub(RequestHandlerInterface::class));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/fr/about', $response->getHeaderLine('Location'));
        self::assertSame('Accept-Language, Cookie', $response->getHeaderLine('Vary'));
    }

    #[Test]
    public function courtesyRedirectPreservesQueryString(): void
    {
        $middleware = $this->makeMiddleware(
            $this->makeConfig(defaultLocale: 'en', courtesyRedirect: true),
            $this->makeNegotiator('de'),
        );
        $request = new ServerRequest(method: 'GET', uri: '/search?q=hello');

        $response = $middleware->process($request, $this->createStub(RequestHandlerInterface::class));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/de/search?q=hello', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function courtesyRedirectSkipsDefaultLocaleToAvoidLoop(): void
    {
        // The default locale keeps the canonical unprefixed URL; redirecting it
        // would loop with the canonical 301 strip.
        $middleware = $this->makeMiddleware(
            $this->makeConfig(defaultLocale: 'en', courtesyRedirect: true),
            $this->makeNegotiator('en'),
        );
        $request = new ServerRequest(method: 'GET', uri: '/about');

        $captured = null;
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willReturnCallback(
            static function (ServerRequestInterface $r) use (&$captured): ResponseInterface {
                $captured = $r->getAttribute('_locale');

                return Response::text('OK');
            },
        );

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('en', $captured);
    }

    #[Test]
    public function courtesyRedirectUsesArrivalFallbackForUndetectedVisitor(): void
    {
        // No supported preference -> negotiate returns the arrival fallback, which
        // is redirected because it differs from the default locale.
        $middleware = $this->makeMiddleware(
            $this->makeConfig(defaultLocale: 'en', courtesyRedirect: true, courtesyFallbackLocale: 'de'),
            $this->echoDefaultNegotiator(),
        );
        $request = new ServerRequest(method: 'GET', uri: '/about');

        $response = $middleware->process($request, $this->createStub(RequestHandlerInterface::class));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/de/about', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function courtesyRedirectIgnoredForNonGetRequests(): void
    {
        $middleware = $this->makeMiddleware(
            $this->makeConfig(defaultLocale: 'en', courtesyRedirect: true),
            $this->makeNegotiator('fr'),
        );
        $request = new ServerRequest(method: 'POST', uri: '/about');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willReturn(Response::text('OK'));

        self::assertSame(200, $middleware->process($request, $handler)->getStatusCode());
    }

    #[Test]
    public function persistsLocaleCookieOnPrefixedPage(): void
    {
        $middleware = $this->makeMiddleware(
            $this->makeConfig(defaultLocale: 'en', localeCookieEnabled: true),
            $this->makeNegotiator('fr'),
        );
        $request = new ServerRequest(method: 'GET', uri: '/fr/docs');

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $cookie = $middleware->process($request, $handler)->getHeaderLine('Set-Cookie');

        self::assertStringContainsString('pulsar_locale=fr', $cookie);
        self::assertStringContainsString('Path=/', $cookie);
        self::assertStringContainsString('SameSite=Lax', $cookie);
    }

    #[Test]
    public function persistsCustomCookieName(): void
    {
        $middleware = $this->makeMiddleware(
            $this->makeConfig(defaultLocale: 'en', localeCookieEnabled: true, localeCookieName: 'lang'),
            $this->makeNegotiator('de'),
        );
        $request = new ServerRequest(method: 'GET', uri: '/de/docs');

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        self::assertStringContainsString('lang=de', $middleware->process($request, $handler)->getHeaderLine('Set-Cookie'));
    }

    #[Test]
    public function noLocaleCookieWhenPersistenceDisabled(): void
    {
        $middleware = $this->makeMiddleware(
            $this->makeConfig(defaultLocale: 'en', localeCookieEnabled: false),
            $this->makeNegotiator('fr'),
        );
        $request = new ServerRequest(method: 'GET', uri: '/fr/docs');

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        self::assertSame('', $middleware->process($request, $handler)->getHeaderLine('Set-Cookie'));
    }

    #[Test]
    public function doesNotResendCookieWhenAlreadyEqualToTheServedLocale(): void
    {
        $middleware = $this->makeMiddleware(
            $this->makeConfig(defaultLocale: 'en', localeCookieEnabled: true),
            $this->makeNegotiator('fr'),
        );
        // Request already carries pulsar_locale=fr and lands on /fr/docs → no re-send.
        $request = new ServerRequest(method: 'GET', uri: '/fr/docs')->withCookieParams(['pulsar_locale' => 'fr']);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        self::assertSame('', $middleware->process($request, $handler)->getHeaderLine('Set-Cookie'));
    }
}
