<?php

declare(strict_types=1);

namespace Pulsar\Extension\Graphql;

use Pulsar\Api\Internal;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Cms\Content\ContentBlockRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Media\MediaRepositoryInterface;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyRepositoryInterface;
use Pulsar\Extension\Graphql\Execution\GraphqlExecutor;
use Pulsar\Extension\Graphql\Http\GraphqlController;
use Pulsar\Extension\Graphql\Resolver\ContentResolver;
use Pulsar\Extension\Graphql\Resolver\MediaResolver;
use Pulsar\Extension\Graphql\Resolver\TaxonomyResolver;
use Pulsar\Extension\Graphql\Schema\SchemaBuilder;

/**
 * Wires GraphQL schema, resolvers, executor, and controller.
 */
#[Internal(reason: 'GraphQL service wiring — use GraphqlController for public API')]
final class GraphqlServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        if (
            !$container->has(ContentRepositoryInterface::class)
            || !$container->has(ContentTranslationRepositoryInterface::class)
            || !$container->has(ContentBlockRepositoryInterface::class)
            || !$container->has(TaxonomyRepositoryInterface::class)
            || !$container->has(MediaRepositoryInterface::class)
        ) {
            return;
        }

        /** @var ContentRepositoryInterface $contentRepo */
        $contentRepo = $container->get(ContentRepositoryInterface::class);

        /** @var ContentTranslationRepositoryInterface $translationRepo */
        $translationRepo = $container->get(ContentTranslationRepositoryInterface::class);

        /** @var ContentBlockRepositoryInterface $blockRepo */
        $blockRepo = $container->get(ContentBlockRepositoryInterface::class);

        /** @var TaxonomyRepositoryInterface $taxonomyRepo */
        $taxonomyRepo = $container->get(TaxonomyRepositoryInterface::class);

        /** @var MediaRepositoryInterface $mediaRepo */
        $mediaRepo = $container->get(MediaRepositoryInterface::class);

        $contentResolver = new ContentResolver($contentRepo, $translationRepo, $blockRepo);
        $taxonomyResolver = new TaxonomyResolver($taxonomyRepo);
        $mediaResolver = new MediaResolver($mediaRepo);

        $container->instance(ContentResolver::class, $contentResolver);
        $container->instance(TaxonomyResolver::class, $taxonomyResolver);
        $container->instance(MediaResolver::class, $mediaResolver);

        $schema = new SchemaBuilder()->build();
        $container->instance(SchemaBuilder::class, new SchemaBuilder());

        $executor = new GraphqlExecutor($schema, $contentResolver, $taxonomyResolver, $mediaResolver);
        $container->instance(GraphqlExecutor::class, $executor);

        $controller = new GraphqlController($executor, $schema);
        $container->instance(GraphqlController::class, $controller);
    }

    /**
     * @return list<string>
     */
    public function provides(): array
    {
        return [
            ContentResolver::class,
            TaxonomyResolver::class,
            MediaResolver::class,
            GraphqlExecutor::class,
            GraphqlController::class,
        ];
    }
}
