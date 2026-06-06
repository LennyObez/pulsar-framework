<?php

declare(strict_types=1);

namespace Pulsar\Mail\Webhook\Verifier;

use Pulsar\Api\Internal;
use Pulsar\Mail\Webhook\WebhookRequest;
use Pulsar\Mail\Webhook\WebhookVerifierInterface;
use SensitiveParameter;

use function abs;
use function hash_equals;
use function hash_hmac;
use function is_array;
use function is_string;
use function json_decode;
use function time;

/**
 * Verifies Mailgun webhook signatures using HMAC-SHA256.
 *
 * Mailgun signs webhooks with HMAC-SHA256 using the signing key.
 * The signature is computed over the concatenation of timestamp + token.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final readonly class MailgunWebhookVerifier implements WebhookVerifierInterface
{
    private const int MAX_TIMESTAMP_DRIFT_SECONDS = 900;

    public function __construct(
        #[SensitiveParameter]
        private string $signingKey,
    ) {}

    public function verify(WebhookRequest $request): bool
    {
        $decoded = json_decode($request->payload, true);
        if (!is_array($decoded)) {
            return false;
        }

        /** @var array<string, mixed> $data */
        $data = $decoded;

        $signatureData = $data['signature'] ?? null;
        if (!is_array($signatureData)) {
            return false;
        }

        $timestamp = $signatureData['timestamp'] ?? null;
        $token = $signatureData['token'] ?? null;
        $signature = $signatureData['signature'] ?? null;

        if (!is_string($timestamp) || !is_string($token) || !is_string($signature)) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > self::MAX_TIMESTAMP_DRIFT_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . $token, $this->signingKey);

        return hash_equals($expected, $signature);
    }
}
