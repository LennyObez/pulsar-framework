<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Internal\Session;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\SocialSso\Contracts\OAuthStateManagerInterface;
use Pulsar\Security\Session\SessionInterface;

use function array_key_exists;
use function bin2hex;
use function is_array;
use function is_int;
use function random_bytes;
use function time;

/**
 * Session-backed OAuth state manager with TTL enforcement.
 *
 * Stores CSRF state tokens and PKCE code verifiers in the user's session.
 * State tokens are single-use and expire after the configured TTL to prevent
 * replay attacks and stale authorization flows.
 */
#[Internal]
final readonly class SessionOAuthStateManager implements OAuthStateManagerInterface
{
    private const string STATE_KEY = 'sso_state';
    private const string PKCE_KEY = 'sso_pkce';

    public function __construct(
        private SessionInterface $session,
        private int $ttlSeconds = 300,
    ) {}

    #[Override]
    public function generate(): string
    {
        $state = bin2hex(random_bytes(32));

        /** @var array<string, int> $states */
        $states = $this->session->get(self::STATE_KEY, []);

        if (!is_array($states)) {
            $states = [];
        }

        $states[$state] = time();
        $this->session->set(self::STATE_KEY, $states);

        return $state;
    }

    #[Override]
    public function verify(string $state): bool
    {
        /** @var array<string, int> $states */
        $states = $this->session->get(self::STATE_KEY, []);

        if (!is_array($states) || !array_key_exists($state, $states)) {
            return false;
        }

        $createdAt = $states[$state];

        // One-time consume: remove the state regardless of TTL validity
        unset($states[$state]);
        $this->session->set(self::STATE_KEY, $states);

        if (!is_int($createdAt)) {
            return false;
        }

        return (time() - $createdAt) <= $this->ttlSeconds;
    }

    /**
     * Store a PKCE code verifier associated with the given state token.
     */
    public function storePkceVerifier(string $state, string $verifier): void
    {
        /** @var array<string, string> $pkce */
        $pkce = $this->session->get(self::PKCE_KEY, []);

        if (!is_array($pkce)) {
            $pkce = [];
        }

        $pkce[$state] = $verifier;
        $this->session->set(self::PKCE_KEY, $pkce);
    }

    /**
     * Retrieve and delete the PKCE code verifier for the given state token.
     *
     * Returns null if no verifier was stored for this state.
     */
    public function retrievePkceVerifier(string $state): ?string
    {
        /** @var array<string, string> $pkce */
        $pkce = $this->session->get(self::PKCE_KEY, []);

        if (!is_array($pkce) || !array_key_exists($state, $pkce)) {
            return null;
        }

        $verifier = $pkce[$state];

        unset($pkce[$state]);
        $this->session->set(self::PKCE_KEY, $pkce);

        return $verifier;
    }
}
