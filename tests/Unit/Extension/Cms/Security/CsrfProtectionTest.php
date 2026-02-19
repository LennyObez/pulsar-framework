<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Auth\TwoFactor\RecoveryCodeGenerator;
use Pulsar\Auth\TwoFactor\TotpGenerator;
use Pulsar\Auth\TwoFactor\TotpVerifier;
use Pulsar\Extension\Cms\Http\Controller\Admin\TwoFactorController;
use Pulsar\Extension\Cms\Internal\Security\QrCodeEncoder;
use RuntimeException;

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

        $this->controller = new TwoFactorController(
            $generator,
            $verifier,
            $recoveryGenerator,
            $qrEncoder,
            $gate,
            null,
        );
    }

    // -- POST without step-up → rejected -----------------------------------

    #[Test]
    public function test_post_without_step_up_throws_exception(): void
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

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Step-up authentication required');

        $this->controller->enroll($request);
    }

    #[Test]
    public function test_confirm_without_step_up_throws_exception(): void
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

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Step-up authentication required');

        $this->controller->confirm($request);
    }

    #[Test]
    public function test_disable_without_step_up_throws_exception(): void
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

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Step-up authentication required');

        $this->controller->disable($request);
    }

    // -- Unauthenticated requests → rejected --------------------------------

    #[Test]
    public function test_unauthenticated_request_throws_exception(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([]);
        $request->method('getAttribute')
            ->willReturnCallback(static fn(string $name) => match ($name) {
                'identity' => null,
                default => null,
            });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Authentication required');

        $this->controller->enroll($request);
    }

    #[Test]
    public function test_non_authenticated_identity_throws_exception(): void
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

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Authentication required');

        $this->controller->enroll($request);
    }
}
