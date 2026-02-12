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
        private ?ProcessWebhookHandler $processHandler = null,
    ) {}

    /**
     * Process an incoming webhook request.
     *
     * @param string $rawBody Raw request body bytes
     * @param string $signatureHeader Signature header value
     */
    public function process(string $rawBody, string $signatureHeader): Response
    {
        $handler = $this->processHandler ?? new ProcessWebhookHandler(
            $this->verifier,
            $this->eventLog,
            $this->handler,
            $this->clock,
            $this->metricRegistry,
            $this->logger,
            $this->config,
        );

        return $handler->execute(
            new ProcessWebhookRequest($rawBody, $signatureHeader),
        )->response;
    }
}
