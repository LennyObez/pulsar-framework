<?php

declare(strict_types=1);

namespace Pulsar\Security\Csrf;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use RuntimeException;
use SensitiveParameter;
use SodiumException;

use function bin2hex;
use function hex2bin;
use function pack;
use function sodium_crypto_auth;
use function sodium_crypto_auth_verify;
use function strlen;
use function substr;
use function time;
use function unpack;

/**
 * Stateless CSRF protection using libsodium HMAC.
 *
 * Token = hex(timestamp_bytes || hmac(timestamp || action, secret_key))
 *
 * No server-side state required; compatible with CDN/Varnish caching
 * and stateless API architectures. Each token is action-bound: a token
 * generated for "create_post" cannot be replayed against "delete_post".
 *
 * Uses sodium_crypto_auth (HMAC-SHA-512/256) instead of hash_hmac
 * for constant-time verification and libsodium key management.
 * @api
 */
#[Api(since: '1.0.0')]
final class StatelessCsrfManager implements CsrfTokenManagerInterface
{
    private const int TIMESTAMP_BYTES = 8;

    public function __construct(
        #[SensitiveParameter]
        private readonly string $secretKey,
        private readonly int $windowSeconds = 600,
        private readonly string $defaultAction = '_default',
    ) {}

    /**
     * Generate a stateless CSRF token for the default action.
     *
     * @throws SodiumException
     */
    #[Override]
    public function generate(): string
    {
        return $this->generateForAction($this->defaultAction);
    }

    /**
     * Get a token (generates a new one; stateless, no storage).
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
     * Rotate is a no-op for stateless tokens.
     *
     * @throws SodiumException
     */
    #[Override]
    public function rotate(): string
    {
        return $this->generate();
    }

    /**
     * Generate a token bound to a specific action.
     *
     * @throws SodiumException
     */
    #[NoDiscard]
    public function generateForAction(string $action): string
    {
        $timestamp = time();
        $timestampBytes = pack('J', $timestamp);
        $message = $timestampBytes . $action;

        $mac = sodium_crypto_auth($message, $this->secretKey);

        return bin2hex($timestampBytes . $mac);
    }

    /**
     * Validate a token against a specific action.
     *
     * Checks both the HMAC and the timestamp window.
     *
     * @throws SodiumException
     */
    public function validateForAction(string $submittedToken, string $action): bool
    {
        $raw = @hex2bin($submittedToken);

        if ($raw === false) {
            return false;
        }

        $expectedLength = self::TIMESTAMP_BYTES + SODIUM_CRYPTO_AUTH_BYTES;

        if (strlen($raw) !== $expectedLength) {
            return false;
        }

        $timestampBytes = substr($raw, 0, self::TIMESTAMP_BYTES);
        $mac = substr($raw, self::TIMESTAMP_BYTES);

        // Verify HMAC first (constant-time)
        $message = $timestampBytes . $action;

        if (!sodium_crypto_auth_verify($mac, $message, $this->secretKey)) {
            return false;
        }

        // Verify timestamp window
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('J', $timestampBytes);
        $tokenTime = $unpacked[1];
        $now = time();

        return ($now - $tokenTime) <= $this->windowSeconds && $tokenTime <= $now;
    }

    public function __debugInfo(): array
    {
        return [
            'secretKey' => '[REDACTED]',
            'windowSeconds' => $this->windowSeconds,
            'defaultAction' => $this->defaultAction,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function __serialize(): array
    {
        throw new RuntimeException('StatelessCsrfManager must not be serialized');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new RuntimeException('StatelessCsrfManager must not be serialized');
    }
}
