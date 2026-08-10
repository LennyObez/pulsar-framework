<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\I18nConfig;
use Pulsar\Config\RoutingConfig;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\RoutingWiring;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Middleware\CanonicalPathMiddleware;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\I18n\Locale\LocalePrefixMiddleware;
use Pulsar\I18n\Locale\LocaleUrlStrategy;
use Pulsar\I18n\Locale\UrlPrefixExtractor;
use Pulsar\I18n\LocaleNegotiatorInterface;
use Pulsar\I18n\TranslatorInterface;
use Pulsar\Routing\Router;

/**
 * Guards the load-bearing invariant that CanonicalPathMiddleware runs OUTERMOST
 * — ahead of the locale-prefix strip. If it ever ran after the strip,
 * `/nl/coaching/` would be seen as the stripped `/coaching/` and permanently
 * redirect to `/coaching`, silently dropping the locale prefix on every
 * non-canonical URL (a browser/crawler-cached 301). See RoutingWiring's
 * docblock.
 *
 * The point of prepend() is that this holds regardless of boot order, so these
 * tests deliberately run the wiring in the ADVERSARIAL order (locale piped
 * first) and still expect canonicalization to win.
 */
#[CoversClass(RoutingWiring::class)]
#[CoversClass(CanonicalPathMiddleware::class)]
final class CanonicalPathOrderingTest extends TestCase
{
    #[Test]
    public function routingWiringPrependsCanonicalizationAheadOfAnAlreadyPipedLocaleStrip(): void
    {
        $pipeline = new MiddlewarePipeline();

        // Adversarial boot order: the locale-prefix strip is already piped
        // (as if I18nWiring ran BEFORE RoutingWiring, or was reordered ahead of
        // it). With pipe() this would leave canonicalization inner and break the
        // invariant; with prepend() it must still land outermost.
        $pipeline->pipe($this->localePrefixMiddleware());

        $this->runRoutingWiring($pipeline, redirectToCanonicalPath: true);

        $stack = $pipeline->snapshot();

        $canonicalIndex = $this->indexOfInstance($stack, CanonicalPathMiddleware::class);
        $localeIndex = $this->indexOfInstance($stack, LocalePrefixMiddleware::class);

        self::assertNotNull($canonicalIndex, 'CanonicalPathMiddleware must be in the pipeline');
        self::assertNotNull($localeIndex, 'LocalePrefixMiddleware must be in the pipeline');
        self::assertSame(0, $canonicalIndex, 'CanonicalPathMiddleware must be OUTERMOST (index 0)');
        self::assertLessThan(
            $localeIndex,
            $canonicalIndex,
            'CanonicalPathMiddleware must precede LocalePrefixMiddleware even when locale was piped first',
        );
    }

    #[Test]
    public function canonicalizationIsNotPipedWhenTheFeatureIsOff(): void
    {
        $pipeline = new MiddlewarePipeline();

        $this->runRoutingWiring($pipeline, redirectToCanonicalPath: false);

        self::assertNull(
            $this->indexOfInstance($pipeline->snapshot(), CanonicalPathMiddleware::class),
            'With the feature off, nothing is piped — the router keeps its forgiving behaviour',
        );
    }

    #[Test]
    public function outermostCanonicalizationPreservesTheLocalePrefixOnRedirect(): void
    {
        // The functional payoff: with canonicalization OUTERMOST, a request for
        // /nl/coaching/ is redirected to /nl/coaching — the locale prefix is
        // seen and kept, because the strip has not run yet.
        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe($this->localePrefixMiddleware());
        $this->runRoutingWiring($pipeline, redirectToCanonicalPath: true);

        $response = $pipeline->dispatch(
            new ServerRequest(method: 'GET', uri: '/nl/coaching/'),
            static fn(): ResponseInterface => Response::text('handler ran — should not happen'),
        );

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/nl/coaching', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function theReversedOrderIsWhatDropsThePrefix(): void
    {
        // Documents precisely the failure mode the invariant prevents: if
        // canonicalization ran AFTER the locale strip, the same request loses
        // its prefix. Built by hand (both piped, locale outermost) so the
        // consequence is demonstrated rather than asserted — this is why the
        // wiring uses prepend() and not pipe().
        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe($this->localePrefixMiddleware());
        $pipeline->pipe(new CanonicalPathMiddleware());

        $response = $pipeline->dispatch(
            new ServerRequest(method: 'GET', uri: '/nl/coaching/'),
            static fn(): ResponseInterface => Response::text('handler ran'),
        );

        self::assertSame(301, $response->getStatusCode());
        self::assertSame(
            '/coaching',
            $response->getHeaderLine('Location'),
            'Locale-first ordering drops the /nl prefix — the exact regression prepend() prevents',
        );
    }

    private function runRoutingWiring(MiddlewarePipeline $pipeline, bool $redirectToCanonicalPath): void
    {
        $container = new Container();
        $configManager = new ConfigManager();
        // load() (null configPath → empty defaults) creates the repository; then
        // seed the RoutingConfig this test exercises.
        $configManager->load();
        $configManager->repository()->set(new RoutingConfig(redirectToCanonicalPath: $redirectToCanonicalPath));

        new RoutingWiring()->wire(
            $container,
            $configManager,
            $pipeline,
            new MiddlewareRegistry(),
            new Router(),
        );
    }

    private function localePrefixMiddleware(): LocalePrefixMiddleware
    {
        $negotiator = $this->createStub(LocaleNegotiatorInterface::class);
        $negotiator->method('negotiate')->willReturnCallback(
            static fn(ServerRequestInterface $r, array $supported, string $default): string => $default,
        );

        $translator = new class implements TranslatorInterface {
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

        return new LocalePrefixMiddleware(
            extractor: new UrlPrefixExtractor(),
            negotiator: $negotiator,
            config: $this->pathPrefixConfig(),
            translator: $translator,
        );
    }

    private function pathPrefixConfig(): I18nConfig
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
            // Off so the strip runs in pure strip-and-continue mode rather than
            // issuing its own redirect — keeps the reversed-order test focused
            // on the prefix-loss the ordering causes.
            canonicalRedirect: false,
            negotiateUnprefixedLocale: true,
            courtesyRedirect: false,
            courtesyFallbackLocale: '',
            localeCookieEnabled: false,
            localeCookieName: 'pulsar_locale',
        );
    }

    /**
     * @param list<object|class-string> $stack
     */
    private function indexOfInstance(array $stack, string $class): ?int
    {
        foreach ($stack as $i => $entry) {
            if ($entry instanceof $class) {
                return $i;
            }
        }

        return null;
    }
}
