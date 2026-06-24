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
    private function config(): I18nConfig
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
        );
    }

    private function dispatch(string $method, string $uri): ResponseInterface
    {
        $config = $this->config();
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

        return $pipeline->process(new ServerRequest(method: $method, uri: $uri), $finalHandler);
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
}
