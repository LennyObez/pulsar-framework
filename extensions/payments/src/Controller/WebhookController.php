<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Controller;

use Pulsar\Extension\Payments\Config\PaymentsConfig;
use Pulsar\Extension\Payments\Webhook\WebhookProcessor;
use Pulsar\Http\Request;
use Pulsar\Http\Response;

/**
 * HTTP endpoint for incoming payment webhooks.
 */
final readonly class WebhookController
{
    public function __construct(
        private WebhookProcessor $processor,
        private PaymentsConfig $config,
    ) {}

    public function handle(Request $request): Response
    {
        $signatureHeader = $request->header($this->config->webhook->signatureHeader) ?? '';

        return $this->processor->process($request->body, $signatureHeader);
    }
}
