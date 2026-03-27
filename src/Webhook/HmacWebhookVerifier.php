<?php

declare(strict_types=1);

namespace Pulsar\Webhook;

use DateTimeImmutable;
use Override;
use Pulsar\Webhook\Exception\WebhookException;

use function abs;
use function str_starts_with;
use function substr;

/**
 * HMAC-SHA256 webhook signature verifier.
 *
 * Header format: t={unix_timestamp},v1={hex_signature}[,v1={hex_signature}...]
 * Multiple v1= values are allowed for secret rotation.
 */
final readonly class HmacWebhookVerifier implements WebhookVerifierInterface
{
    public function __construct(
        private ?DateTimeImmutable $now = null,
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
        $now = ($this->now ?? new DateTimeImmutable())->getTimestamp();
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
                if (!ctype_digit($value)) {
                    throw WebhookException::malformedHeader('invalid timestamp');
                }
                $timestamp = (int) $value;
            } elseif (str_starts_with($part, 'v1=')) {
                $value = substr($part, 3);
                if ($value !== '') {
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

        return ['timestamp' => $timestamp, 'signatures' => $signatures];
    }
}
