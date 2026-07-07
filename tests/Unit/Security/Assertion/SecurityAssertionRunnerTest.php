<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Assertion;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\HstsConfig;
use Pulsar\Config\SessionConfig;
use Pulsar\Security\Assertion\SecurityAssertionRunner;
use Pulsar\Security\Assertion\SecurityViolationSeverity;
use Pulsar\Security\Exception\SecurityException;

use function array_map;
use function str_repeat;

final class SecurityAssertionRunnerTest extends TestCase
{
    /** A valid 64-hex-char (32-byte) master key. */
    private const string VALID_KEY_HEX = 'abababababababababababababababababababababababababababababababab';

    private function secureSession(bool $encryption = true): SessionConfig
    {
        return new SessionConfig(
            cookieName: 'sid',
            lifetime: 3600,
            cookieHttpOnly: true,
            cookieSecure: true,
            cookieSameSite: 'Strict',
            regenerateOnPrivilegeChange: true,
            encryption: $encryption,
        );
    }

    #[Test]
    public function assertAllPassesWithSecureConfig(): void
    {
        $runner = new SecurityAssertionRunner(
            debugMode: false,
            hstsEnabled: true,
            masterKeyHex: self::VALID_KEY_HEX,
            hstsConfig: new HstsConfig(enabled: true, maxAge: 63072000),
            sessionConfig: $this->secureSession(),
        );

        $runner->assertAll();
        self::assertSame([], $runner->check());
    }

    #[Test]
    public function assertAllThrowsOnDebugMode(): void
    {
        $runner = new SecurityAssertionRunner(
            debugMode: true,
            hstsEnabled: true,
            masterKeyHex: self::VALID_KEY_HEX,
            hstsConfig: new HstsConfig(enabled: true, maxAge: 63072000),
            sessionConfig: null,
        );

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('debug_mode');

        $runner->assertAll();
    }

    #[Test]
    public function assertAllThrowsOnNoHttps(): void
    {
        $runner = new SecurityAssertionRunner(
            debugMode: false,
            hstsEnabled: false,
            masterKeyHex: self::VALID_KEY_HEX,
            hstsConfig: new HstsConfig(enabled: true, maxAge: 63072000),
            sessionConfig: null,
        );

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('https_enforced');

        $runner->assertAll();
    }

    #[Test]
    public function assertAllThrowsOnHstsDisabled(): void
    {
        $runner = new SecurityAssertionRunner(
            debugMode: false,
            hstsEnabled: true,
            masterKeyHex: self::VALID_KEY_HEX,
            hstsConfig: new HstsConfig(enabled: false),
            sessionConfig: null,
        );

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('hsts_enabled');

        $runner->assertAll();
    }

    #[Test]
    public function assertAllThrowsOnLowHstsMaxAge(): void
    {
        $runner = new SecurityAssertionRunner(
            debugMode: false,
            hstsEnabled: true,
            masterKeyHex: self::VALID_KEY_HEX,
            hstsConfig: new HstsConfig(enabled: true, maxAge: 3600),
            sessionConfig: null,
        );

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('hsts_max_age');

        $runner->assertAll();
    }

    #[Test]
    public function assertAllThrowsOnMissingMasterKey(): void
    {
        // The key is absent from BOTH the OS env and the resolved value: the
        // runner must report it missing regardless of getenv() state.
        $runner = new SecurityAssertionRunner(
            debugMode: false,
            hstsEnabled: true,
            masterKeyHex: null,
            hstsConfig: new HstsConfig(enabled: true, maxAge: 63072000),
            sessionConfig: $this->secureSession(),
        );

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('master_key_present');

        $runner->assertAll();
    }

    #[Test]
    public function assertAllThrowsOnSessionEncryptionDisabled(): void
    {
        $runner = new SecurityAssertionRunner(
            debugMode: false,
            hstsEnabled: true,
            masterKeyHex: self::VALID_KEY_HEX,
            hstsConfig: new HstsConfig(enabled: true, maxAge: 63072000),
            sessionConfig: $this->secureSession(encryption: false),
        );

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('session_encryption');

        $runner->assertAll();
    }

    #[Test]
    public function checkReturnsViolationsWithoutThrowing(): void
    {
        $runner = new SecurityAssertionRunner(
            debugMode: true,
            hstsEnabled: false,
            masterKeyHex: null,
            hstsConfig: null,
            sessionConfig: null,
        );

        $violations = $runner->check();

        self::assertNotEmpty($violations);

        $assertions = array_map(static fn($v) => $v->assertion, $violations);
        self::assertContains('debug_mode', $assertions);
        self::assertContains('https_enforced', $assertions);
        self::assertContains('master_key_present', $assertions);
    }

    #[Test]
    public function checkReturnsCriticalSeverityForDebugMode(): void
    {
        $runner = new SecurityAssertionRunner(
            debugMode: true,
            hstsEnabled: true,
            masterKeyHex: self::VALID_KEY_HEX,
            hstsConfig: new HstsConfig(enabled: true, maxAge: 63072000),
            sessionConfig: null,
        );

        $violations = $runner->check();
        $debugViolation = null;

        foreach ($violations as $v) {
            if ($v->assertion === 'debug_mode') {
                $debugViolation = $v;

                break;
            }
        }

        self::assertNotNull($debugViolation);
        self::assertSame(SecurityViolationSeverity::Critical, $debugViolation->severity);
    }

    #[Test]
    public function checkReportsMissingMasterKeyWhenHexIsNull(): void
    {
        $runner = new SecurityAssertionRunner(
            debugMode: false,
            hstsEnabled: true,
            masterKeyHex: null,
            hstsConfig: new HstsConfig(enabled: true, maxAge: 63072000),
            sessionConfig: $this->secureSession(),
        );

        $assertions = array_map(static fn($v) => $v->assertion, $runner->check());
        self::assertContains('master_key_present', $assertions);
    }

    #[Test]
    public function checkAcceptsMasterKeyProvidedAsResolvedValue(): void
    {
        // Acceptance: a key supplied via the resolved param (as the wiring does
        // from the Environment repository / .env) is honored — no
        // master_key_present or master_key_strength violation — even though the
        // OS env (getenv) does not contain it.
        $runner = new SecurityAssertionRunner(
            debugMode: false,
            hstsEnabled: true,
            masterKeyHex: self::VALID_KEY_HEX,
            hstsConfig: new HstsConfig(enabled: true, maxAge: 63072000),
            sessionConfig: $this->secureSession(),
        );

        $assertions = array_map(static fn($v) => $v->assertion, $runner->check());
        self::assertNotContains('master_key_present', $assertions);
        self::assertNotContains('master_key_strength', $assertions);
    }

    #[Test]
    public function checkReportsWeakMasterKey(): void
    {
        $runner = new SecurityAssertionRunner(
            debugMode: false,
            hstsEnabled: true,
            masterKeyHex: str_repeat('ab', 8), // 16 hex chars — below the 64 floor
            hstsConfig: new HstsConfig(enabled: true, maxAge: 63072000),
            sessionConfig: $this->secureSession(),
        );

        $assertions = array_map(static fn($v) => $v->assertion, $runner->check());
        self::assertContains('master_key_strength', $assertions);
    }

    #[Test]
    public function checkReturnsEmptyWhenAllSecure(): void
    {
        $runner = new SecurityAssertionRunner(
            debugMode: false,
            hstsEnabled: true,
            masterKeyHex: self::VALID_KEY_HEX,
            hstsConfig: new HstsConfig(enabled: true, maxAge: 63072000),
            sessionConfig: $this->secureSession(),
        );

        self::assertSame([], $runner->check());
    }
}
