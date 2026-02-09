<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;
use Throwable;

use function sprintf;

/**
 * Domain-level SSO exceptions.
 *
 * Each static factory produces a semantically typed exception with a machine-readable
 * error type for structured error handling and logging.
 */
#[Api(since: '1.0.0')]
final class SsoException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $errorType,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    #[NoDiscard]
    public static function invalidState(): self
    {
        return new self('OAuth state parameter is invalid or expired', 'invalid_state');
    }

    #[NoDiscard]
    public static function invalidNonce(): self
    {
        return new self('OpenID Connect nonce verification failed', 'invalid_nonce');
    }

    #[NoDiscard]
    public static function invalidIdToken(string $reason): self
    {
        return new self(
            sprintf('ID token verification failed: %s', $reason),
            'invalid_id_token',
        );
    }

    #[NoDiscard]
    public static function unexpectedIdToken(): self
    {
        return new self('Received an ID token when none was expected', 'unexpected_id_token');
    }

    #[NoDiscard]
    public static function tokenExchangeFailed(): self
    {
        return new self('OAuth token exchange failed', 'token_exchange_failed');
    }

    #[NoDiscard]
    public static function providerNotFound(): self
    {
        return new self('OAuth provider not found in registry', 'provider_not_found');
    }

    #[NoDiscard]
    public static function providerError(): self
    {
        return new self('OAuth provider returned an error', 'provider_error');
    }

    #[NoDiscard]
    public static function missingAuthorizationCode(): self
    {
        return new self('Authorization code is missing from the callback', 'missing_authorization_code');
    }

    #[NoDiscard]
    public static function extensionDisabled(): self
    {
        return new self('Social SSO extension is disabled', 'extension_disabled');
    }

    #[NoDiscard]
    public static function pkceRequired(): self
    {
        return new self('PKCE is required but no code verifier was provided', 'pkce_required');
    }

    #[NoDiscard]
    public static function jwksFetchFailed(): self
    {
        return new self('Failed to fetch JWKS from the provider', 'jwks_fetch_failed');
    }

    #[NoDiscard]
    public static function signatureVerificationFailed(): self
    {
        return new self('JWT signature verification failed', 'signature_verification_failed');
    }

    #[NoDiscard]
    public static function unsupportedAlgorithm(string $alg): self
    {
        return new self(
            sprintf('Unsupported JWT signing algorithm: %s', $alg),
            'unsupported_algorithm',
        );
    }
}
