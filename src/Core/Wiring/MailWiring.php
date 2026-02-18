<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\MailConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Mail\MailManager;
use Pulsar\Mail\MailManagerInterface;
use Pulsar\Mail\Security\PhiScrubber;
use Pulsar\Mail\Security\PhiScrubberInterface;
use Pulsar\Mail\Transport\MailHttpClientInterface;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Routing\Router;

#[Internal]
final readonly class MailWiring implements ServiceWiringInterface
{
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        $repository = $configManager->repository();

        if (!$repository->has(MailConfig::class)) {
            return;
        }

        /** @var MailConfig $mailConfig */
        $mailConfig = $repository->get(MailConfig::class);
        $container->instance(MailConfig::class, $mailConfig);

        if (!$mailConfig->enabled) {
            return;
        }

        // Resolve optional dependencies
        $httpClient = $container->has(MailHttpClientInterface::class)
            ? $container->get(MailHttpClientInterface::class)
            : null;

        /** @var MailHttpClientInterface|null $httpClient */
        $eventDispatcher = $container->has(EventDispatcherInterface::class)
            ? $container->get(EventDispatcherInterface::class)
            : null;

        /** @var EventDispatcherInterface|null $eventDispatcher */
        $auditLogger = $container->has(AuditLoggerInterface::class)
            ? $container->get(AuditLoggerInterface::class)
            : null;

        /** @var AuditLoggerInterface|null $auditLogger */
        $logger = $container->has(LoggerInterface::class)
            ? $container->get(LoggerInterface::class)
            : null;

        /** @var LoggerInterface|null $logger */
        $metrics = $container->has(MetricRegistry::class)
            ? $container->get(MetricRegistry::class)
            : null;

        /** @var MetricRegistry|null $metrics */
        $mailManager = new MailManager(
            $mailConfig,
            $httpClient,
            $eventDispatcher,
            $auditLogger,
            $logger,
            $metrics,
        );
        $container->instance(MailManager::class, $mailManager);
        $container->instance(MailManagerInterface::class, $mailManager);

        // HIPAA mode: register PHI scrubber
        if ($mailConfig->hipaaMode) {
            $phiScrubber = new PhiScrubber();
            $container->instance(PhiScrubber::class, $phiScrubber);
            $container->instance(PhiScrubberInterface::class, $phiScrubber);
        }
    }
}
