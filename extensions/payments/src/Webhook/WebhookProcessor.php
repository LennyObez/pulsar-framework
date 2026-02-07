<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Webhook;

use Psr\Log\LoggerInterface;
use Pulsar\Extension\Payments\Config\PaymentsConfig;
<<<<<<< feat/modular-monolith-payments
use Pulsar\Extension\Payments\Contracts\ClockInterface;
use Pulsar\Extension\Payments\Contracts\WebhookEventLogInterface;
use Pulsar\Extension\Payments\Contracts\WebhookHandlerInterface;
use Pulsar\Extension\Payments\Contracts\WebhookProcessorInterface;
use Pulsar\Extension\Payments\Contracts\WebhookVerifierInterface;
use Pulsar\Extension\Payments\Features\ProcessWebhook\ProcessWebhookHandler;
use Pulsar\Extension\Payments\Features\ProcessWebhook\ProcessWebhookRequest;
use Pulsar\Http\Response;
use Pulsar\Observability\Metrics\MetricRegistry;
=======
use Pulsar\Extension\Payments\Contract\ClockInterface;
use Pulsar\Extension\Payments\Contract\WebhookEventLogInterface;
use Pulsar\Extension\Payments\Contract\WebhookHandlerInterface;
use Pulsar\Extension\Payments\Contract\WebhookVerifierInterface;
use Pulsar\Extension\Payments\Domain\WebhookEvent;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricRegistry;
use Throwable;
>>>>>>> main

/**
 * Webhook processor: verify + deduplicate + dispatch.
 *
 * Uses record-after-success ordering to ensure failed dispatches are
 * retried by the vendor (never silently dropped).
 */
<<<<<<< feat/modular-monolith-payments
final readonly class WebhookProcessor implements WebhookProcessorInterface
=======
final readonly class WebhookProcessor
>>>>>>> main
{
    public function __construct(
        private WebhookVerifierInterface $verifier,
        private WebhookEventLogInterface $eventLog,
        private WebhookHandlerInterface $handler,
        private ClockInterface $clock,
        private MetricRegistry $metricRegistry,
        private LoggerInterface $logger,
        private PaymentsConfig $config,
<<<<<<< feat/modular-monolith-payments
        private ?ProcessWebhookHandler $processHandler = null,
=======
>>>>>>> main
    ) {}

    /**
     * Process an incoming webhook request.
     *
     * @param string $rawBody Raw request body bytes
     * @param string $signatureHeader Signature header value
     */
    public function process(string $rawBody, string $signatureHeader): Response
    {
<<<<<<< feat/modular-monolith-payments
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
=======
        $webhookConfig = $this->config->webhook;
        $provider = $this->config->provider;

        // 1. Verify signature
        try {
            $this->verifier->verify(
                $rawBody,
                $signatureHeader,
                $webhookConfig->secret,
                $webhookConfig->toleranceSeconds,
            );
        } catch (Throwable $e) {
            $this->incrementWebhookMetric($provider, 'unknown', 'invalid_sig');
            $this->logger->warning('Webhook signature verification failed', [
                'error' => $e->getMessage(),
            ]);

            return Response::json(
                ['status' => 'invalid_signature'],
                ResponseStatus::Forbidden,
            );
        }

        // 2. Parse event
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        $event = WebhookEvent::fromArray($decoded);

        // 3. Claim event for deduplication
        $claim = $this->eventLog->claim(
            $event->id,
            $this->clock->now(),
            $this->config->webhookLog->ttlSeconds,
        );

        if ($claim->status === WebhookClaimStatus::Replay) {
            $this->incrementWebhookMetric($provider, $event->type->value, 'replay');

            return Response::json(['status' => 'already_processed']);
        }

        // 4. Dispatch to handler
        try {
            $this->handler->handle($event);
            $this->eventLog->commit($event->id);
            $this->incrementWebhookMetric($provider, $event->type->value, 'ok');

            return Response::json(['status' => 'processed']);
        } catch (Throwable $e) {
            $this->eventLog->release($event->id);
            $this->incrementWebhookMetric($provider, $event->type->value, 'handler_error');
            $this->metricRegistry->counter(
                'payments_webhook_handler_errors_total',
                'Total webhook handler errors',
            )->increment(new LabelSet([
                'provider' => $provider,
                'event_type' => $event->type->value,
            ]));

            $this->logger->error('Webhook handler failed', [
                'event_id' => $event->id,
                'event_type' => $event->type->value,
                'error' => $e->getMessage(),
            ]);

            return Response::json(
                ['status' => 'handler_error'],
                ResponseStatus::InternalServerError,
            );
        }
    }

    private function incrementWebhookMetric(string $provider, string $eventType, string $status): void
    {
        $this->metricRegistry->counter(
            'payments_webhooks_total',
            'Total webhook events processed',
        )->increment(new LabelSet([
            'provider' => $provider,
            'event_type' => $eventType,
            'status' => $status,
        ]));
>>>>>>> main
    }
}
