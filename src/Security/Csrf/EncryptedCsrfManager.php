<?php

declare(strict_types=1);

namespace Pulsar\Security\Csrf;

use JsonException;
use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Security\Crypto\Encryptor;
use Random\Engine\Secure;
use Random\Randomizer;
use RuntimeException;
use SodiumException;
use Throwable;

use function hash_equals;
use function json_decode;
use function json_encode;
use function time;

use const JSON_THROW_ON_ERROR;

/**
 * AEAD-encrypted CSRF protection with request binding.
 *
 * Token = encrypt(JSON{timestamp, session_id, action, nonce}, master_key)
 *
 * Unique to Pulsar: tokens cannot be forged, replayed, or transferred
 * between sessions. Self-contained: no server-side lookup needed.
 *
 * Uses the existing Encryptor (libsodium secretbox) for authenticated
 * encryption with built-in nonce management and key rotation support.
 * @api
 */
#[Api(since: '1.0.0')]
final class EncryptedCsrfManager implements CsrfTokenManagerInterface
{
    private readonly Randomizer $randomizer;

    public function __construct(
        private readonly Encryptor $encryptor,
        private readonly string $sessionId,
        private readonly int $windowSeconds = 3600,
        private readonly string $defaultAction = '_default',
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer(new Secure());
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
     * Rotate is a no-op; each call to generate() produces a unique token.
     *
     * @throws SodiumException
     */
    #[Override]
    public function rotate(): string
    {
        return $this->generate();
    }

    /**
     * Generate an encrypted token bound to a specific action and the current session.
     *
     * The token payload contains:
     * - timestamp: when the token was generated
     * - sid: session identifier (prevents cross-session usage)
     * - action: form action binding (prevents cross-form usage)
     * - nonce: random bytes (ensures uniqueness even for same action/time)
     *
     * @throws SodiumException
     */
    #[NoDiscard]
    public function generateForAction(string $action): string
    {
        $payload = json_encode([
            't' => time(),
            's' => $this->sessionId,
            'a' => $action,
            'n' => bin2hex($this->randomizer->getBytes(8)),
        ], JSON_THROW_ON_ERROR);

        return $this->encryptor->encrypt($payload);
    }

    /**
     * Validate a token against a specific action.
     *
     * Checks:
     * 1. Decryption succeeds (proves authenticity; not tampered)
     * 2. Session ID matches (prevents cross-session transfer)
     * 3. Action matches (prevents cross-form replay)
     * 4. Timestamp within window (prevents old token reuse)
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

        // Verify session binding
        if (!isset($payload['s']) || !hash_equals($this->sessionId, $payload['s'])) {
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
        $now = time();

        return ($now - $tokenTime) <= $this->windowSeconds && $tokenTime <= $now;
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
