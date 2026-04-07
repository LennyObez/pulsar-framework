<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Webhook;

use Psr\Log\LoggerInterface;
use Pulsar\Extension\Payments\Config\PaymentsConfig;
use Pulsar\Extension\Payments\Contracts\ClockInterface;
use Pulsar\Extension\Payments\Contracts\WebhookHandlerInterface;
use Pulsar\Extension\Payments\Contracts\WebhookProcessorInterface;
use Pulsar\Extension\Payments\Features\ProcessWebhook\ProcessWebhookHandler;
use Pulsar\Extension\Payments\Features\ProcessWebhook\ProcessWebhookRequest;
use Pulsar\Http\Message\Response;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Webhook\WebhookEventLogInterface;
use Pulsar\Webhook\WebhookVerifierInterface;

/**
 * Webhook processor: verify + deduplicate + dispatch.
 *
 * Uses record-after-success ordering to ensure failed dispatches are
 * retried by the vendor (never silently dropped).
 */
final readonly class WebhookProcessor implements WebhookProcessorInterface
{
    public function __construct(
        private WebhookVerifierInterface $verifier,
        private WebhookEventLogInterface $eventLog,
        private WebhookHandlerInterface $handler,
        private ClockInterface $clock,
        private MetricRegistry $metricRegistry,
        private LoggerInterface $logger,
        private PaymentsConfig $config,
        // F22.2: the process-webhook handler is now a required dependency.
        // The previous `?ProcessWebhookHandler = null` default hid a
        // service-locator pattern (`?? new ProcessWebhookHandler(...)`)
        // that hardcoded the handler's constructor signature inside the
        // processor. PaymentsServiceProvider already binds the handler
        // in the container, so production wiring was never affected;
        // tests passing `processHandler: null` were exercising the dead
        // fallback. Force the typed dependency so any future change to
        // the handler constructor reaches every call site through the
        // type system rather than silently breaking the inline fallback.
        private ProcessWebhookHandler $processHandler,
    ) {}

    /**
     * Process an incoming webhook request.
     *
     * @param string $rawBody Raw request body bytes
     * @param string $signatureHeader Signature header value
     */
    public function process(string $rawBody, string $signatureHeader): Response
    {
        return $this->processHandler->execute(
            new ProcessWebhookRequest($rawBody, $signatureHeader),
        )->response;
    }
}
