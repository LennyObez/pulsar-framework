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

final class SecurityAssertionRunnerTest extends TestCase
{
    #[Test]
    public function assertAllPassesWithSecureConfig(): void
    {
        // Set master key env for the test
        $originalKey = getenv('PULSAR_MASTER_KEY');
        putenv('PULSAR_MASTER_KEY=' . str_repeat('ab', 32));

        try {
            $runner = new SecurityAssertionRunner(
                debugMode: false,
                hstsEnabled: true,
                hstsConfig: new HstsConfig(enabled: true, maxAge: 63072000),
                sessionConfig: new SessionConfig(
                    cookieName: 'sid',
                    lifetime: 3600,
                    cookieHttpOnly: true,
                    cookieSecure: true,
                    cookieSameSite: 'Strict',
                    regenerateOnPrivilegeChange: true,
                    encryption: true,
                ),
            );

            // assertAll() should not throw; also verify check() returns no violations
            $runner->assertAll();
            self::assertSame([], $runner->check());
        } finally {
            if ($originalKey === false) {
                putenv('PULSAR_MASTER_KEY');
            } else {
                putenv('PULSAR_MASTER_KEY=' . $originalKey);
            }
        }
    }

    #[Test]
    public function assertAllThrowsOnDebugMode(): void
    {
        $runner = new SecurityAssertionRunner(
            debugMode: true,
            hstsEnabled: true,
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
            hstsConfig: new HstsConfig(enabled: true, maxAge: 3600),
            sessionConfig: null,
        );

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('hsts_max_age');

        $runner->assertAll();
    }

    #[Test]
    public function assertAllThrowsOnSessionEncryptionDisabled(): void
    {
        $originalKey = getenv('PULSAR_MASTER_KEY');
        putenv('PULSAR_MASTER_KEY=' . str_repeat('ab', 32));

        try {
            $runner = new SecurityAssertionRunner(
                debugMode: false,
                hstsEnabled: true,
                hstsConfig: new HstsConfig(enabled: true, maxAge: 63072000),
                sessionConfig: new SessionConfig(
                    cookieName: 'sid',
                    lifetime: 3600,
                    cookieHttpOnly: true,
                    cookieSecure: true,
                    cookieSameSite: 'Strict',
                    regenerateOnPrivilegeChange: true,
                    encryption: false,
                ),
            );

            $this->expectException(SecurityException::class);
            $this->expectExceptionMessage('session_encryption');

            $runner->assertAll();
        } finally {
            if ($originalKey === false) {
                putenv('PULSAR_MASTER_KEY');
            } else {
                putenv('PULSAR_MASTER_KEY=' . $originalKey);
            }
        }
    }

    #[Test]
    public function checkReturnsViolationsWithoutThrowing(): void
    {
        $runner = new SecurityAssertionRunner(
            debugMode: true,
            hstsEnabled: false,
            hstsConfig: null,
            sessionConfig: null,
        );

        $violations = $runner->check();

        // At minimum: debug + https + hsts + master key
        self::assertNotEmpty($violations);

        $assertions = array_map(static fn($v) => $v->assertion, $violations);
        self::assertContains('debug_mode', $assertions);
        self::assertContains('https_enforced', $assertions);
    }

    #[Test]
    public function checkReturnsCriticalSeverityForDebugMode(): void
    {
        $runner = new SecurityAssertionRunner(
            debugMode: true,
            hstsEnabled: true,
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
    public function checkReturnsEmptyWhenAllSecure(): void
    {
        $originalKey = getenv('PULSAR_MASTER_KEY');
        putenv('PULSAR_MASTER_KEY=' . str_repeat('ab', 32));

        try {
            $runner = new SecurityAssertionRunner(
                debugMode: false,
                hstsEnabled: true,
                hstsConfig: new HstsConfig(enabled: true, maxAge: 63072000),
                sessionConfig: new SessionConfig(
                    cookieName: 'sid',
                    lifetime: 3600,
                    cookieHttpOnly: true,
                    cookieSecure: true,
                    cookieSameSite: 'Strict',
                    regenerateOnPrivilegeChange: true,
                    encryption: true,
                ),
            );

            $violations = $runner->check();

            self::assertSame([], $violations);
        } finally {
            if ($originalKey === false) {
                putenv('PULSAR_MASTER_KEY');
            } else {
                putenv('PULSAR_MASTER_KEY=' . $originalKey);
            }
        }
    }
}
