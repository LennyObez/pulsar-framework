<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\I18n;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Config\I18nConfig;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Method;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\I18n\Locale\LocaleNegotiator;
use Pulsar\I18n\Locale\LocalePrefixMiddleware;
use Pulsar\I18n\Locale\LocaleUrlStrategy;
use Pulsar\I18n\Locale\LocalizedSlugMiddleware;
use Pulsar\I18n\Locale\SlugRegistry;
use Pulsar\I18n\Locale\UrlPrefixExtractor;
use Pulsar\I18n\TranslatorInterface;
use Pulsar\Routing\Router;
use Pulsar\Routing\RoutingException;

use function is_string;

/**
 * End-to-end acceptance for localized route slugs: locale strip → slug rewrite
 * → route match, exercising the same middleware chain the kernel wires.
 */
#[CoversClass(LocalizedSlugMiddleware::class)]
#[CoversClass(SlugRegistry::class)]
final class LocalizedSlugRoutingTest extends TestCase
{
    private function config(bool $negotiateUnprefixedLocale = true): I18nConfig
    {
        return new I18nConfig(
            defaultLocale: 'en',
            supportedLocales: ['en', 'fr', 'nl'],
            fallbackLocales: ['en'],
            catalogPath: null,
            regulated: false,
            maxSupportedLocales: 50,
            strictMode: false,
            urlStrategy: LocaleUrlStrategy::PathPrefix,
            defaultLocaleInUrl: false,
            canonicalRedirect: true,
            localizedSlugs: [
                'development' => ['fr' => 'developpement', 'nl' => 'ontwikkeling'],
                'development/projects' => ['fr' => 'developpement/projets', 'nl' => 'ontwikkeling/projecten'],
            ],
            negotiateUnprefixedLocale: $negotiateUnprefixedLocale,
        );
    }

    private function dispatch(
        string $method,
        string $uri,
        ?string $acceptLanguage = null,
        bool $negotiateUnprefixedLocale = true,
    ): ResponseInterface {
        $config = $this->config($negotiateUnprefixedLocale);
        $extractor = new UrlPrefixExtractor();

        $translator = new class implements TranslatorInterface {
            public string $locale = 'en';

            public function translate(string $key, array $parameters = [], ?string $locale = null, string $domain = 'messages'): string
            {
                return $key;
            }

            public function has(string $key, ?string $locale = null, string $domain = 'messages'): bool
            {
                return false;
            }
        };

        $router = new Router();
        $router->get('/development', 'DevHandler', 'development');
        $router->get('/development/projects/{slug}', 'ProjectHandler', 'development/projects');

        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe(new LocalePrefixMiddleware($extractor, new LocaleNegotiator(), $config, $translator));
        $pipeline->pipe(new LocalizedSlugMiddleware(
            SlugRegistry::fromConfig($config->localizedSlugs, $config->supportedLocales),
            $config,
            $extractor,
        ));

        // Terminal handler: match the (rewritten) path and echo the route name +
        // any slug parameter, or 404 on no match.
        $finalHandler = new class ($router) implements RequestHandlerInterface {
            public function __construct(private readonly Router $router) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                try {
                    $matched = $this->router->match(
                        Method::from($request->getMethod()),
                        $request->getUri()->getPath(),
                    );
                } catch (RoutingException) {
                    return new Response(404);
                }

                $body = $matched->getName() ?? 'unnamed';
                $slug = $matched->parameters['slug'] ?? null;

                if (is_string($slug)) {
                    $body .= ':' . $slug;
                }

                return Response::text($body);
            }
        };

        $request = new ServerRequest(method: $method, uri: $uri);

        if ($acceptLanguage !== null) {
            $request = $request->withHeader('Accept-Language', $acceptLanguage);
        }

        return $pipeline->process($request, $finalHandler);
    }

    #[Test]
    public function default_locale_at_root_resolves(): void
    {
        $response = $this->dispatch('GET', '/development');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('development', (string) $response->getBody());
    }

    #[Test]
    public function localized_slug_resolves_to_same_handler(): void
    {
        $response = $this->dispatch('GET', '/fr/developpement');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('development', (string) $response->getBody());
    }

    #[Test]
    public function dutch_localized_slug_resolves(): void
    {
        $response = $this->dispatch('GET', '/nl/ontwikkeling');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('development', (string) $response->getBody());
    }

    #[Test]
    public function key_alias_redirects_to_canonical_localized_slug(): void
    {
        $response = $this->dispatch('GET', '/fr/development');

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/fr/developpement', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function dutch_key_alias_redirects_to_canonical(): void
    {
        $response = $this->dispatch('GET', '/nl/development');

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/nl/ontwikkeling', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function route_parameters_survive_localized_segments(): void
    {
        $response = $this->dispatch('GET', '/fr/developpement/projets/my-post');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('development/projects:my-post', (string) $response->getBody());
    }

    #[Test]
    public function unknown_path_is_not_found(): void
    {
        $response = $this->dispatch('GET', '/fr/inconnu');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function unprefixed_default_locale_url_stays_canonical_under_foreign_accept_language(): void
    {
        // With negotiation disabled, a French browser hitting the canonical
        // English URL is served the English page — not bounced to /fr/...
        $response = $this->dispatch('GET', '/development', acceptLanguage: 'fr-FR,fr;q=0.9', negotiateUnprefixedLocale: false);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('development', (string) $response->getBody());
    }

    #[Test]
    public function localized_paths_unaffected_by_disabled_negotiation(): void
    {
        // The prefix is URL-authoritative regardless of the negotiation flag.
        $canonical = $this->dispatch('GET', '/fr/developpement', acceptLanguage: 'en-US', negotiateUnprefixedLocale: false);
        self::assertSame(200, $canonical->getStatusCode());
        self::assertSame('development', (string) $canonical->getBody());

        $alias = $this->dispatch('GET', '/fr/development', acceptLanguage: 'en-US', negotiateUnprefixedLocale: false);
        self::assertSame(301, $alias->getStatusCode());
        self::assertSame('/fr/developpement', $alias->getHeaderLine('Location'));
    }

    #[Test]
    public function unprefixed_url_follows_accept_language_when_negotiation_enabled(): void
    {
        // Default (BC) behaviour: an unprefixed URL is negotiated, so a French
        // browser is redirected to the localized slug.
        $response = $this->dispatch('GET', '/development', acceptLanguage: 'fr-FR,fr;q=0.9', negotiateUnprefixedLocale: true);

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/fr/developpement', $response->getHeaderLine('Location'));
    }
}
