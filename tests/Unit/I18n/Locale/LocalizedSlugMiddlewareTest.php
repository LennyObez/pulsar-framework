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
use Pulsar\Http\Message\Uri;
use Pulsar\I18n\Locale\LocaleUrlStrategy;
use Pulsar\I18n\Locale\LocalizedSlugMiddleware;
use Pulsar\I18n\Locale\SlugRegistry;
use Pulsar\I18n\Locale\UrlPrefixExtractor;

#[CoversClass(LocalizedSlugMiddleware::class)]
final class LocalizedSlugMiddlewareTest extends TestCase
{
    /**
     * @return array<string, array<string, string>>
     */
    private function slugs(): array
    {
        return [
            'development' => ['fr' => 'developpement', 'nl' => 'ontwikkeling'],
            'development/projects' => ['fr' => 'developpement/projets', 'nl' => 'ontwikkeling/projecten'],
        ];
    }

    /**
     * @param array<string, array<string, string>> $slugs
     */
    private function makeMiddleware(
        array $slugs,
        bool $canonicalRedirect = true,
        bool $defaultLocaleInUrl = false,
    ): LocalizedSlugMiddleware {
        $config = new I18nConfig(
            defaultLocale: 'en',
            supportedLocales: ['en', 'fr', 'nl'],
            fallbackLocales: ['en'],
            catalogPath: null,
            regulated: false,
            maxSupportedLocales: 50,
            strictMode: false,
            urlStrategy: LocaleUrlStrategy::PathPrefix,
            defaultLocaleInUrl: $defaultLocaleInUrl,
            canonicalRedirect: $canonicalRedirect,
            localizedSlugs: $slugs,
        );

        return new LocalizedSlugMiddleware(
            SlugRegistry::fromConfig($slugs, $config->supportedLocales),
            $config,
            new UrlPrefixExtractor(),
        );
    }

    /**
     * Handler stub that records the path it is dispatched with.
     */
    private function capturingHandler(?string &$capturedPath): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->willReturnCallback(static function (ServerRequestInterface $r) use (&$capturedPath): ResponseInterface {
                $capturedPath = $r->getUri()->getPath();

                return Response::text('OK');
            });

