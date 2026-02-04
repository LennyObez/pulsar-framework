<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\AuthManagerInterface;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Auth\Middleware\TwoFactorMiddleware;
use Pulsar\Auth\SecurityContext;
use Pulsar\Auth\TwoFactor\RecoveryCodeGenerator;
use Pulsar\Auth\TwoFactor\RecoveryCodeVerifier;
use Pulsar\Auth\TwoFactor\TotpGenerator;
use Pulsar\Auth\TwoFactor\TotpVerifier;
use Pulsar\Auth\TwoFactor\TwoFactorManager;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;

#[CoversClass(TwoFactorManager::class)]
#[CoversClass(TotpGenerator::class)]
#[CoversClass(TotpVerifier::class)]
#[CoversClass(RecoveryCodeGenerator::class)]
#[CoversClass(RecoveryCodeVerifier::class)]
#[CoversClass(TwoFactorMiddleware::class)]
final class TwoFactorFlowTest extends TestCase
{
    private TwoFactorManager $manager;
    private TotpGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new TotpGenerator();
        $verifier = new TotpVerifier($this->generator);
        $recoveryGenerator = new RecoveryCodeGenerator();
        $recoveryVerifier = new RecoveryCodeVerifier();

        $this->manager = new TwoFactorManager(
            generator: $this->generator,
            verifier: $verifier,
            recoveryCodeGenerator: $recoveryGenerator,
            recoveryCodeVerifier: $recoveryVerifier,
            issuer: 'PulsarTest',
            recoveryCodeCount: 8,
        );
    }

    #[Test]
    public function fullTwoFactorSetupAndVerificationFlow(): void
    {
        $identity = new Identity(
            id: 'user-1',
            displayName: 'test@example.com',
            roles: ['user'],
        );

        // Step 1: Begin setup
        $setup = $this->manager->beginSetup($identity);

        self::assertNotEmpty($setup['secret']);
        self::assertNotEmpty($setup['secret_base32']);
        self::assertStringContainsString('otpauth://totp/', $setup['provisioning_uri']);
        self::assertStringContainsString('PulsarTest', $setup['provisioning_uri']);
        self::assertCount(8, $setup['recovery_codes']);

        // Step 2: Confirm setup with a valid TOTP code
        $timestamp = time();
        $code = $this->generator->computeCode($setup['secret'], $timestamp);
        self::assertTrue($this->manager->confirmSetup($setup['secret'], $code));

        // Step 3: Verify code during login
        $loginCode = $this->generator->computeCode($setup['secret'], $timestamp);
        self::assertTrue($this->manager->verifyCode($setup['secret'], $loginCode));

        // Step 4: Use recovery code as fallback
        $recoveryIndex = $this->manager->verifyRecoveryCode(
            $setup['recovery_codes'][0],
            $setup['recovery_codes'],
        );
        self::assertSame(0, $recoveryIndex);

        // Wrong recovery code fails
        $failedIndex = $this->manager->verifyRecoveryCode(
            'ZZZZ-ZZZZ',
            $setup['recovery_codes'],
        );
        self::assertSame(-1, $failedIndex);
    }

    #[Test]
    public function twoFactorMiddlewareBlocksPendingStatus(): void
    {
        $pendingIdentity = new Identity(
            id: 'user-1',
            displayName: 'Pending User',
            roles: ['user'],
            twoFactorStatus: TwoFactorStatus::Pending,
        );

        $authManager = $this->createStub(AuthManagerInterface::class);
        $authManager->method('authenticate')->willReturn($pendingIdentity);

        $request = new Request(
            method: Method::GET,
            uri: '/dashboard',
            path: '/dashboard',
            queryString: '',
            headers: new HeaderBag([]),
            body: '',
        );

        $securityContext = new SecurityContext($authManager, $request);
        $request = $request->withAttribute('_security_context', $securityContext);

        $middleware = new TwoFactorMiddleware();
        $handler = fn(Request $req): Response => new Response(body: 'OK', status: ResponseStatus::OK);

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Forbidden, $response->status);
    }

    #[Test]
    public function twoFactorMiddlewareAllowsVerifiedStatus(): void
    {
        $verifiedIdentity = new Identity(
            id: 'user-1',
            displayName: 'Verified User',
            roles: ['user'],
            twoFactorStatus: TwoFactorStatus::Verified,
        );

        $authManager = $this->createStub(AuthManagerInterface::class);
        $authManager->method('authenticate')->willReturn($verifiedIdentity);

        $request = new Request(
            method: Method::GET,
            uri: '/dashboard',
            path: '/dashboard',
            queryString: '',
            headers: new HeaderBag([]),
            body: '',
        );

        $securityContext = new SecurityContext($authManager, $request);
        $request = $request->withAttribute('_security_context', $securityContext);

        $middleware = new TwoFactorMiddleware();
        $handler = fn(Request $req): Response => new Response(body: 'OK', status: ResponseStatus::OK);

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::OK, $response->status);
    }

    #[Test]
    public function identityStatusTransitionsDuringTwoFactorFlow(): void
    {
        // User logs in — identity starts with Pending status
        $pendingIdentity = new Identity(
            id: 'user-1',
            displayName: 'User',
            roles: ['user'],
            twoFactorStatus: TwoFactorStatus::Pending,
        );

        self::assertSame(TwoFactorStatus::Pending, $pendingIdentity->twoFactorStatus());
        self::assertTrue($pendingIdentity->isAuthenticated());

        // After 2FA verification, identity transitions to Verified
        $verifiedIdentity = $pendingIdentity->withTwoFactorStatus(TwoFactorStatus::Verified);

        self::assertSame(TwoFactorStatus::Verified, $verifiedIdentity->twoFactorStatus());
        self::assertTrue($verifiedIdentity->isAuthenticated());

        // Original identity is unchanged (immutable)
        self::assertSame(TwoFactorStatus::Pending, $pendingIdentity->twoFactorStatus());
    }
}
