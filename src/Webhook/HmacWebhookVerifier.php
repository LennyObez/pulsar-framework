<?php

declare(strict_types=1);

namespace Pulsar\Webhook;

use DateTimeImmutable;
use Override;
use Pulsar\Webhook\Exception\WebhookException;

use function abs;
use function ctype_xdigit;
use function strlen;
use function str_starts_with;
use function strtolower;
use function substr;

/**
 * HMAC-SHA256 webhook signature verifier.
 *
 * Header format: t={unix_timestamp},v1={hex_signature}[,v1={hex_signature}...]
 * Multiple v1= values are allowed for secret rotation.
 */
final readonly class HmacWebhookVerifier implements WebhookVerifierInterface
{
    /**
     * F25.8: HMAC-SHA256 produces 32 bytes = 64 hex chars. Any v1=
     * value with a different length is malformed and must be
     * rejected before reaching `hash_equals` (defence-in-depth — an
     * attacker who can submit any non-empty v1= forced the verifier
     * to compare against arbitrary attacker-supplied bytes, even
     * though the constant-time guarantee holds).
     */
    private const int HMAC_HEX_LENGTH = 64;

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

                if ($value === '') {
                    continue;
                }

                // F25.8: reject anything that isn't exactly 64 lowercase
                // hex chars. The HMAC comparison further down uses
                // hash_equals (constant time), but only on values that
                // first pass through this filter — denying junk early
                // keeps the audit trail clean and the verification path
                // free of attacker-controlled length / charset.
                $value = strtolower($value);

                if (strlen($value) !== self::HMAC_HEX_LENGTH || !ctype_xdigit($value)) {
                    throw WebhookException::malformedHeader('non-hex v1 signature');
                }

                $signatures[] = $value;
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
