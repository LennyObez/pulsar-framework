<?php

declare(strict_types=1);

namespace Pulsar\Mail\Webhook\Verifier;

use Pulsar\Api\Internal;
use Pulsar\Mail\Webhook\WebhookRequest;
use Pulsar\Mail\Webhook\WebhookVerifierInterface;
use SensitiveParameter;

use function hash_equals;

/**
 * Verifies Postmark webhook requests via token comparison.
 *
 * Postmark authenticates webhooks by including a token in a custom header.
 * This verifier compares the header value against the configured token
 * using constant-time comparison.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final readonly class PostmarkWebhookVerifier implements WebhookVerifierInterface
{
    private const string TOKEN_HEADER = 'x-postmark-token';

    public function __construct(
        #[SensitiveParameter]
        private string $webhookToken,
    ) {}

    public function verify(WebhookRequest $request): bool
    {
        $headerToken = $request->headers[self::TOKEN_HEADER] ?? null;

        if ($headerToken === null) {
            return false;
        }

        return hash_equals($this->webhookToken, $headerToken);
    }
}
