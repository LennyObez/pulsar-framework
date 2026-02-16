<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Auth\TwoFactor\RecoveryCodeGenerator;
use Pulsar\Auth\TwoFactor\TotpGenerator;
use Pulsar\Auth\TwoFactor\TotpVerifier;
use Pulsar\Cache\Application\Lock\LockHandle;
use Pulsar\Cache\Application\Lock\LockInterface;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Extension\Cms\Http\Controller\Admin\TwoFactorController;
use Pulsar\Extension\Cms\Internal\Security\CmsRateLimiter;
use Pulsar\Extension\Cms\Security\QrCodeEncoder;

/**
 * Security tests verifying that CSRF-like step-up protections work.
 *
 * The CMS admin controllers require step-up authentication (a secondary
 * verification) for sensitive POST operations. These tests verify that
 * requests without step-up are rejected.
 */
#[CoversClass(TwoFactorController::class)]
final class CsrfProtectionTest extends TestCase
{
    private TwoFactorController $controller;

    protected function setUp(): void
    {
        $generator = new TotpGenerator();
        $verifier = new TotpVerifier($generator);
        $recoveryGenerator = new RecoveryCodeGenerator();
        $qrEncoder = new QrCodeEncoder();

        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(false);

        $lockStub = $this->createStub(LockInterface::class);
        $lockStub->method('acquire')->willReturn(new LockHandle('r', 't', 1.0, 60));
        $lockStub->method('release')->willReturn(true);
        $rateLimiter = new CmsRateLimiter($this->createStub(TaggedCacheInterface::class), $lockStub);

        $this->controller = new TwoFactorController(
            $generator,
            $verifier,
            $recoveryGenerator,
            $qrEncoder,
            $rateLimiter,
            null,
            $gate,
        );
    }

    // -- POST without step-up → rejected -----------------------------------

    #[Test]
    public function postWithoutStepUpThrowsException(): void
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn('user-1');
        $identity->method('isAuthenticated')->willReturn(true);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([]);
        $request->method('getAttribute')
            ->willReturnCallback(static fn(string $name) => match ($name) {
                'identity' => $identity,
                'step_up_verified' => false,
                default => null,
            });

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Step-up authentication is required');

        $this->controller->enroll($request);
    }

    #[Test]
    public function confirmWithoutStepUpThrowsException(): void
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn('user-1');
        $identity->method('isAuthenticated')->willReturn(true);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['code' => '123456', 'secret' => 'ABC']);
        $request->method('getAttribute')
            ->willReturnCallback(static fn(string $name) => match ($name) {
                'identity' => $identity,
                'step_up_verified' => false,
                default => null,
            });

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Step-up authentication is required');

        $this->controller->confirm($request);
    }

    #[Test]
    public function disableWithoutStepUpThrowsException(): void
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn('user-1');
        $identity->method('isAuthenticated')->willReturn(true);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['reason' => 'Testing disable flow']);
        $request->method('getAttribute')
            ->willReturnCallback(static fn(string $name) => match ($name) {
                'identity' => $identity,
                'step_up_verified' => false,
                default => null,
            });

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Step-up authentication is required');

        $this->controller->disable($request);
    }

    // -- Unauthenticated requests → rejected --------------------------------

    #[Test]
    public function unauthenticatedRequestThrowsException(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([]);
        $request->method('getAttribute')
            ->willReturnCallback(static fn(string $name) => match ($name) {
                'identity' => null,
                default => null,
            });

        $this->expectException(AuthenticationException::class);

        $this->controller->enroll($request);
    }

    #[Test]
    public function nonAuthenticatedIdentityThrowsException(): void
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn('anon');
        $identity->method('isAuthenticated')->willReturn(false);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([]);
        $request->method('getAttribute')
            ->willReturnCallback(static fn(string $name) => match ($name) {
                'identity' => $identity,
                default => null,
            });

        $this->expectException(AuthenticationException::class);

        $this->controller->enroll($request);
    }
}
