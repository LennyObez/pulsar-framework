<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller;

use JsonException;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Internal\Commerce\WebhookHandler;
use Pulsar\Http\Message\Response;

/**
 * Public controller for payment provider webhook endpoints.
 *
 * Delegates payload processing to the WebhookHandler which verifies
 * the signature and handles event-specific logic. Returns 200 on success
 * or 400 on failure to satisfy webhook provider retry semantics.
 */
#[Internal(reason: 'CMS HTTP controller; implementation detail')]
final readonly class WebhookController
{
    public function __construct(
        private WebhookHandler $webhookHandler,
    ) {}

    public function handle(ServerRequestInterface $request): Response
    {
        $payload = (string) $request->getBody();
        $signature = $request->getHeaderLine('Stripe-Signature');

        if ($signature === '') {
            $signature = $request->getHeaderLine('X-Webhook-Signature');
        }

        if ($payload === '' || $signature === '') {
            return Response::json(['status' => 'error', 'message' => 'Missing payload or signature'], 400);
        }

        try {
            $this->webhookHandler->handle($payload, $signature);

            return Response::json(['status' => 'ok']);
        } catch (CmsException $e) {
            return Response::json(['status' => 'error', 'message' => $e->getMessage()], 400);
        } catch (JsonException) {
            return Response::json(['status' => 'error', 'message' => 'Invalid JSON payload'], 400);
        }
    }
}
