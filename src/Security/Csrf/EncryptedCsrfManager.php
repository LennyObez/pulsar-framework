<?php

declare(strict_types=1);

namespace Pulsar\Security\Csrf;

use Closure;
use JsonException;
use LogicException;
use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Security\Crypto\Encryptor;
use Pulsar\Security\Session\SessionInterface;
use Random\Engine\Secure;
use Random\Randomizer;
use RuntimeException;
use SodiumException;
use Throwable;

use function hash_equals;
use function is_string;
use function json_decode;
use function json_encode;
use function time;

use const JSON_THROW_ON_ERROR;

/**
 * AEAD-encrypted CSRF protection bound to the session, action, and time window.
 *
 * Token = encrypt(JSON{timestamp, session_id, action, nonce}, master_key).
 *
 * The token authenticity is guaranteed by the libsodium secretbox AEAD, and it
 * is bound to the current session id and a form action, so it cannot be forged
 * or transferred to another session or form. It carries no server-side state,
 * which is its appeal (CDN/stateless-friendly) but also its limit: a token
 * remains valid for its whole window, so one captured through a side channel
 * (logs, Referer, a shared proxy) CAN be replayed until it expires. Wire a
 * {@see CsrfReplayGuardInterface} to make it single-use; without one, the short
 * window is the only bound on a captured token. (The default session-backed
 * {@see CsrfTokenManager} does not have this limitation.)
 *
 * The session id is read fresh on every operation, never captured at
 * construction: a manager built at boot would otherwise freeze the empty
 * pre-session id and validate every anonymous client's tokens against each
 * other (hash_equals('', '') is true) — session binding would silently vanish.
 * Operations fail closed if no session is active.
 *
 * Requires PULSAR_MASTER_KEY (the Encryptor). Opt-in: not wired by default. See
 * ADR-0035 for why the session synchronizer remains the default manager.
 * @api
 */
#[Api(since: '1.0.0')]
final class EncryptedCsrfManager implements CsrfTokenManagerInterface
{
    private readonly Randomizer $randomizer;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param Encryptor $encryptor Holds the master key (libsodium secretbox).
     * @param SessionInterface $session Read for its CURRENT id per operation, so
     *     a post-login regenerate() rebinds automatically and pre-login tokens
     *     stop validating.
     * @param (Closure(): int)|null $clock Unix-seconds source; defaults to time().
     *     Injectable so the expiry window is testable.
     * @param CsrfReplayGuardInterface|null $replayGuard When set, each token is
     *     single-use: its nonce is consumed on first acceptance and refused on
     *     replay. When null, tokens are replayable within their window.
     */
    public function __construct(
        private readonly Encryptor $encryptor,
        private readonly SessionInterface $session,
        private readonly int $windowSeconds = 3600,
        private readonly string $defaultAction = '_default',
        ?Randomizer $randomizer = null,
        ?Closure $clock = null,
        private readonly ?CsrfReplayGuardInterface $replayGuard = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer(new Secure());
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * The current session id, or fail closed.
     *
     * Never returns '': binding a token to an empty id, then validating another
     * empty-id token against it, would make every anonymous client interchangeable.
     */
    private function sessionId(): string
    {
        $id = $this->session->id();

        if ($id === '') {
            throw new LogicException(
                'EncryptedCsrfManager needs an active session to bind the token; none is started.',
            );
        }

        return $id;
    }

    /**
     * Generate an encrypted CSRF token for the default action.
     *
     * @throws SodiumException
     */
    #[Override]
    public function generate(): string
    {
        return $this->generateForAction($this->defaultAction);
    }

    /**
     * Get a token (generates a new one each time).
     *
     * @throws SodiumException
     */
    #[Override]
    public function getToken(): string
    {
        return $this->generate();
    }

    /**
     * Validate a submitted token against the default action.
     *
     * @throws SodiumException
     */
    #[Override]
    public function validate(string $submittedToken): bool
    {
        return $this->validateForAction($submittedToken, $this->defaultAction);
    }

    /**
     * Rotate the session id (anti-fixation) then issue a fresh token bound to it.
     *
     * Because the session id is read fresh, regenerating it here also
     * invalidates every previously-issued token: they were bound to the old id
     * and now fail the session check. This is real rotation, not a reissue.
     *
     * @throws SodiumException
     */
    #[Override]
    public function rotate(): string
    {
        $this->session->regenerate(true);

        return $this->generate();
    }

    /**
     * Generate an encrypted token bound to a specific action and the current session.
     *
     * The encrypted payload contains:
     * - t: generation time (window enforcement)
     * - s: current session id (prevents cross-session transfer)
     * - a: action binding (prevents cross-form use)
     * - n: 16 random bytes — uniqueness, and the single-use key when a replay
     *      guard is wired
     *
     * @throws SodiumException
     */
    #[NoDiscard]
    public function generateForAction(string $action): string
    {
        $payload = json_encode([
            't' => ($this->clock)(),
            's' => $this->sessionId(),
            'a' => $action,
            'n' => bin2hex($this->randomizer->getBytes(16)),
        ], JSON_THROW_ON_ERROR);

        return $this->encryptor->encrypt($payload);
    }

    /**
     * Validate a token against a specific action.
     *
     * Checks, in order (cheapest and most-discriminating first, replay last so a
     * forged token can never burn a legitimate nonce):
     * 1. Decryption succeeds (AEAD authenticity — not forged or tampered)
     * 2. Session id matches the CURRENT session (no cross-session transfer)
     * 3. Action matches (no cross-form use)
     * 4. Timestamp within the window (no stale reuse)
     * 5. Nonce not already consumed, when a replay guard is wired (single-use)
     *
     * @throws SodiumException
     */
    public function validateForAction(string $submittedToken, string $action): bool
    {
        try {
            $plaintext = $this->encryptor->decrypt($submittedToken);
        } catch (Throwable) {
            return false;
        }

        try {
            /** @var array{t?: int, s?: string, a?: string, n?: string} $payload */
            $payload = json_decode($plaintext, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        // Verify session binding against the CURRENT session id.
        if (!isset($payload['s']) || !hash_equals($this->sessionId(), $payload['s'])) {
            return false;
        }

        // Verify action binding
        if (!isset($payload['a']) || !hash_equals($action, $payload['a'])) {
            return false;
        }

        // Verify timestamp window
        if (!isset($payload['t'])) {
            return false;
        }

        $tokenTime = $payload['t'];
        $now = ($this->clock)();

        if (($now - $tokenTime) > $this->windowSeconds || $tokenTime > $now) {
            return false;
        }

        // Single-use enforcement, last: only an otherwise-valid token consumes a
        // nonce, so an attacker cannot exhaust the guard with forged tokens.
        if ($this->replayGuard !== null) {
            $nonce = $payload['n'] ?? null;

            if (!is_string($nonce) || $nonce === '' || !$this->replayGuard->consume($nonce, $this->windowSeconds)) {
                return false;
            }
        }

        return true;
    }

    public function __debugInfo(): array
    {
        return [
            'encryptor' => '[ENCRYPTOR]',
            'sessionId' => '[REDACTED]',
            'windowSeconds' => $this->windowSeconds,
            'defaultAction' => $this->defaultAction,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function __serialize(): array
    {
        throw new RuntimeException('EncryptedCsrfManager must not be serialized');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        unset($data);
        throw new RuntimeException('EncryptedCsrfManager must not be serialized');
    }
}
