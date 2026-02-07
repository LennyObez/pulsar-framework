<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Features\ProcessWebhook;

use Pulsar\Extension\Payments\Config\PaymentsConfig;
use Pulsar\Http\Request;
use Pulsar\Http\Response;

/**
 * HTTP endpoint for incoming payment webhooks.
 */
final readonly class WebhookController
{
    public function __construct(
        private ProcessWebhookHandler $handler,
        private PaymentsConfig $config,
    ) {}

    public function handle(Request $request): Response
    {
        $signatureHeader = $request->header($this->config->webhook->signatureHeader) ?? '';

        return $this->handler->execute(
            new ProcessWebhookRequest($request->body, $signatureHeader),
        )->response;
    }
}
