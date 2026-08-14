<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\I18nConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Controller\Api\I18nController;
use Pulsar\Http\Controller\Api\RegionApiController;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\I18n\Catalog\ChainCatalog;
use Pulsar\I18n\Catalog\JsonCatalog;
use Pulsar\I18n\Catalog\PhpCatalog;
use Pulsar\I18n\CatalogInterface;
use Pulsar\I18n\Compiler\TranslationCompiler;
use Pulsar\I18n\Compiler\TranslationCompilerInterface;
use Pulsar\I18n\Exception\I18nException;
use Pulsar\I18n\Extractor\TranslationExtractor;
use Pulsar\I18n\Format\CurrencyFormatterInterface;
use Pulsar\I18n\Format\DateFormatterInterface;
use Pulsar\I18n\Format\FallbackMessageFormatter;
use Pulsar\I18n\Format\IcuMessageFormatter;
use Pulsar\I18n\Format\IntlCurrencyFormatter;
use Pulsar\I18n\Format\IntlDateFormatter;
use Pulsar\I18n\Format\IntlNumberFormatter;
use Pulsar\I18n\Format\MessageFormatterInterface;
use Pulsar\I18n\Format\NumberFormatterInterface;
use Pulsar\I18n\Linter\TranslationLinter;
use Pulsar\I18n\Locale\CookieAwareLocaleNegotiator;
use Pulsar\I18n\Locale\LocaleMiddleware;
use Pulsar\I18n\Locale\LocaleNegotiator;
use Pulsar\I18n\Locale\LocalePrefixMiddleware;
use Pulsar\I18n\Locale\LocaleUrlGenerator;
use Pulsar\I18n\Locale\LocaleUrlResolverInterface;
use Pulsar\I18n\Locale\LocaleUrlStrategy;
use Pulsar\I18n\Locale\LocalizedSlugMiddleware;
use Pulsar\I18n\Locale\LocalizedUrlGenerator;
use Pulsar\I18n\Locale\RouteBasedLocaleUrlResolver;
use Pulsar\I18n\Locale\SlugLocaleUrlResolver;
use Pulsar\I18n\Locale\SlugRegistry;
use Pulsar\I18n\Locale\UrlPrefixExtractor;
use Pulsar\I18n\LocaleNegotiatorInterface;
use Pulsar\I18n\Region\CountryRegistry;
use Pulsar\I18n\Region\CurrencyResolver;
use Pulsar\I18n\Region\RegionMiddleware;
use Pulsar\I18n\Region\RegionResolver;
use Pulsar\I18n\Translator;
use Pulsar\I18n\TranslatorInterface;
use Pulsar\Routing\Router;
use Pulsar\View\Engine\TemplateLocaleHelper;

use function extension_loaded;
use function in_array;
use function sprintf;

/**
 * Wires the i18n translation system into the container.
 *
 * Only activates when I18nConfig is present in the repository.
 */
