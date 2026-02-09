<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Internal\LiveCss\CspHashComputer;
use Pulsar\Extension\Cms\Internal\LiveCss\CssValidator;
use Pulsar\Extension\Cms\Internal\LiveCss\LiveCssService;
use Pulsar\Extension\Cms\Internal\LiveCss\ThemeTokenResolver;
use Pulsar\Extension\Cms\Internal\Persistence\CachePreviewSessionRepository;
use Pulsar\Extension\Cms\Internal\Plugins\CmsPluginManager;
use Pulsar\Extension\Cms\Internal\Plugins\HookExecutionEngine;
use Pulsar\Extension\Cms\Internal\Plugins\PluginManifestValidator;
use Pulsar\Extension\Cms\Internal\Plugins\PluginProvenanceVerifier;
use Pulsar\Extension\Cms\Internal\Themes\SafeArchiveExtractor;
use Pulsar\Extension\Cms\Internal\Themes\ThemeAssetResolver;
use Pulsar\Extension\Cms\Internal\Themes\ThemeManager;
use Pulsar\Extension\Cms\Internal\Themes\ThemeManifestValidator;
use Pulsar\Extension\Cms\Internal\Themes\ThemeProvenanceVerifier;
use Pulsar\Extension\Cms\LiveCss\CspHashComputerInterface;
use Pulsar\Extension\Cms\LiveCss\CssOverrideRepositoryInterface;
use Pulsar\Extension\Cms\LiveCss\CssValidatorInterface;
use Pulsar\Extension\Cms\LiveCss\LiveCssServiceInterface;
use Pulsar\Extension\Cms\LiveCss\ThemeTokenResolverInterface;
use Pulsar\Extension\Cms\Plugins\CmsPluginManagerInterface;
use Pulsar\Extension\Cms\Plugins\CmsPluginRepositoryInterface;
use Pulsar\Extension\Cms\Plugins\HookRegistry;
use Pulsar\Extension\Cms\Plugins\PluginManifestValidatorInterface;
use Pulsar\Extension\Cms\Plugins\PluginProvenanceVerifierInterface;
use Pulsar\Extension\Cms\Themes\PreviewSessionRepositoryInterface;
use Pulsar\Extension\Cms\Themes\ThemeArchiveExtractorInterface;
use Pulsar\Extension\Cms\Themes\ThemeAssetResolverInterface;
use Pulsar\Extension\Cms\Themes\ThemeManagerInterface;
use Pulsar\Extension\Cms\Themes\ThemeManifestValidatorInterface;
use Pulsar\Extension\Cms\Themes\ThemeProvenanceVerifierInterface;
use Pulsar\Extension\Cms\Themes\ThemeRepositoryInterface;

use function getcwd;
use function rtrim;

/**
 * Binds theme manager, plugin manager, hook engine, and live CSS services.
 */
