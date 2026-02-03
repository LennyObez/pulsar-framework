<?php

declare(strict_types=1);

namespace Pulsar\Security\Exception;

use RuntimeException;

use function sprintf;

/**
 * Base exception for all security-related errors.
 *
 * Provides static factory methods for specific security error scenarios.
 */
final class SecurityException extends RuntimeException
{
    /**
     * CSRF token validation failed.
     */
    public static function csrfTokenMissing(): self
    {
        return new self('CSRF token is missing from the request');
    }

    /**
     * CSRF token does not match expected value.
     */
    public static function csrfTokenInvalid(): self
    {
        return new self('CSRF token is invalid');
    }

    /**
     * Session has not been started.
     */
    public static function sessionNotStarted(): self
    {
        return new self('Session has not been started');
    }

    /**
     * Session could not be started.
     */
    public static function sessionStartFailed(): self
    {
        return new self('Failed to start session');
    }

    /**
     * Master key is missing from environment.
     */
    public static function masterKeyMissing(): self
    {
        return new self('PULSAR_MASTER_KEY environment variable is not set');
    }

    /**
     * Master key has invalid format.
     */
    public static function masterKeyInvalid(string $reason): self
    {
        return new self(sprintf('Invalid master key: %s', $reason));
    }

    /**
     * Encryption failed.
     */
    public static function encryptionFailed(string $reason): self
    {
        return new self(sprintf('Encryption failed: %s', $reason));
    }

    /**
     * Decryption failed (wrong key, tampered ciphertext, etc.).
     */
    public static function decryptionFailed(): self
    {
        return new self('Decryption failed: ciphertext is invalid or has been tampered with');
    }

    /**
     * Audit log integrity verification failed.
     */
    public static function auditIntegrityViolation(string $entryId): self
    {
        return new self(sprintf('Audit log integrity violation for entry "%s"', $entryId));
    }

    /**
     * Audit sink write failure.
     */
    public static function auditWriteFailed(string $reason): self
    {
        return new self(sprintf('Failed to write audit entry: %s', $reason));
    }
}