#[Internal]
final readonly class I18nWiring implements ServiceWiringInterface
{
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        $repository = $configManager->repository();

        if (!$repository->has(I18nConfig::class)) {
            return;
        }

        /** @var I18nConfig $config */
        $config = $repository->get(I18nConfig::class);
        $container->instance(I18nConfig::class, $config);

        // Compile the localized-slug registry once at boot and register it so
        // controllers can type-hint it. Empty when no slugs are configured.
        $slugRegistry = SlugRegistry::fromConfig($config->localizedSlugs, $config->supportedLocales);
        $container->instance(SlugRegistry::class, $slugRegistry);

        $intlAvailable = extension_loaded('intl');

        // Regulated mode requires ext-intl
        if ($config->regulated && !$intlAvailable) {
            throw I18nException::intlRequired();
        }

        // Build catalog
        $catalog = $this->buildCatalog($config);
        $container->instance(CatalogInterface::class, $catalog);

        // Bind the translation CLI tooling so the i18n:extract and i18n:lint
        // commands (registered conditionally in bin/pulsar on these bindings)
        // become available once i18n is configured — matching the slug linter,
        // which is already wired off SlugRegistry. The linter reuses the
        // catalog and config built above; the extractor is stateless.
        $container->instance(TranslationExtractor::class, new TranslationExtractor());
        $container->instance(TranslationLinter::class, new TranslationLinter($catalog, $config));

        // Build message formatter
        $messageFormatter = $this->buildMessageFormatter($intlAvailable, $container);
        $container->instance(MessageFormatterInterface::class, $messageFormatter);

        // Build translator
        $translator = new Translator($catalog, $config, $messageFormatter);
        Translator::setGlobalInstance($translator);
        $container->instance(TranslatorInterface::class, $translator);
        $container->instance(Translator::class, $translator);

        // Locale negotiator. When locale-cookie persistence is enabled, wrap the
        // core negotiator so the cookie and session take precedence over
        // Accept-Language; otherwise stay Accept-Language-only (default behaviour).
        $negotiator = $config->localeCookieEnabled
            ? new CookieAwareLocaleNegotiator(new LocaleNegotiator(), $config->localeCookieName)
            : new LocaleNegotiator();
        $container->instance(LocaleNegotiatorInterface::class, $negotiator);

        // Surface a misconfigured courtesy fallback at boot: a non-empty
        // courtesy_fallback_locale outside supported_locales would silently skip
        // the redirect for visitors with no detectable locale.
        if (
            $config->courtesyRedirect
            && $config->courtesyFallbackLocale !== ''
            && !in_array($config->courtesyFallbackLocale, $config->supportedLocales, true)
        ) {
            $courtesyLogger = $container->has(LoggerInterface::class)
                ? $container->get(LoggerInterface::class)
                : null;
            /** @var ?LoggerInterface $courtesyLogger */
            $courtesyLogger?->warning(sprintf(
                'i18n courtesy_fallback_locale "%s" is not in supported_locales; the courtesy redirect is skipped for visitors with no detectable locale.',
                $config->courtesyFallbackLocale,
            ));
        }

        // Register Intl formatters if ext-intl is available
        if ($intlAvailable) {
            $numberFormatter = new IntlNumberFormatter($translator);
            $container->instance(NumberFormatterInterface::class, $numberFormatter);

            $dateFormatter = new IntlDateFormatter($translator);
            $container->instance(DateFormatterInterface::class, $dateFormatter);

            $currencyFormatter = new IntlCurrencyFormatter($translator);
            $container->instance(CurrencyFormatterInterface::class, $currencyFormatter);
        }

        // Register i18n API routes
        $compiler = new TranslationCompiler($catalog);
        $container->instance(TranslationCompiler::class, $compiler);
        // Bound under the interface too: the controller asks for the contract, and
        // used to build its own compiler because it could not name one. That made
        // two, of which this registered instance was the unused half.
        $container->instance(TranslationCompilerInterface::class, $compiler);
        $router->get('/api/i18n/{locale}', [I18nController::class, 'show'], 'api.i18n.locale');

        // Region system
        $countryRegistry = new CountryRegistry();
        $container->instance(CountryRegistry::class, $countryRegistry);

        $regionResolver = new RegionResolver(
            $countryRegistry,
            $this->deriveDefaultCountry($config->defaultLocale, $countryRegistry),
        );
        $container->instance(RegionResolver::class, $regionResolver);

        $currencyResolver = new CurrencyResolver($countryRegistry);
        $container->instance(CurrencyResolver::class, $currencyResolver);

        $regionMiddleware = new RegionMiddleware($regionResolver, $currencyResolver);
        $middleware->pipe($regionMiddleware);

        $router->get('/api/i18n/regions.json', RegionApiController::class, 'api.i18n.regions');

        // Wire middleware based on URL strategy
        if ($config->urlStrategy === LocaleUrlStrategy::PathPrefix) {
            $this->wireLocaleUrlRouting($container, $middleware, $config, $negotiator, $translator, $router, $slugRegistry);
        } else {
            $middleware->pipe(new LocaleMiddleware($negotiator, $config, $translator));
        }
    }

    private function wireLocaleUrlRouting(
        ContainerInterface $container,
        MiddlewarePipeline $middleware,
        I18nConfig $config,
        LocaleNegotiatorInterface $negotiator,
        TranslatorInterface $translator,
        Router $router,
        SlugRegistry $slugRegistry,
    ): void {
        $extractor = new UrlPrefixExtractor();
        $container->instance(UrlPrefixExtractor::class, $extractor);

        // LocalePrefixMiddleware replaces LocaleMiddleware
        $middleware->pipe(new LocalePrefixMiddleware($extractor, $negotiator, $config, $translator));

        // Localized slug rewriting runs immediately after the prefix strip, so
        // the locale-agnostic router only ever sees canonical key paths. Piped
        // only when slugs are configured — zero overhead otherwise.
        if (!$slugRegistry->isEmpty()) {
            $middleware->pipe(new LocalizedSlugMiddleware($slugRegistry, $config, $extractor));
        }

        // Default URL resolver: slug-aware when slugs are configured, otherwise
        // simple prefix swapping. Extensions may pre-bind a content-aware
        // implementation, which takes precedence.
        if (!$container->has(LocaleUrlResolverInterface::class)) {
            $resolver = $slugRegistry->isEmpty()
                ? new RouteBasedLocaleUrlResolver($extractor, $config)
                : new SlugLocaleUrlResolver($slugRegistry, $config, $extractor);
            $container->instance(LocaleUrlResolverInterface::class, $resolver);
        }

        // Path-based URL generator (hreflang, locale switchers)
        $resolverInstance = $container->get(LocaleUrlResolverInterface::class);
        /** @var LocaleUrlResolverInterface $resolverInstance */
        $urlGenerator = new LocaleUrlGenerator($extractor, $config, $resolverInstance);
        $container->instance(LocaleUrlGenerator::class, $urlGenerator);

        // Key-based localized URL generator backing route() and @route.
        $localizedGenerator = new LocalizedUrlGenerator($slugRegistry, $config, $extractor, $translator, $router);
        $container->instance(LocalizedUrlGenerator::class, $localizedGenerator);
        LocalizedUrlGenerator::setGlobalInstance($localizedGenerator);

        // Template helper (reads locale from translator, updated per-request by middleware)
        $helper = new TemplateLocaleHelper($translator, $urlGenerator);
        $container->instance(TemplateLocaleHelper::class, $helper);
    }

    /**
     * Derive the default country (for region/currency defaults) from the
     * configured default locale instead of always assuming the US. Prefers an
     * explicit region subtag (fr-FR / en_US → FR / US); for a bare language the
     * uppercased code is the ISO country for most locales (fr → FR); falls back
     * to US only when the derived code is not a known country.
     */
    private function deriveDefaultCountry(string $locale, CountryRegistry $registry): string
    {
        if (preg_match('/[-_]([A-Za-z]{2})$/', $locale, $matches) === 1) {
            $country = strtoupper($matches[1]);

            if ($registry->has($country)) {
                return $country;
            }
        }

        if (preg_match('/^([A-Za-z]{2})/', $locale, $matches) === 1) {
            $country = strtoupper($matches[1]);

            if ($registry->has($country)) {
                return $country;
            }
        }

        return 'US';
    }

    private function buildCatalog(I18nConfig $config): CatalogInterface
    {
        if ($config->catalogPath === null) {
            return new ChainCatalog();
        }

        return new ChainCatalog(
            new PhpCatalog($config->catalogPath),
            new JsonCatalog($config->catalogPath),
        );
    }

    private function buildMessageFormatter(bool $intlAvailable, ContainerInterface $container): MessageFormatterInterface
    {
        if ($intlAvailable) {
            return new IcuMessageFormatter();
        }

        $logger = $container->has(LoggerInterface::class)
            ? $container->get(LoggerInterface::class)
            : null;

        /** @var ?LoggerInterface $logger */
        return new FallbackMessageFormatter($logger);
    }
}
