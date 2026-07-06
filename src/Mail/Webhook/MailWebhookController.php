<?php

declare(strict_types=1);

namespace Pulsar\Mail\Webhook;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Http\Message\Response;
use Pulsar\Http\ResponseStatus;
use Pulsar\Http\TrustedProxy;

use function is_string;
use function time;

/**
 * Inbound mail-provider webhook endpoint.
 *
 * Builds a {@see WebhookRequest} from the HTTP request, enforces the optional
 * source-IP allowlist, runs the secure handler (signature verification, replay
 * window, deduplication), and dispatches bounce/complaint events to their
 * handlers. Wired only when mail webhooks are configured (see MailWiring).
 *
 * The request timestamp is the receive time: replay protection comes from the
 * provider signature (which the verifiers check) and event-id deduplication;
 * the handler's replay window is an additional clock-skew guard.
 */
#[Internal]
final readonly class MailWebhookController
{
    public function __construct(
        private WebhookHandlerInterface $handler,
        private BounceHandler $bounceHandler,
        private ComplaintHandler $complaintHandler,
        private string $provider,
        private ?IpAllowlistInterface $ipAllowlist = null,
        private ?TrustedProxy $trustedProxy = null,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $sourceIp = $this->clientIp($request);

        if ($this->ipAllowlist !== null && !$this->ipAllowlist->isAllowed($sourceIp, $this->provider)) {
            return Response::json(['error' => 'Source not permitted'], ResponseStatus::Forbidden->value);
        }

        $webhookRequest = new WebhookRequest(
            payload: (string) $request->getBody(),
            headers: $this->flattenHeaders($request),
            sourceIp: $sourceIp,
            timestamp: time(),
            provider: $this->provider,
        );

        $result = $this->handler->handle($webhookRequest);

        if (!$result->accepted) {
            return Response::json(['error' => 'Webhook rejected'], ResponseStatus::Unauthorized->value);
        }

        match ($result->eventType) {
            WebhookEventType::Bounce => $this->bounceHandler->process($webhookRequest),
            WebhookEventType::Complaint => $this->complaintHandler->process($webhookRequest),
            default => null,
        };

        return Response::json([
            'status' => 'accepted',
            'event_type' => $result->eventType->value,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function flattenHeaders(ServerRequestInterface $request): array
    {
        /** @var array<string, string> $headers */
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[(string) $name] = $values[0] ?? '';
        }

        return $headers;
    }

    private function clientIp(ServerRequestInterface $request): string
    {
        if ($this->trustedProxy !== null) {
            return $this->trustedProxy->resolveClientIp($request);
        }

        /** @var mixed $ip */
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '';

        return is_string($ip) ? $ip : '';
    }
}
