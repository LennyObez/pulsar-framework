<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Internal\Token;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\SocialSso\Contracts\IdTokenVerifierInterface;
use Pulsar\Extension\SocialSso\Contracts\JwtSignatureDriverInterface;
use Pulsar\Extension\SocialSso\Domain\IdTokenClaims;
use Pulsar\Extension\SocialSso\Domain\IdTokenVerificationContext;
use Pulsar\Extension\SocialSso\Domain\JwkKey;
use Pulsar\Extension\SocialSso\Exception\SsoException;

use function array_filter;
use function base64_decode;
use function count;
use function explode;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function str_repeat;
use function str_replace;
use function strlen;
use function time;

use const ARRAY_FILTER_USE_BOTH;
use const JSON_THROW_ON_ERROR;

/**
 * JWKS-backed OIDC ID token verifier.
 *
 * Verifies JWT signatures using keys fetched from the provider's JWKS endpoint,
 * then validates all standard OIDC claims (issuer, audience, expiration, nonce)
 * per the OpenID Connect Core specification Section 3.1.3.7.
 */
#[Internal]
final readonly class JwksIdTokenVerifier implements IdTokenVerifierInterface
{
    public function __construct(
        private JwksFetcher $fetcher,
        private JwtSignatureDriverInterface $signatureDriver,
    ) {}

    #[Override]
    public function verify(string $idToken, IdTokenVerificationContext $context): IdTokenClaims
    {
        $parts = explode('.', $idToken);

        if (count($parts) !== 3) {
            throw SsoException::invalidIdToken('token must have exactly three segments');
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        // Decode header
        /** @var array{alg?: string, kid?: string, typ?: string} $header */
        $header = json_decode(self::base64UrlDecode($headerB64), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($header) || !isset($header['alg'])) {
            throw SsoException::invalidIdToken('missing algorithm in header');
        }

        /** @var string $alg */
        $alg = $header['alg'];

        // Reject "none" algorithm
        if ($alg === 'none') {
            throw SsoException::unsupportedAlgorithm('none');
        }

        // Check driver supports the algorithm
        if (!$this->signatureDriver->supports($alg)) {
            throw SsoException::unsupportedAlgorithm($alg);
        }

        // Resolve the signing key
        $key = $this->resolveSigningKey($context, $header);

        // Verify signature
        $signature = self::base64UrlDecode($signatureB64);

        if (!$this->signatureDriver->verify($headerB64, $payloadB64, $signature, $key)) {
            throw SsoException::signatureVerificationFailed();
        }

        // Decode payload
        /** @var array<string, mixed> $claims */
        $claims = json_decode(self::base64UrlDecode($payloadB64), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($claims)) {
            throw SsoException::invalidIdToken('payload is not a valid JSON object');
        }

        // Validate standard claims
        $this->validateClaims($claims, $context);

        // Build the IdTokenClaims DTO
        /** @var string $sub */
        $sub = $claims['sub'];
        /** @var string $iss */
        $iss = $claims['iss'];
        /** @var string|list<string> $aud */
        $aud = $claims['aud'];
        $exp = (int) $claims['exp'];
        $iat = (int) $claims['iat'];

        $nonce = isset($claims['nonce']) && is_string($claims['nonce']) ? $claims['nonce'] : null;
        $azp = isset($claims['azp']) && is_string($claims['azp']) ? $claims['azp'] : null;

        // Remove standard claims from the extras
        $standardKeys = ['sub', 'iss', 'aud', 'exp', 'iat', 'nonce', 'azp'];
        $extraClaims = array_filter(
            $claims,
            static fn (mixed $v, string $k): bool => !in_array($k, $standardKeys, true),
            ARRAY_FILTER_USE_BOTH,
        );

        return new IdTokenClaims(
            sub: $sub,
            iss: $iss,
            aud: $aud,
            exp: $exp,
            iat: $iat,
            nonce: $nonce,
            azp: $azp,
            claims: $extraClaims,
        );
    }

    /**
     * Resolve the signing key from JWKS using the token header.
     *
     * @param array{alg?: string, kid?: string, typ?: string} $header
     */
    private function resolveSigningKey(IdTokenVerificationContext $context, array $header): JwkKey
    {
        $jwksUri = $context->issuer . '/.well-known/jwks.json';
        $kid = $header['kid'] ?? null;

        if ($kid !== null) {
            $key = $this->fetcher->fetchKey($jwksUri, $kid);

            if ($key === null) {
                throw SsoException::invalidIdToken('key not found in JWKS for kid: ' . $kid);
            }

            return $key;
        }

        // No kid in header — determine key from JWKS
        $keys = $this->fetcher->fetchKeys($jwksUri);

        if (count($keys) === 0) {
            throw SsoException::invalidIdToken('JWKS contains no keys');
        }

        if (count($keys) > 1) {
            throw SsoException::invalidIdToken('missing kid for multi-key JWKS');
        }

        return $keys[0];
    }

    /**
     * Validate standard OIDC claims against the verification context.
     *
     * @param array<string, mixed> $claims
     */
    private function validateClaims(array $claims, IdTokenVerificationContext $context): void
    {
        // Required claims
        if (!isset($claims['sub']) || !is_string($claims['sub'])) {
            throw SsoException::invalidIdToken('missing or invalid "sub" claim');
        }

        if (!isset($claims['iss']) || !is_string($claims['iss'])) {
            throw SsoException::invalidIdToken('missing or invalid "iss" claim');
        }

        if (!isset($claims['aud'])) {
            throw SsoException::invalidIdToken('missing "aud" claim');
        }

        if (!isset($claims['exp'])) {
            throw SsoException::invalidIdToken('missing "exp" claim');
        }

        if (!isset($claims['iat'])) {
            throw SsoException::invalidIdToken('missing "iat" claim');
        }

        // Validate issuer
        if ($claims['iss'] !== $context->issuer) {
            throw SsoException::invalidIdToken('issuer mismatch');
        }

        // Validate audience
        /** @var string|list<string> $aud */
        $aud = $claims['aud'];
        $audList = is_array($aud) ? $aud : [$aud];

        if (!in_array($context->clientId, $audList, true)) {
            throw SsoException::invalidIdToken('audience mismatch');
        }

        // If aud contains multiple values, azp must be present and equal to clientId
        if (count($audList) > 1) {
            if (!isset($claims['azp']) || $claims['azp'] !== $context->clientId) {
                throw SsoException::invalidIdToken('azp must equal client_id when multiple audiences are present');
            }
        }

        // Validate expiration
        $now = time();
        $exp = (int) $claims['exp'];
        $iat = (int) $claims['iat'];

        if ($exp + $context->maxClockSkewSeconds < $now) {
            throw SsoException::invalidIdToken('token has expired');
        }

        if ($iat - $context->maxClockSkewSeconds > $now) {
            throw SsoException::invalidIdToken('token issued in the future');
        }

        // Validate nonce if expected
        if ($context->nonce !== null) {
            if (!isset($claims['nonce']) || !is_string($claims['nonce']) || $claims['nonce'] !== $context->nonce) {
                throw SsoException::invalidIdToken('nonce mismatch');
            }
        }
    }

    /**
     * Decode a base64url-encoded string (no padding, URL-safe alphabet).
     */
    private static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;

        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(str_replace(['-', '_'], ['+', '/'], $data), true);

        if ($decoded === false) {
            throw SsoException::invalidIdToken('invalid base64url encoding');
        }

        return $decoded;
    }
}
