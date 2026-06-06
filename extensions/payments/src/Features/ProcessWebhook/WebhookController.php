<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Features\ProcessWebhook;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Extension\Payments\Config\PaymentsConfig;
use Pulsar\Http\Message\Response;

/**
 * HTTP endpoint for incoming payment webhooks.
 */
final readonly class WebhookController
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        private ProcessWebhookHandler $handler,
        private PaymentsConfig $config,
    ) {}
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function handle(ServerRequestInterface $request): Response
    {
        $signatureHeader = $request->getHeaderLine($this->config->webhook->signatureHeader);

        return $this->handler->execute(
            new ProcessWebhookRequest((string) $request->getBody(), $signatureHeader),
        )->response;
    }
}
