<?php

declare(strict_types=1);

namespace Pulsar\Extension\Feedback;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Feedback\Http\Controller\Admin\FeedbackController as AdminFeedbackController;
use Pulsar\Extension\Feedback\Http\Controller\Api\FeedbackApiController;
use Pulsar\Extension\Feedback\Internal\FeedbackService;
use Pulsar\Extension\Feedback\Internal\Persistence\DbFeedbackRepository;
use Pulsar\Http\Client\HttpClientInterface;
use Pulsar\Queue\QueueDriverInterface;

/**
 * Binds feedback repository, service, and HTTP controllers to the container.
 */
#[Internal(reason: 'Feedback service wiring; use FeedbackRepositoryInterface for public API')]
final class FeedbackServiceProvider implements ServiceProviderInterface
{
    #[Override]
    public function register(ContainerInterface $container): void
    {
        if (!$container->has(ConnectionInterface::class)) {
            return;
        }

        /** @var ConnectionInterface $connection */
        $connection = $container->get(ConnectionInterface::class);

        // Repository
        $repository = new DbFeedbackRepository($connection);
        $container->instance(FeedbackRepositoryInterface::class, $repository);

        // Service
        $service = new FeedbackService($repository);
        $container->instance(FeedbackService::class, $service);

        // API controller
        $container->instance(
            FeedbackApiController::class,
            new FeedbackApiController($service, $repository),
        );

        // Admin controller (with optional HTTP client, GitHub token, and queue driver)
        /** @var HttpClientInterface|null $httpClient */
        $httpClient = $container->has(HttpClientInterface::class)
            ? $container->get(HttpClientInterface::class)
            : null;

        /** @var QueueDriverInterface|null $queueDriver */
        $queueDriver = $container->has(QueueDriverInterface::class)
            ? $container->get(QueueDriverInterface::class)
            : null;

        $container->instance(
            AdminFeedbackController::class,
            new AdminFeedbackController($service, $repository, $httpClient, '', $queueDriver),
        );
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function provides(): array
    {
        return [
            FeedbackRepositoryInterface::class,
            FeedbackService::class,
            FeedbackApiController::class,
            AdminFeedbackController::class,
        ];
    }
}
