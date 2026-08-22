<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Webhook;

use Pulsar\Extension\Payments\Contracts\WebhookProcessorInterface;
use Pulsar\Extension\Payments\Features\ProcessWebhook\ProcessWebhookHandler;
use Pulsar\Extension\Payments\Features\ProcessWebhook\ProcessWebhookRequest;
use Pulsar\Http\Message\Response;

/**
 * Webhook processor: verify + deduplicate + dispatch.
 *
 * Uses record-after-success ordering to ensure failed dispatches are
 * retried by the vendor (never silently dropped). All verification,
 * deduplication, dispatch, audit and metrics work happens inside
 * ProcessWebhookHandler, so this class is a thin facade kept for the
 * stable WebhookProcessorInterface surface.
 */
final readonly class WebhookProcessor implements WebhookProcessorInterface
{
    public function __construct(
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
