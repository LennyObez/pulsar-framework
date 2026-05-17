<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\Payments\Domain\WebhookEvent;

/**
 * Webhook event handler contract.
 *
 * Implementations process webhook events dispatched by the WebhookProcessor.
 * @api
 */
#[Api(since: '1.0.0')]
interface WebhookHandlerInterface
{
    /**
     * Handle a verified webhook event.
     */
    public function handle(WebhookEvent $event): void;
}
