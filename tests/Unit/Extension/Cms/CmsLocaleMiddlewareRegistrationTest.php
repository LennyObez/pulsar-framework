<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Container;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Cms\CmsCoreServiceProvider;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Http\Middleware\CmsLocaleMiddleware;

/**
 * Verifies that CmsLocaleMiddleware is registered in the container by
 * CmsCoreServiceProvider, allowing the middleware pipeline to resolve
 * it with its constructor dependencies (LocaleResolver, CmsConfig).
 */
#[CoversClass(CmsCoreServiceProvider::class)]
final class CmsLocaleMiddlewareRegistrationTest extends TestCase
{
    #[Test]
    public function cmsLocaleMiddlewareIsRegisteredInContainer(): void
    {
        $container = $this->buildMinimalContainer();

        $provider = new CmsCoreServiceProvider();
        $provider->register($container);

        self::assertTrue(
            $container->has(CmsLocaleMiddleware::class),
            'CmsLocaleMiddleware should be registered in the container after CmsCoreServiceProvider::register()',
        );
    }

    #[Test]
    public function cmsLocaleMiddlewareIsCorrectInstance(): void
    {
        $container = $this->buildMinimalContainer();

        $provider = new CmsCoreServiceProvider();
        $provider->register($container);

        $middleware = $container->get(CmsLocaleMiddleware::class);

        self::assertInstanceOf(CmsLocaleMiddleware::class, $middleware);
    }

    /**
     * Build a minimal container with the dependencies that
     * CmsCoreServiceProvider requires to not bail out early.
     */
    private function buildMinimalContainer(): Container
    {
        $container = new Container();

        // CmsCoreServiceProvider checks for ConnectionInterface first
        $container->instance(ConnectionInterface::class, $this->createStub(ConnectionInterface::class));

        // CMS config is needed for all sub-providers
        $container->instance(CmsConfig::class, CmsConfig::fromArray([]));

        // Register all the repository interfaces that CmsCoreServiceProvider expects
        // to be already bound by CmsRepositoryProvider. We use stubs for unit testing.
        $repositoryInterfaces = [
            \Pulsar\Extension\Cms\Content\ContentRepositoryInterface::class,
            \Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface::class,
            \Pulsar\Extension\Cms\Content\ContentBlockRepositoryInterface::class,
            \Pulsar\Extension\Cms\Content\ContentRevisionRepositoryInterface::class,
            \Pulsar\Extension\Cms\Content\RedirectRepositoryInterface::class,
            \Pulsar\Extension\Cms\FieldRegistry\FieldRegistryRepositoryInterface::class,
            \Pulsar\Extension\Cms\Comments\CommentRepositoryInterface::class,
            \Pulsar\Extension\Cms\Media\MediaRepositoryInterface::class,
            \Pulsar\Extension\Cms\Navigation\MenuRepositoryInterface::class,
            \Pulsar\Extension\Cms\Taxonomy\TaxonomyRepositoryInterface::class,
            \Pulsar\Extension\Cms\Forms\FormSubmissionRepositoryInterface::class,
            \Pulsar\Extension\Cms\Search\SearchAnalyticsRepositoryInterface::class,
            \Pulsar\Extension\Cms\Seo\LinkHealthRepositoryInterface::class,
            \Pulsar\Extension\Cms\Users\CmsUserRepositoryInterface::class,
            \Pulsar\Extension\Cms\Collaboration\CollaborationRepositoryInterface::class,
            \Pulsar\Extension\Cms\ABTest\ExperimentRepositoryInterface::class,
            \Pulsar\Extension\Cms\Docs\DocVersionServiceInterface::class,
            \Pulsar\Extension\Cms\Newsletter\NewsletterSubscriberRepositoryInterface::class,
            \Pulsar\Extension\Cms\Newsletter\NewsletterCampaignRepositoryInterface::class,
        ];

        foreach ($repositoryInterfaces as $interface) {
            if (interface_exists($interface)) {
                $container->instance($interface, $this->createStub($interface));
            }
        }

        // Audit logger is needed for several services
        $container->instance(\Pulsar\Audit\AuditLoggerInterface::class, $this->createStub(\Pulsar\Audit\AuditLoggerInterface::class));

        // MasterKey for CmsKeyManager
        $container->instance(\Pulsar\Security\Crypto\MasterKey::class, \Pulsar\Security\Crypto\MasterKey::fromHex(str_repeat('ab', 32)));

        return $container;
    }
}
