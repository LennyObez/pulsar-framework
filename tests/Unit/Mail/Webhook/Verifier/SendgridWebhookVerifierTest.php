<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Webhook\Verifier;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Webhook\Verifier\SendgridWebhookVerifier;
use Pulsar\Mail\Webhook\WebhookRequest;

use function base64_encode;
use function time;

#[CoversClass(SendgridWebhookVerifier::class)]
final class SendgridWebhookVerifierTest extends TestCase
{
    #[Test]
    public function verifiesValidSignature(): void
    {
        $keyPair = @openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);

        if ($keyPair === false) {
            self::markTestSkipped('EC key generation unavailable (missing OpenSSL config)');
        }

        openssl_pkey_export($keyPair, $privateKeyPem);
        $details = openssl_pkey_get_details($keyPair);
        self::assertIsArray($details);
        $publicKey = $details['key'];
        self::assertIsString($publicKey);

        $verifier = new SendgridWebhookVerifier($publicKey);

        $timestamp = (string) time();
        $payload = '{"event":"delivered"}';
        $dataToSign = $timestamp . $payload;

        $signature = '';
        openssl_sign($dataToSign, $signature, $keyPair, OPENSSL_ALGO_SHA256);
        self::assertIsString($signature);
        $encodedSignature = base64_encode($signature);

        $request = new WebhookRequest(
            $payload,
            [
                'x-twilio-email-event-webhook-signature' => $encodedSignature,
                'x-twilio-email-event-webhook-timestamp' => $timestamp,
            ],
            '10.0.0.1',
            time(),
            'sendgrid',
        );

        self::assertTrue($verifier->verify($request));
    }

    #[Test]
    public function rejectsMissingSignatureHeader(): void
    {
        $verifier = new SendgridWebhookVerifier('dummy-key');

        $request = new WebhookRequest(
            '{"event":"delivered"}',
            [
                'x-twilio-email-event-webhook-timestamp' => (string) time(),
            ],
            '10.0.0.1',
            time(),
            'sendgrid',
        );

        self::assertFalse($verifier->verify($request));
    }

    #[Test]
    public function rejectsMissingTimestampHeader(): void
    {
        $verifier = new SendgridWebhookVerifier('dummy-key');

        $request = new WebhookRequest(
            '{"event":"delivered"}',
            [
                'x-twilio-email-event-webhook-signature' => base64_encode('some-sig'),
            ],
            '10.0.0.1',
            time(),
            'sendgrid',
        );

        self::assertFalse($verifier->verify($request));
    }

    #[Test]
    public function rejectsMissingBothHeaders(): void
    {
        $verifier = new SendgridWebhookVerifier('dummy-key');

        $request = new WebhookRequest(
            '{"event":"delivered"}',
            [],
            '10.0.0.1',
            time(),
            'sendgrid',
        );

        self::assertFalse($verifier->verify($request));
    }

    #[Test]
    public function rejectsInvalidPublicKey(): void
    {
        $verifier = new SendgridWebhookVerifier('not-a-valid-key');

        $request = new WebhookRequest(
            '{"event":"delivered"}',
            [
                'x-twilio-email-event-webhook-signature' => base64_encode('some-sig'),
                'x-twilio-email-event-webhook-timestamp' => (string) time(),
            ],
            '10.0.0.1',
            time(),
            'sendgrid',
        );

        self::assertFalse($verifier->verify($request));
    }

    #[Test]
    public function rejectsInvalidBase64Signature(): void
    {
        $verifier = new SendgridWebhookVerifier('not-a-valid-key');

        $request = new WebhookRequest(
            '{"event":"delivered"}',
            [
                'x-twilio-email-event-webhook-signature' => '!!!not-base64!!!',
                'x-twilio-email-event-webhook-timestamp' => (string) time(),
            ],
            '10.0.0.1',
            time(),
            'sendgrid',
        );

        self::assertFalse($verifier->verify($request));
    }
}
