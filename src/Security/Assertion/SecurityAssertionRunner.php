<?php

declare(strict_types=1);

namespace Pulsar\Security\Assertion;

use Pulsar\Api\Api;
use Pulsar\Config\HstsConfig;
use Pulsar\Config\SessionConfig;
use Pulsar\Security\Exception\SecurityException;

use function getenv;
use function sprintf;
use function strlen;

/**
 * Runtime security assertions that verify the application's security posture.
 *
 * Run at kernel boot in production mode. Each assertion checks a specific
 * security requirement and throws SecurityException on violation.
 *
 * Assertions:
 * - Debug mode off in production
 * - HTTPS enforced
 * - HSTS enabled with sufficient max-age
 * - Master key present and sufficient length
 * - Session encryption enabled
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SecurityAssertionRunner
{
    /**
     * Minimum acceptable HSTS max-age (1 year).
     */
    private const int MIN_HSTS_MAX_AGE = 31_536_000;

    /**
     * Minimum master key hex length (32 bytes = 64 hex chars).
     */
    private const int MIN_MASTER_KEY_HEX_LENGTH = 64;

    public function __construct(
        private bool $debugMode,
        private bool $httpsEnforced,
        private ?HstsConfig $hstsConfig,
        private ?SessionConfig $sessionConfig,
    ) {}

    /**
     * Run all security assertions.
     *
     * @throws SecurityException If any assertion fails
     */
    public function assertAll(): void
    {
        $this->assertDebugOff();
        $this->assertHttpsEnforced();
        $this->assertHstsEnabled();
        $this->assertMasterKeyStrength();
        $this->assertSessionEncryption();
    }

    /**
     * Run assertions and collect violations instead of throwing.
     *
     * @return list<SecurityViolation>
     */
    public function check(): array
    {
        $violations = [];

        if ($this->debugMode) {
            $violations[] = new SecurityViolation(
                'debug_mode',
                'Debug mode is enabled in production',
                SecurityViolationSeverity::Critical,
            );
        }

        if (!$this->httpsEnforced) {
            $violations[] = new SecurityViolation(
                'https_enforced',
                'HTTPS is not enforced',
                SecurityViolationSeverity::Critical,
            );
        }

        if ($this->hstsConfig === null || !$this->hstsConfig->enabled) {
            $violations[] = new SecurityViolation(
                'hsts_enabled',
                'HSTS is not enabled',
                SecurityViolationSeverity::High,
            );
        } elseif ($this->hstsConfig->maxAge < self::MIN_HSTS_MAX_AGE) {
            $violations[] = new SecurityViolation(
                'hsts_max_age',
                sprintf('HSTS max-age is %d, minimum recommended is %d (1 year)', $this->hstsConfig->maxAge, self::MIN_HSTS_MAX_AGE),
                SecurityViolationSeverity::Medium,
            );
        }

        $masterKey = getenv('PULSAR_MASTER_KEY');
        if ($masterKey === false || $masterKey === '') {
            $violations[] = new SecurityViolation(
                'master_key_present',
                'PULSAR_MASTER_KEY environment variable is not set',
                SecurityViolationSeverity::Critical,
            );
        } elseif (strlen($masterKey) < self::MIN_MASTER_KEY_HEX_LENGTH) {
            $violations[] = new SecurityViolation(
                'master_key_strength',
                sprintf('Master key is %d hex chars, minimum is %d (32 bytes)', strlen($masterKey), self::MIN_MASTER_KEY_HEX_LENGTH),
                SecurityViolationSeverity::Critical,
            );
        }

        if ($this->sessionConfig !== null && !$this->sessionConfig->encryption) {
            $violations[] = new SecurityViolation(
                'session_encryption',
                'Session encryption is disabled',
                SecurityViolationSeverity::High,
            );
        }

        return $violations;
    }

    /**
     * @throws SecurityException
     */
    private function assertDebugOff(): void
    {
        if ($this->debugMode) {
            throw SecurityException::assertionFailed(
                'debug_mode',
                'Debug mode must be disabled in production',
            );
        }
    }

    /**
     * @throws SecurityException
     */
    private function assertHttpsEnforced(): void
    {
        if (!$this->httpsEnforced) {
            throw SecurityException::assertionFailed(
                'https_enforced',
                'HTTPS must be enforced in production',
            );
        }
    }

    /**
     * @throws SecurityException
     */
    private function assertHstsEnabled(): void
    {
        if ($this->hstsConfig === null || !$this->hstsConfig->enabled) {
            throw SecurityException::assertionFailed(
                'hsts_enabled',
                'HSTS must be enabled in production',
            );
        }

        if ($this->hstsConfig->maxAge < self::MIN_HSTS_MAX_AGE) {
            throw SecurityException::assertionFailed(
                'hsts_max_age',
                sprintf('HSTS max-age is %d, minimum is %d (1 year)', $this->hstsConfig->maxAge, self::MIN_HSTS_MAX_AGE),
            );
        }
    }

    /**
     * @throws SecurityException
     */
    private function assertMasterKeyStrength(): void
    {
        $masterKey = getenv('PULSAR_MASTER_KEY');

        if ($masterKey === false || $masterKey === '') {
            throw SecurityException::assertionFailed(
                'master_key_present',
                'PULSAR_MASTER_KEY environment variable is not set',
            );
        }

        if (strlen($masterKey) < self::MIN_MASTER_KEY_HEX_LENGTH) {
            throw SecurityException::assertionFailed(
                'master_key_strength',
                sprintf('Master key is %d hex chars, minimum is %d (32 bytes)', strlen($masterKey), self::MIN_MASTER_KEY_HEX_LENGTH),
            );
        }
    }

    /**
     * @throws SecurityException
     */
    private function assertSessionEncryption(): void
    {
        if ($this->sessionConfig !== null && !$this->sessionConfig->encryption) {
            throw SecurityException::assertionFailed(
                'session_encryption',
                'Session encryption must be enabled in production',
            );
        }
    }
}
