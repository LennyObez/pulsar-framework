<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Auth\Security\AccountTakeoverGuard;
use Pulsar\Auth\Security\SensitiveOperation;
use Pulsar\Auth\Security\TakeoverRisk;
use Pulsar\Auth\Security\TakeoverRiskLevel;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\TrustedProxy;
use Pulsar\Security\Session\SessionMetadata;

use function time;

#[CoversClass(AccountTakeoverGuard::class)]
#[CoversClass(TakeoverRisk::class)]
final class AccountTakeoverGuardTest extends TestCase
{
    private function createMeta(string $ip = '10.0.0.1', string $ua = 'Mozilla/5.0'): SessionMetadata
    {
        return new SessionMetadata(
            createdAt: time(),
            lastActivity: time(),
            ipAddress: $ip,
            userAgent: $ua,
            userId: 'user-1',
        );
    }

    private function createRequest(string $ip = '10.0.0.1', string $ua = 'Mozilla/5.0'): ServerRequest
    {
        return new ServerRequest(
            method: 'POST',
            uri: '/account/change-password',
            headers: ['User-Agent' => $ua],
            serverParams: ['REMOTE_ADDR' => $ip],
        );
    }

    #[Test]
    public function requiresReauthWhenNeverAuthenticated(): void
    {
        $guard = new AccountTakeoverGuard(new NullLogger());

        self::assertTrue($guard->requiresReauthentication(
            SensitiveOperation::PasswordChange,
            null,
        ));
    }

    #[Test]
    public function requiresReauthWhenWindowExpired(): void
    {
        $guard = new AccountTakeoverGuard(new NullLogger(), reauthWindowSeconds: 300);

        self::assertTrue($guard->requiresReauthentication(
            SensitiveOperation::PasswordChange,
            time() - 600, // 10 minutes ago
        ));
    }

    #[Test]
    public function doesNotRequireReauthWithinWindow(): void
    {
        $guard = new AccountTakeoverGuard(new NullLogger(), reauthWindowSeconds: 300);

        self::assertFalse($guard->requiresReauthentication(
            SensitiveOperation::PasswordChange,
            time() - 60, // 1 minute ago
        ));
    }

    #[Test]
    public function doesNotRequireReauthForNonConfiguredOperations(): void
    {
        $guard = new AccountTakeoverGuard(
            new NullLogger(),
            requiresReauth: [SensitiveOperation::PasswordChange],
        );

        self::assertFalse($guard->requiresReauthentication(
            SensitiveOperation::ApiKeyCreate,
            null,
        ));
    }

    #[Test]
    public function evaluateLowRiskWhenNothingChanged(): void
    {
        $guard = new AccountTakeoverGuard(new NullLogger());
        $risk = $guard->evaluate(
            SensitiveOperation::PasswordChange,
            $this->createRequest('10.0.0.1', 'Mozilla/5.0'),
            $this->createMeta('10.0.0.1', 'Mozilla/5.0'),
        );

        self::assertTrue($risk->isLow());
        self::assertSame(TakeoverRiskLevel::Low, $risk->level);
    }

    #[Test]
    public function trustedProxyResolvesForwardedClientSoNoFalseElevation(): void
    {
        $guard = new AccountTakeoverGuard(new NullLogger(), trustedProxy: new TrustedProxy(['10.0.0.0/8']));

        // Same real client 203.0.113.5 arriving via the trusted proxy 10.0.0.1.
        $request = new ServerRequest(
            method: 'POST',
            uri: '/account',
            headers: ['User-Agent' => 'Mozilla/5.0', 'X-Forwarded-For' => '203.0.113.5'],
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
        );

        $risk = $guard->evaluate(SensitiveOperation::PasswordChange, $request, $this->createMeta('203.0.113.5', 'Mozilla/5.0'));

        self::assertTrue($risk->isLow());
    }

    #[Test]
    public function trustedProxyStillElevatesOnRealClientIpChange(): void
    {
        $guard = new AccountTakeoverGuard(new NullLogger(), trustedProxy: new TrustedProxy(['10.0.0.0/8']));

        // A different real client behind the same proxy — raw REMOTE_ADDR would
        // miss this (proxy IP is constant); resolution catches it.
        $request = new ServerRequest(
            method: 'POST',
            uri: '/account',
            headers: ['User-Agent' => 'Mozilla/5.0', 'X-Forwarded-For' => '198.51.100.9'],
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
        );

        $risk = $guard->evaluate(SensitiveOperation::PasswordChange, $request, $this->createMeta('203.0.113.5', 'Mozilla/5.0'));

        self::assertSame(TakeoverRiskLevel::Elevated, $risk->level);
    }

    #[Test]
    public function evaluateElevatedRiskWhenIpChanged(): void
    {
        $guard = new AccountTakeoverGuard(new NullLogger());
        $risk = $guard->evaluate(
            SensitiveOperation::EmailChange,
            $this->createRequest('192.168.1.1', 'Mozilla/5.0'),
            $this->createMeta('10.0.0.1', 'Mozilla/5.0'),
        );

        self::assertSame(TakeoverRiskLevel::Elevated, $risk->level);
        self::assertTrue($risk->isElevatedOrHigher());
    }

    #[Test]
    public function evaluateHighRiskWhenIpAndDeviceChanged(): void
    {
        $guard = new AccountTakeoverGuard(new NullLogger());
        $risk = $guard->evaluate(
            SensitiveOperation::MfaDisable,
            $this->createRequest('192.168.1.1', 'curl/7.68.0'),
            $this->createMeta('10.0.0.1', 'Mozilla/5.0'),
        );

        self::assertSame(TakeoverRiskLevel::High, $risk->level);
        self::assertTrue($risk->isElevatedOrHigher());
        self::assertStringContainsString('mfa_disable', $risk->reason);
    }

    #[Test]
    public function evaluateLowRiskWhenSessionIpIsEmpty(): void
    {
        $guard = new AccountTakeoverGuard(new NullLogger());
        $risk = $guard->evaluate(
            SensitiveOperation::PasswordChange,
            $this->createRequest('192.168.1.1', 'Mozilla/5.0'),
            $this->createMeta('', 'Mozilla/5.0'),
        );

        self::assertTrue($risk->isLow());
    }
}
