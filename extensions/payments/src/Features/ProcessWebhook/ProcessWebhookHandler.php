<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Features\ProcessWebhook;

use Psr\Log\LoggerInterface;
use Pulsar\Extension\Payments\Config\PaymentsConfig;
use Pulsar\Extension\Payments\Contracts\ClockInterface;
use Pulsar\Extension\Payments\Contracts\WebhookEventLogInterface;
use Pulsar\Extension\Payments\Contracts\WebhookHandlerInterface;
use Pulsar\Extension\Payments\Contracts\WebhookVerifierInterface;
use Pulsar\Extension\Payments\Domain\WebhookEvent;
use Pulsar\Extension\Payments\Webhook\WebhookClaimStatus;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricRegistry;
use Throwable;

/**
 * Process webhook use case handler.
 *
 * Orchestrates verify → deduplicate → dispatch pipeline.
 */
final readonly class ProcessWebhookHandler
{
    public function __construct(
        private WebhookVerifierInterface $verifier,
        private WebhookEventLogInterface $eventLog,
        private WebhookHandlerInterface $handler,
        private ClockInterface $clock,
        private MetricRegistry $metricRegistry,
        private LoggerInterface $logger,
        private PaymentsConfig $config,
    ) {}

    public function execute(ProcessWebhookRequest $request): ProcessWebhookResult
    {
        $webhookConfig = $this->config->webhook;
        $provider = $this->config->provider;

        // 1. Verify signature
        try {
            $this->verifier->verify(
                $request->rawBody,
                $request->signatureHeader,
                $webhookConfig->secret,
                $webhookConfig->toleranceSeconds,
            );
        } catch (Throwable $e) {
            $this->incrementWebhookMetric($provider, 'unknown', 'invalid_sig');
            $this->logger->warning('Webhook signature verification failed', [
                'error' => $e->getMessage(),
            ]);

            return new ProcessWebhookResult(
                Response::json(
                    ['status' => 'invalid_signature'],
                    ResponseStatus::Forbidden,
                ),
            );
        }

        // 2. Parse event
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($request->rawBody, true, 512, JSON_THROW_ON_ERROR);
        $event = WebhookEvent::fromArray($decoded);

        // 3. Claim event for deduplication
        $claim = $this->eventLog->claim(
            $event->id,
            $this->clock->now(),
            $this->config->webhookLog->ttlSeconds,
        );

        if ($claim->status === WebhookClaimStatus::Replay) {
            $this->incrementWebhookMetric($provider, $event->type->value, 'replay');

            return new ProcessWebhookResult(
                Response::json(['status' => 'already_processed']),
            );
        }

        // 4. Dispatch to handler
        try {
            $this->handler->handle($event);
            $this->eventLog->commit($event->id);
            $this->incrementWebhookMetric($provider, $event->type->value, 'ok');

            return new ProcessWebhookResult(
                Response::json(['status' => 'processed']),
            );
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

            return new ProcessWebhookResult(
                Response::json(
                    ['status' => 'handler_error'],
                    ResponseStatus::InternalServerError,
                ),
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
    }
}
