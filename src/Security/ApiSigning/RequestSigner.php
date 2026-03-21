<?php

declare(strict_types=1);

namespace Pulsar\Security\ApiSigning;

use DateMalformedStringException;
use DateTimeImmutable;
use InvalidArgumentException;
use NoDiscard;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;
use SensitiveParameter;
use SodiumException;

use function hash;
use function sodium_bin2hex;
use function sodium_crypto_generichash;
use function strlen;

use const SODIUM_CRYPTO_GENERICHASH_BYTES;
use const SODIUM_CRYPTO_GENERICHASH_KEYBYTES_MIN;

/**
 * HMAC-based API request signing using libsodium BLAKE2b.
 *
 * Signs: METHOD + PATH + TIMESTAMP + BODY_HASH
 * Similar in spirit to AWS Signature V4 but using BLAKE2b keyed hashing.
 *
 * Headers used:
 * - X-Signature: the hex-encoded HMAC
 * - X-Signature-Timestamp: ISO 8601 timestamp
 * - X-Signature-Key-Id: identifier for the signing key
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class RequestSigner
{
    public const string HEADER_SIGNATURE = 'X-Signature';
    public const string HEADER_TIMESTAMP = 'X-Signature-Timestamp';
    public const string HEADER_KEY_ID = 'X-Signature-Key-Id';

    public function __construct(
        #[SensitiveParameter]
        private string $signingKey,
        private string $keyId,
        private int $maxClockSkewSeconds = 300,
    ) {}

    /**
     * Sign a request by adding signature headers.
     *
     * @throws SodiumException
     */
    #[NoDiscard]
    public function sign(ServerRequestInterface $request): ServerRequestInterface
    {
        $timestamp = new DateTimeImmutable()->format('c');
        $body = (string) $request->getBody();

        $components = new SignedRequestComponents(
            method: $request->getMethod(),
            path: $request->getUri()->getPath(),
            timestamp: $timestamp,
            bodyHash: hash('sha256', $body),
            keyId: $this->keyId,
        );

        $signature = $this->computeSignature($components);

        return $request
            ->withHeader(self::HEADER_SIGNATURE, $signature)
            ->withHeader(self::HEADER_TIMESTAMP, $timestamp)
            ->withHeader(self::HEADER_KEY_ID, $this->keyId);
    }

    /**
     * Verify the signature on a request.
     *
     * Returns the verification result with reason on failure.
     *
     * @throws SodiumException
     */
    #[NoDiscard]
    public function verify(ServerRequestInterface $request): SignatureVerificationResult
    {
        $signature = $request->getHeaderLine(self::HEADER_SIGNATURE);
        $timestamp = $request->getHeaderLine(self::HEADER_TIMESTAMP);
        $keyId = $request->getHeaderLine(self::HEADER_KEY_ID);

        if ($signature === '' || $timestamp === '' || $keyId === '') {
            return SignatureVerificationResult::failure('Missing signature headers');
        }

        if ($keyId !== $this->keyId) {
            return SignatureVerificationResult::failure('Unknown key ID: ' . $keyId);
        }

        // Check clock skew
        try {
            $requestTime = new DateTimeImmutable($timestamp);
        } catch (DateMalformedStringException) {
            return SignatureVerificationResult::failure('Invalid timestamp format');
        }

        $now = new DateTimeImmutable();
        $skew = abs($now->getTimestamp() - $requestTime->getTimestamp());

        if ($skew > $this->maxClockSkewSeconds) {
            return SignatureVerificationResult::failure(
                'Timestamp outside allowed skew (' . $skew . 's > ' . $this->maxClockSkewSeconds . 's)',
            );
        }

        $body = (string) $request->getBody();

        $components = new SignedRequestComponents(
            method: $request->getMethod(),
            path: $request->getUri()->getPath(),
            timestamp: $timestamp,
            bodyHash: hash('sha256', $body),
            keyId: $keyId,
        );

        $expectedSignature = $this->computeSignature($components);

        if (!hash_equals($expectedSignature, $signature)) {
            return SignatureVerificationResult::failure('Signature mismatch');
        }

        return SignatureVerificationResult::success();
    }

    /**
     * @throws SodiumException
     */
    private function computeSignature(SignedRequestComponents $components): string
    {
        $message = $components->canonicalString();

        if (strlen($this->signingKey) < SODIUM_CRYPTO_GENERICHASH_KEYBYTES_MIN) {
            throw new InvalidArgumentException(
                'Signing key must be at least ' . SODIUM_CRYPTO_GENERICHASH_KEYBYTES_MIN . ' bytes',
            );
        }

        $raw = sodium_crypto_generichash($message, $this->signingKey, SODIUM_CRYPTO_GENERICHASH_BYTES);

        return sodium_bin2hex($raw);
    }
}
