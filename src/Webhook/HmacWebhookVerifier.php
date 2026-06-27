<?php

declare(strict_types=1);

namespace Pulsar\Webhook;

use DateTimeImmutable;
use Override;
use Pulsar\Webhook\Exception\WebhookException;

use function abs;
use function count;
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

    /**
     * F25.8: a 13+ digit timestamp saturates the (int) cast toward
     * PHP_INT_MAX on 64-bit. 12 digits covers Unix seconds through the
     * year 33658, so any legitimate timestamp fits and overflow inputs
     * are rejected as malformed.
     */
    private const int MAX_TIMESTAMP_DIGITS = 12;

    /**
     * F25.8: cap the number of v1= candidates a single header may carry.
     * Each candidate forces one HMAC computation in verify(); without a
     * cap a single request can demand thousands of HMACs (CPU
     * amplification). Five comfortably covers any key-rotation window.
     */
    private const int MAX_V1_SIGNATURES = 5;

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

                // Reject non-numeric timestamps and any digit string longer
                // than 12 chars: a 13+ digit value silently saturates the
                // (int) cast toward PHP_INT_MAX on 64-bit and produces a
                // nonsensical "too old" age. 12 digits covers Unix
                // timestamps through year 33658 — generous for any caller.
                if (!ctype_digit($value) || strlen($value) > self::MAX_TIMESTAMP_DIGITS) {
                    throw WebhookException::malformedHeader('invalid timestamp');
                }
                $timestamp = (int) $value;
            } elseif (str_starts_with($part, 'v1=')) {
                $value = substr($part, 3);

                if ($value !== '') {
                    // Buffer raw v1; validate after timestamp /
                    // signatures-present checks so error priority
                    // matches the parser's lexical order (missing
                    // timestamp wins over malformed v1).
                    $signatures[] = $value;

                    // Cap the candidate count BEFORE any HMAC work: each v1
                    // entry forces one hash_hmac + hash_equals in verify(),
                    // so an unbounded header is a CPU-amplification vector.
                    // Five is generous for any key-rotation window.
                    if (count($signatures) > self::MAX_V1_SIGNATURES) {
                        throw WebhookException::malformedHeader('too many v1 signatures');
                    }
                }
            }
        }

        if ($timestamp === null) {
            throw WebhookException::malformedHeader('missing timestamp');
        }

        if ($signatures === []) {
            throw WebhookException::malformedHeader('no v1 signatures');
        }

        // F25.8: validate each v1 is exactly 64 lowercase hex chars
        // AFTER the timestamp / signatures-present checks so error
        // priority is consistent.
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
