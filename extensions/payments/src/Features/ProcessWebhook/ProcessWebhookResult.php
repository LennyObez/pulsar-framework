<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Features\ProcessWebhook;

use Pulsar\Http\Message\Response;

/**
 * Result DTO for processing a webhook.
 */
final readonly class ProcessWebhookResult
{
    public function __construct(
        public Response $response,
    ) {}
}