        return $handler;
    }

    private function request(string $method, string $path, string $locale, string $query = ''): ServerRequest
    {
        $uri = $query !== '' ? $path . '?' . $query : $path;

        return new ServerRequest(method: $method, uri: $uri)->withAttribute('_locale', $locale);
    }

    #[Test]
    public function records_the_original_uri_when_it_runs_first(): void
    {
        // No prior rewriter: the slug middleware itself stamps the original URI.
        $middleware = $this->makeMiddleware($this->slugs());

        $capturedOriginalPath = null;
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->willReturnCallback(static function (ServerRequestInterface $r) use (&$capturedOriginalPath): ResponseInterface {
                /** @var \Psr\Http\Message\UriInterface $original */
                $original = $r->getAttribute('_original_uri');
                $capturedOriginalPath = $original->getPath();

                return Response::text('OK');
            });

        $middleware->process($this->request('GET', '/developpement', 'fr'), $handler);

        self::assertSame('/developpement', $capturedOriginalPath);
    }

    #[Test]
    public function does_not_overwrite_an_original_uri_set_by_an_earlier_rewriter(): void
    {
        // LocalePrefixMiddleware already recorded the full prefixed URI; the slug
        // middleware must leave that record intact, not replace it with the
        // already-stripped path it sees.
        $middleware = $this->makeMiddleware($this->slugs());

        $preExisting = new Uri(path: '/fr/developpement');
        $request = $this->request('GET', '/developpement', 'fr')
            ->withAttribute('_original_uri', $preExisting);

        $capturedOriginalPath = null;
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->willReturnCallback(static function (ServerRequestInterface $r) use (&$capturedOriginalPath): ResponseInterface {
                /** @var \Psr\Http\Message\UriInterface $original */
                $original = $r->getAttribute('_original_uri');
                $capturedOriginalPath = $original->getPath();

                return Response::text('OK');
            });

        $middleware->process($request, $handler);

        self::assertSame('/fr/developpement', $capturedOriginalPath, 'The earlier rewriter\'s record must survive');
    }

    #[Test]
    public function rewrites_canonical_localized_slug_to_key_path(): void
    {
        $middleware = $this->makeMiddleware($this->slugs());
        $capturedPath = null;

        $response = $middleware->process(
            $this->request('GET', '/developpement', 'fr'),
            $this->capturingHandler($capturedPath),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('/development', $capturedPath);
    }

    #[Test]
    public function rewrites_multi_segment_slug_and_preserves_route_params(): void
    {
        $middleware = $this->makeMiddleware($this->slugs());
        $capturedPath = null;

        $middleware->process(
            $this->request('GET', '/developpement/projets/my-project', 'fr'),
            $this->capturingHandler($capturedPath),
        );

        self::assertSame('/development/projects/my-project', $capturedPath);
    }

    #[Test]
    public function redirects_key_alias_to_canonical_slug(): void
    {
        $middleware = $this->makeMiddleware($this->slugs());

        $response = $middleware->process(
            $this->request('GET', '/development', 'fr'),
            $this->createStub(RequestHandlerInterface::class),
        );

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/fr/developpement', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function canonical_redirect_preserves_query_string(): void
    {
        $middleware = $this->makeMiddleware($this->slugs());

        $response = $middleware->process(
            $this->request('GET', '/development/projects/x', 'fr', 'page=2&sort=asc'),
            $this->createStub(RequestHandlerInterface::class),
        );

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/fr/developpement/projets/x?page=2&sort=asc', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function does_not_redirect_alias_on_post(): void
    {
        $middleware = $this->makeMiddleware($this->slugs());
        $capturedPath = null;

        $response = $middleware->process(
            $this->request('POST', '/development', 'fr'),
            $this->capturingHandler($capturedPath),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('/development', $capturedPath);
    }

    #[Test]
    public function does_not_redirect_when_canonical_redirect_disabled(): void
    {
        $middleware = $this->makeMiddleware($this->slugs(), canonicalRedirect: false);
        $capturedPath = null;

        $response = $middleware->process(
            $this->request('GET', '/development', 'fr'),
            $this->capturingHandler($capturedPath),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('/development', $capturedPath);
    }

    #[Test]
    public function passes_through_unknown_path(): void
    {
        $middleware = $this->makeMiddleware($this->slugs());
        $capturedPath = null;

        $middleware->process(
            $this->request('GET', '/contact', 'fr'),
            $this->capturingHandler($capturedPath),
        );

        self::assertSame('/contact', $capturedPath);
    }

    #[Test]
    public function passes_through_when_registry_empty(): void
    {
        $middleware = $this->makeMiddleware([]);
        $capturedPath = null;

        $middleware->process(
            $this->request('GET', '/developpement', 'fr'),
            $this->capturingHandler($capturedPath),
        );

        self::assertSame('/developpement', $capturedPath);
    }

    #[Test]
    public function default_locale_uses_key_as_canonical_without_redirect(): void
    {
        $middleware = $this->makeMiddleware($this->slugs());
        $capturedPath = null;

        $response = $middleware->process(
            $this->request('GET', '/development', 'en'),
            $this->capturingHandler($capturedPath),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('/development', $capturedPath);
    }

    #[Test]
    public function redirect_target_includes_prefix_for_default_locale_when_in_url(): void
    {
        $middleware = $this->makeMiddleware($this->slugs(), defaultLocaleInUrl: true);

        // Under nl, the bare key is a non-canonical alias → 301 to localized slug with prefix.
        $response = $middleware->process(
            $this->request('GET', '/development', 'nl'),
            $this->createStub(RequestHandlerInterface::class),
        );

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/nl/ontwikkeling', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function falls_back_to_default_locale_when_attribute_missing(): void
    {
        $middleware = $this->makeMiddleware($this->slugs());
        $capturedPath = null;

        // No _locale attribute → default 'en' → '/development' is canonical, identity rewrite.
        $request = new ServerRequest(method: 'GET', uri: '/development');

        $response = $middleware->process($request, $this->capturingHandler($capturedPath));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('/development', $capturedPath);
    }
}