#[Internal(reason: 'CMS service wiring — use interfaces for public API')]
final readonly class CmsThemePluginProvider
{
    public function register(ContainerInterface $container): void
    {
        if (!$container->has(ConnectionInterface::class)) {
            return;
        }

        /** @var CmsConfig $config */
        $config = $container->has(CmsConfig::class)
            ? $container->get(CmsConfig::class)
            : new CmsConfig();

        /** @var AuditLoggerInterface|null $auditLogger */
        $auditLogger = $container->has(AuditLoggerInterface::class)
            ? $container->get(AuditLoggerInterface::class)
            : null;

        /** @var LoggerInterface $logger */
        $logger = $container->has(LoggerInterface::class)
            ? $container->get(LoggerInterface::class)
            : new NullLogger();

        /** @var EventDispatcherInterface|null $eventDispatcher */
        $eventDispatcher = $container->has(EventDispatcherInterface::class)
            ? $container->get(EventDispatcherInterface::class)
            : null;

        // Theme stack
        $themesConfig = $config->themes;

        $manifestValidator = new ThemeManifestValidator();
        $container->instance(ThemeManifestValidatorInterface::class, $manifestValidator);

        $provenanceVerifier = new ThemeProvenanceVerifier($themesConfig, $logger);
        $container->instance(ThemeProvenanceVerifierInterface::class, $provenanceVerifier);

        $archiveExtractor = new SafeArchiveExtractor($themesConfig, $logger);
        $container->instance(ThemeArchiveExtractorInterface::class, $archiveExtractor);

        /** @var ThemeRepositoryInterface $themeRepository */
        $themeRepository = $container->get(ThemeRepositoryInterface::class);

        $container->instance(
            ThemeAssetResolverInterface::class,
            new ThemeAssetResolver($themeRepository, $themesConfig, $logger),
        );

        // Preview session repository (cache-backed when TaggedCacheInterface is available)
        if ($container->has(TaggedCacheInterface::class)) {
            /** @var TaggedCacheInterface $taggedCache */
            $taggedCache = $container->get(TaggedCacheInterface::class);
            $previewSessionRepository = new CachePreviewSessionRepository($taggedCache);
            $container->instance(PreviewSessionRepositoryInterface::class, $previewSessionRepository);

            if ($eventDispatcher !== null) {
                /** @var string $basePath */
                $basePath = $container->has('app.base_path')
                    ? $container->get('app.base_path')
                    : (getcwd() ?: '.');

                $publicPath = rtrim($basePath, '/') . '/public';

                $container->instance(
                    ThemeManagerInterface::class,
                    new ThemeManager(
                        $themeRepository,
                        $manifestValidator,
                        $provenanceVerifier,
                        $archiveExtractor,
                        $themesConfig,
                        $eventDispatcher,
                        $auditLogger,
                        $logger,
                        $previewSessionRepository,
                        $publicPath,
                    ),
                );
            }
        }

        // Plugin stack
        $securityConfig = $config->security;

        $pluginManifestValidator = new PluginManifestValidator();
        $container->instance(PluginManifestValidatorInterface::class, $pluginManifestValidator);

        $pluginProvenanceVerifier = new PluginProvenanceVerifier($securityConfig, $logger);
        $container->instance(PluginProvenanceVerifierInterface::class, $pluginProvenanceVerifier);

        $hookRegistry = new HookRegistry();
        $container->instance(HookRegistry::class, $hookRegistry);

        $hookEngine = new HookExecutionEngine($hookRegistry, $auditLogger, $logger);
        $container->instance(HookExecutionEngine::class, $hookEngine);

        if ($eventDispatcher !== null) {
            /** @var CmsPluginRepositoryInterface $pluginRepository */
            $pluginRepository = $container->get(CmsPluginRepositoryInterface::class);

            $container->instance(
                CmsPluginManagerInterface::class,
                new CmsPluginManager(
                    $pluginRepository,
                    $pluginManifestValidator,
                    $pluginProvenanceVerifier,
                    $archiveExtractor,
                    $securityConfig,
                    $container,
                    $hookRegistry,
                    $hookEngine,
                    $eventDispatcher,
                    $auditLogger,
                    $logger,
                ),
            );
        }

        // Live CSS stack
        $this->bindLiveCssServices($container, $themeRepository, $auditLogger);
    }

    private function bindLiveCssServices(
        ContainerInterface $container,
        ThemeRepositoryInterface $themeRepository,
        ?AuditLoggerInterface $auditLogger,
    ): void {
        $cssValidator = new CssValidator();
        $container->instance(CssValidatorInterface::class, $cssValidator);

        $cspHashComputer = new CspHashComputer();
        $container->instance(CspHashComputerInterface::class, $cspHashComputer);

        $themeTokenResolver = new ThemeTokenResolver($themeRepository);
        $container->instance(ThemeTokenResolverInterface::class, $themeTokenResolver);

        /** @var CssOverrideRepositoryInterface $cssOverrideRepository */
        $cssOverrideRepository = $container->get(CssOverrideRepositoryInterface::class);

        $container->instance(
            LiveCssServiceInterface::class,
            new LiveCssService($cssOverrideRepository, $cssValidator, $cspHashComputer, $auditLogger),
        );
    }
}
