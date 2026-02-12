<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Internal\Session;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\SocialSso\Contracts\NonceVerifierInterface;
use Pulsar\Extension\SocialSso\Domain\IdTokenClaims;
use Pulsar\Security\Session\SessionInterface;

use function array_key_exists;
use function bin2hex;
use function hash_equals;
use function is_array;
use function random_bytes;

/**
 * Session-backed OIDC nonce verifier.
 *
 * Generates cryptographically random nonces stored in the user's session and
 * verifies them against the nonce claim in a verified ID token. Nonces are
 * single-use to prevent token replay attacks.
 */
#[Internal]
final readonly class SessionNonceVerifier implements NonceVerifierInterface
{
    private const string NONCE_KEY = 'sso_nonce';

    public function __construct(
        private SessionInterface $session,
    ) {}

    #[Override]
    public function generate(): string
    {
        $nonce = bin2hex(random_bytes(32));

        $raw = $this->session->get(self::NONCE_KEY, []);
        /** @var array<string, true> $nonces */
        $nonces = is_array($raw) ? $raw : [];

        $nonces[$nonce] = true;
        $this->session->set(self::NONCE_KEY, $nonces);

        return $nonce;
    }

    #[Override]
    public function verify(string $nonce, IdTokenClaims $verifiedClaims): bool
    {
        // The nonce from the ID token must match the expected nonce
        if ($verifiedClaims->nonce === null || !hash_equals($nonce, $verifiedClaims->nonce)) {
            return false;
        }

        $rawNonces = $this->session->get(self::NONCE_KEY, []);
        /** @var array<string, true> $nonces */
        $nonces = is_array($rawNonces) ? $rawNonces : [];

        if (!array_key_exists($nonce, $nonces)) {
            return false;
        }

        // One-time consume: remove the nonce
        unset($nonces[$nonce]);
        $this->session->set(self::NONCE_KEY, $nonces);

        return true;
    }
}
