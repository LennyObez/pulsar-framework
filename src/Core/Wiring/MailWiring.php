<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\MailConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Http\TrustedProxy;
use Pulsar\Mail\MailManager;
use Pulsar\Mail\MailManagerInterface;
use Pulsar\Mail\Security\PhiScrubber;
use Pulsar\Mail\Security\PhiScrubberInterface;
use Pulsar\Mail\Transport\MailHttpClientInterface;
use Pulsar\Mail\Webhook\BounceHandler;
use Pulsar\Mail\Webhook\CacheBackedDeduplicationStore;
use Pulsar\Mail\Webhook\ComplaintHandler;
use Pulsar\Mail\Webhook\ConfigurableIpAllowlist;
use Pulsar\Mail\Webhook\InMemoryDeduplicationStore;
use Pulsar\Mail\Webhook\MailWebhookConfig;
use Pulsar\Mail\Webhook\MailWebhookController;
use Pulsar\Mail\Webhook\Verifier\MailgunWebhookVerifier;
use Pulsar\Mail\Webhook\Verifier\PostmarkWebhookVerifier;
use Pulsar\Mail\Webhook\Verifier\SendgridWebhookVerifier;
use Pulsar\Mail\Webhook\Verifier\SesWebhookVerifier;
use Pulsar\Mail\Webhook\WebhookHandler;
use Pulsar\Mail\Webhook\WebhookHandlerInterface;
use Pulsar\Mail\Webhook\WebhookVerifierInterface;
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

        $this->wireWebhooks($container, $mailConfig, $router, $auditLogger, $logger);
    }

    /**
     * Wire the opt-in inbound mail-webhook endpoint (signature verification,
     * replay window, deduplication, bounce/complaint handling) and its route.
     */
    private function wireWebhooks(
        ContainerInterface $container,
        MailConfig $mailConfig,
        Router $router,
        ?AuditLoggerInterface $auditLogger,
        ?LoggerInterface $logger,
    ): void {
        $config = $mailConfig->webhooks;

        if (!$config->isUsable()) {
            return;
        }

        $verifier = $this->buildWebhookVerifier($config);
        if ($verifier === null) {
            $logger?->warning('Mail webhooks enabled but provider is unknown; endpoint not wired.', [
                'provider' => $config->provider,
            ]);

            return;
        }

        if ($container->has(TaggedCacheInterface::class)) {
            /** @var TaggedCacheInterface $cache */
            $cache = $container->get(TaggedCacheInterface::class);
            $deduplicationStore = new CacheBackedDeduplicationStore($cache);
        } else {
            $deduplicationStore = new InMemoryDeduplicationStore();
            $logger?->warning('Mail webhook deduplication is in-memory (per-process): no cache bound.');
        }

        $handler = new WebhookHandler($verifier, $deduplicationStore, $auditLogger, $config->replayWindowSeconds);
        $container->instance(WebhookHandlerInterface::class, $handler);

        $bounceHandler = new BounceHandler($auditLogger);
        $complaintHandler = new ComplaintHandler($auditLogger);
        $container->instance(BounceHandler::class, $bounceHandler);
        $container->instance(ComplaintHandler::class, $complaintHandler);

        $ipAllowlist = $config->ipAllowlist !== []
            ? new ConfigurableIpAllowlist([$config->provider => $config->ipAllowlist])
            : null;

        $trustedProxy = $container->has(TrustedProxy::class)
            ? $container->get(TrustedProxy::class)
            : null;

        $controller = new MailWebhookController(
            $handler,
            $bounceHandler,
            $complaintHandler,
            $config->provider,
            $ipAllowlist,
            $trustedProxy,
        );
        $container->instance(MailWebhookController::class, $controller);

        $router->post($config->path, [MailWebhookController::class, 'handle'], 'pulsar.mail.webhook');
    }

    private function buildWebhookVerifier(MailWebhookConfig $config): ?WebhookVerifierInterface
    {
        return match ($config->provider) {
            'mailgun' => new MailgunWebhookVerifier($config->secret),
            'postmark' => new PostmarkWebhookVerifier($config->secret),
            'sendgrid' => new SendgridWebhookVerifier($config->secret),
            'ses' => new SesWebhookVerifier(),
            default => null,
        };
    }
}
