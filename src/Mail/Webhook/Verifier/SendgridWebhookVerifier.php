<?php

declare(strict_types=1);

namespace Pulsar\Mail\Webhook\Verifier;

use Pulsar\Api\Internal;
use Pulsar\Mail\Webhook\WebhookRequest;
use Pulsar\Mail\Webhook\WebhookVerifierInterface;
use SensitiveParameter;

use function base64_decode;
use function openssl_pkey_get_public;
use function openssl_verify;

use const OPENSSL_ALGO_SHA256;

/**
 * Verifies SendGrid webhook signatures using ECDSA with SHA-256.
 *
 * SendGrid signs the request body using ECDSA and includes the signature
 * in the X-Twilio-Email-Event-Webhook-Signature header. The timestamp
 * from X-Twilio-Email-Event-Webhook-Timestamp is prepended to the body
 * before verification.
 */
#[Internal]
final readonly class SendgridWebhookVerifier implements WebhookVerifierInterface
{
    private const string SIGNATURE_HEADER = 'x-twilio-email-event-webhook-signature';
    private const string TIMESTAMP_HEADER = 'x-twilio-email-event-webhook-timestamp';

    public function __construct(
        #[SensitiveParameter]
        private string $verificationKey,
    ) {}

    public function verify(WebhookRequest $request): bool
    {
        $signature = $request->headers[self::SIGNATURE_HEADER] ?? null;
        $timestamp = $request->headers[self::TIMESTAMP_HEADER] ?? null;

        if ($signature === null || $timestamp === null) {
            return false;
        }

        $publicKey = openssl_pkey_get_public($this->verificationKey);
        if ($publicKey === false) {
            return false;
        }

        $payloadToVerify = $timestamp . $request->payload;
        $decodedSignature = base64_decode($signature, true);

        if ($decodedSignature === false) {
            return false;
        }

        return openssl_verify($payloadToVerify, $decodedSignature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }
}
