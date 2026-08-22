<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Internal\Infrastructure\Webhook;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Contracts\ClockInterface;
use Pulsar\Webhook\Exception\WebhookException;
use Pulsar\Webhook\WebhookVerifierInterface;

use function abs;
use function ctype_xdigit;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;

/**
 * HMAC-SHA256 webhook signature verifier.
 *
 * Header format: t={unix_timestamp},v1={hex_signature}[,v1={hex_signature}...]
 * Multiple v1= values are allowed for secret rotation.
 */
#[Internal]
final readonly class HmacWebhookVerifier implements WebhookVerifierInterface
{
    /** HMAC-SHA256 produces 32 bytes = 64 hex chars. */
    private const int HMAC_HEX_LENGTH = 64;

    public function __construct(
        private ClockInterface $clock,
    ) {}

    #[Override]
    public function verify(
        string $payload,
        string $signatureHeader,
        string $secret,
        int $toleranceSeconds,
    ): void {
        if ($secret === '') {
            throw WebhookException::emptySecret();
        }

        $parsed = self::parseHeader($signatureHeader);
        $timestamp = $parsed['timestamp'];
        $signatures = $parsed['signatures'];

        // Check timestamp tolerance
        $now = $this->clock->now()->getTimestamp();
        $age = abs($now - $timestamp);

        if ($age > $toleranceSeconds) {
            throw WebhookException::expiredTimestamp($age, $toleranceSeconds);
        }

        // Compute expected signature
        $signedPayload = $timestamp . '.' . $payload;
        $expected = hash_hmac('sha256', $signedPayload, $secret);

        // Check each candidate with constant-time comparison
        foreach ($signatures as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return;
            }
        }

        throw WebhookException::invalidSignature();
    }

    /**
     * @return array{timestamp: int, signatures: list<string>}
     *
     * @throws WebhookException
     */
    private static function parseHeader(string $header): array
    {
        if ($header === '') {
            throw WebhookException::malformedHeader('empty header');
        }

        $parts = explode(',', $header);
        $timestamp = null;
        $signatures = [];

        foreach ($parts as $part) {
            $part = trim($part);

            if (str_starts_with($part, 't=')) {
                $value = substr($part, 2);
                if ($value === '' || !ctype_digit($value)) {
                    throw WebhookException::malformedHeader('invalid timestamp');
                }
                $timestamp = (int) $value;
            } elseif (str_starts_with($part, 'v1=')) {
                $value = substr($part, 3);

                if ($value !== '') {
                    // Buffer raw v1; validate after timestamp /
                    // signatures-present checks so error priority
                    // is stable.
                    $signatures[] = $value;
                }
            }
        }

        if ($timestamp === null) {
            throw WebhookException::malformedHeader('missing timestamp');
        }

        if ($signatures === []) {
            throw WebhookException::malformedHeader('no v1 signatures');
        }

        // Validate each v1 is 64 lowercase hex AFTER the
        // timestamp / signatures-present checks so error priority
        // is consistent with the parser's lexical order.
        $validated = [];

        foreach ($signatures as $signature) {
            $signature = strtolower($signature);

            if (strlen($signature) !== self::HMAC_HEX_LENGTH || !ctype_xdigit($signature)) {
                throw WebhookException::malformedHeader('non-hex v1 signature');
            }

            $validated[] = $signature;
        }

        return ['timestamp' => $timestamp, 'signatures' => $validated];
    }
}
