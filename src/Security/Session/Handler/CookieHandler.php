<?php

declare(strict_types=1);

namespace Pulsar\Security\Session\Handler;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Config\SessionConfig;
use Pulsar\Security\Exception\SecurityException;
use Pulsar\Security\Session\SessionEncryption;

use function json_decode;
use function json_encode;
use function strlen;
use function time;

/**
 * Encrypted stateless cookie session handler for small payloads.
 *
 * Stores session data in an encrypted cookie on the client side. Provides
 * confidentiality + integrity + bounded lifetime. Replay prevention is
 * best-effort unless paired with server-side nonce tracking.
 *
 * Limitations (cannot be worked around; inherent to stateless design):
 * - No server-side state: cannot revoke individual sessions
 * - No session listing: cannot enumerate active sessions
 * - No concurrency control: cannot limit concurrent sessions per user
 * - Size constraints: strict 2KB default / 4KB hard cap on payload
 *
 * Not recommended for regulated workloads. Use Redis or Database handler instead.
 */
#[Internal]
final class CookieHandler implements CookieSessionHandlerInterface
{
    private const int HARD_SIZE_CAP = 4096;

    /** @var array<string, string> Session data indexed by session ID */
    private array $writeBuffer = [];

    /** @var array<string, string> Pending cookie data to be read */
    private array $readBuffer = [];

    public function __construct(
        private readonly SessionEncryption $encryption,
        private readonly SessionConfig $config,
    ) {}

    #[Override]
    public function open(string $path, string $name): bool
    {
        return true;
    }

    #[Override]
    public function close(): bool
    {
        return true;
    }

    #[Override]
    public function read(string $id): string
    {
        if (isset($this->readBuffer[$id])) {
            return $this->readBuffer[$id];
        }

        return '';
    }

    #[Override]
    public function write(string $id, string $data): bool
    {
        $payloadSize = strlen($data);
        $maxSize = min($this->config->cookieMaxPayloadSize, self::HARD_SIZE_CAP);

        if ($payloadSize > $maxSize) {
            throw SecurityException::sessionPayloadTooLarge($payloadSize, $maxSize);
        }

        $envelope = json_encode([
            'data' => $data,
            'iat' => time(),
        ], JSON_THROW_ON_ERROR);

        $encrypted = $this->encryption->encrypt(
            $envelope,
            $id,
            'cookie',
            $this->config->cookieDomain,
        );

        $this->writeBuffer[$id] = $encrypted;

        return true;
    }

    #[Override]
    public function destroy(string $id): bool
    {
        unset($this->writeBuffer[$id], $this->readBuffer[$id]);

        return true;
    }

    #[Override]
    public function gc(int $max_lifetime): int
    {
        // No server-side state to clean up.
        return 0;
    }

    #[Override]
    public function supportsConcurrencyControl(): bool
    {
        return false;
    }

    #[Override]
    public function supportsSessionListing(): bool
    {
        return false;
    }

    #[Override]
    public function supportsRevocation(): bool
    {
        return false;
    }

    /**
     * @return list<array{id: string, last_activity: int, ip_address: string, user_agent: string, created_at: int}>
     */
    #[Override]
    public function listSessions(string $userId): array
    {
        throw SecurityException::sessionHandlerNotSupported('session listing', 'cookie');
    }

    #[Override]
    public function revokeSession(string $sessionId): bool
    {
        throw SecurityException::sessionHandlerNotSupported('session revocation', 'cookie');
    }

    #[Override]
    public function getActiveSessions(string $userId): int
    {
        throw SecurityException::sessionHandlerNotSupported('concurrency control', 'cookie');
    }

    /**
     * Load encrypted cookie data for decryption and reading.
     *
     * Called by the session middleware before read() to inject the raw cookie value
     * received from the client request.
     */
    public function loadFromCookie(string $sessionId, string $encryptedCookie): void
    {
        try {
            $decrypted = $this->encryption->decrypt(
                $encryptedCookie,
                $sessionId,
                'cookie',
                $this->config->cookieDomain,
            );

            /** @var array{data?: string, iat?: int}|null $envelope */
            $envelope = json_decode($decrypted, true);

            if ($envelope === null || !isset($envelope['data'])) {
                return;
            }

            // Validate replay window
            $issuedAt = $envelope['iat'] ?? 0;
            $age = time() - $issuedAt;

            if ($age < 0 || $age > $this->config->cookieReplayWindow) {
                return;
            }

            $this->readBuffer[$sessionId] = $envelope['data'];
        } catch (SecurityException) {
            // Invalid or tampered cookie: silently ignore (treat as new session)
        }
    }

    /**
     * Get the encrypted cookie value for the given session ID to send to the client.
     *
     * Returns null if no write was performed for this session.
     */
    public function getCookieValue(string $sessionId): ?string
    {
        return $this->writeBuffer[$sessionId] ?? null;
    }
}
