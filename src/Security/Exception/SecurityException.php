<?php

declare(strict_types=1);

namespace Pulsar\Security\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Base exception for all security-related errors.
 *
 * Provides static factory methods for specific security error scenarios.
 */
#[Api(since: '1.0.0')]
final class SecurityException extends RuntimeException
{
    /**
     * CSRF token validation failed.
     */
    #[NoDiscard]
    public static function csrfTokenMissing(): self
    {
        return new self('CSRF token is missing from the request');
    }

    /**
     * CSRF token does not match expected value.
     */
    #[NoDiscard]
    public static function csrfTokenInvalid(): self
    {
        return new self('CSRF token is invalid');
    }

    /**
     * Session has not been started.
     */
    #[NoDiscard]
    public static function sessionNotStarted(): self
    {
        return new self('Session has not been started');
    }

    /**
     * Session could not be started.
     */
    #[NoDiscard]
    public static function sessionStartFailed(): self
    {
        return new self('Failed to start session');
    }

    /**
     * Master key is missing from environment.
     */
    #[NoDiscard]
    public static function masterKeyMissing(): self
    {
        return new self('PULSAR_MASTER_KEY environment variable is not set');
    }

    /**
     * Master key has invalid format.
     */
    #[NoDiscard]
    public static function masterKeyInvalid(string $reason): self
    {
        return new self(sprintf('Invalid master key: %s', $reason));
    }

    /**
     * Encryption failed.
     */
    #[NoDiscard]
    public static function encryptionFailed(string $reason): self
    {
        return new self(sprintf('Encryption failed: %s', $reason));
    }

    /**
     * Decryption failed (wrong key, tampered ciphertext, etc.).
     */
    #[NoDiscard]
    public static function decryptionFailed(): self
    {
        return new self('Decryption failed: ciphertext is invalid or has been tampered with');
    }

    /**
     * Audit log integrity verification failed.
     */
    #[NoDiscard]
    public static function auditIntegrityViolation(string $entryId): self
    {
        return new self(sprintf('Audit log integrity violation for entry "%s"', $entryId));
    }

    /**
     * Audit chain state on disk is unverifiable (F24.3).
     *
     * Raised when an audit sink reports {@see \Pulsar\Security\Audit\AuditChainState::Corrupted}
     * — the file holds at least one entry but the last record cannot
     * be parsed, the `hmac` field is missing, or an IO failure
     * prevented the lookup. Continuing would silently break the
     * tamper-evidence chain by re-seeding over corrupt state, so the
     * logger refuses to write any further entries until the chain is
     * either rotated or restored.
     */
    #[NoDiscard]
    public static function auditChainCorrupted(string $reason): self
    {
        return new self(sprintf(
            'Audit chain integrity check failed: %s. Refusing to append further entries until the chain is rotated or restored.',
            $reason,
        ));
    }

    /**
     * Audit sink write failure.
     */
    #[NoDiscard]
    public static function auditWriteFailed(string $reason): self
    {
        return new self(sprintf('Failed to write audit entry: %s', $reason));
    }

    #[NoDiscard]
    public static function serializationForbidden(string $class): self
    {
        return new self(sprintf('Serialization of %s is forbidden: key material must not leave process memory', $class));
    }

    /**
     * Session validation failed for a specific validator.
     */
    #[NoDiscard]
    public static function sessionValidationFailed(string $validator): self
    {
        return new self(sprintf('Session validation failed: %s', $validator));
    }

    /**
     * Concurrent session limit exceeded.
     */
    #[NoDiscard]
    public static function sessionConcurrencyExceeded(int $max): self
    {
        return new self(sprintf('Concurrent session limit exceeded: maximum %d active sessions allowed', $max));
    }

    /**
     * Handler does not support the requested capability.
     */
    #[NoDiscard]
    public static function sessionHandlerNotSupported(string $capability, string $handler): self
    {
        return new self(sprintf('Session handler "%s" does not support %s', $handler, $capability));
    }

    /**
     * Session encryption operation failed.
     */
    #[NoDiscard]
    public static function sessionEncryptionFailed(string $reason): self
    {
        return new self(sprintf('Session encryption failed: %s', $reason));
    }

    /**
     * Session payload exceeds size limit.
     */
    #[NoDiscard]
    public static function sessionPayloadTooLarge(int $size, int $max): self
    {
        return new self(sprintf('Session payload size %d bytes exceeds maximum of %d bytes', $size, $max));
    }

    /**
     * Session has been idle for longer than the configured timeout (PCI-DSS 8.2.8).
     */
    #[NoDiscard]
    public static function sessionIdleExpired(int $idleSeconds, int $maxIdle): self
    {
        return new self(sprintf('Session idle timeout exceeded: %d seconds idle, maximum is %d seconds', $idleSeconds, $maxIdle));
    }

    /**
     * Session payload could not be JSON-encoded for storage.
     *
     * Pulsar 1.0.0-rc.12 stores sessions as JSON to eliminate the
     * unserialize() attack surface (HIGH-4 / CWE-502). Application
     * code that puts non-encodable values (resources, raw object
     * instances, closures) into the session triggers this exception
     * at save() time.
     */
    #[NoDiscard]
    public static function sessionEncodingFailed(string $reason): self
    {
        return new self(sprintf(
            'Failed to encode session payload as JSON: %s. Session values must be scalars, arrays, or JsonSerializable instances.',
            $reason,
        ));
    }

    /**
     * Secret vault key not found.
     */
    #[NoDiscard]
    public static function vaultKeyNotFound(string $key): self
    {
        return new self(sprintf('Secret "%s" not found in the vault', $key));
    }

    /**
     * Runtime security assertion failed.
     */
    #[NoDiscard]
    public static function assertionFailed(string $assertion, string $detail): self
    {
        return new self(sprintf('Security assertion failed [%s]: %s', $assertion, $detail));
    }
}
