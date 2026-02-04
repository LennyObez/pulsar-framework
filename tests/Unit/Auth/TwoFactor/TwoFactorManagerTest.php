<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\TwoFactor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Auth\TwoFactor\RecoveryCodeGenerator;
use Pulsar\Auth\TwoFactor\RecoveryCodeVerifier;
use Pulsar\Auth\TwoFactor\TotpGenerator;
use Pulsar\Auth\TwoFactor\TotpVerifier;
use Pulsar\Auth\TwoFactor\TwoFactorManager;

#[CoversClass(TwoFactorManager::class)]
final class TwoFactorManagerTest extends TestCase
{
    private TwoFactorManager $manager;

    private TotpGenerator $generator;

    private TotpVerifier $verifier;

    private RecoveryCodeGenerator $recoveryCodeGenerator;

    private RecoveryCodeVerifier $recoveryCodeVerifier;

    protected function setUp(): void
    {
        $this->generator = new TotpGenerator();
        $this->verifier = new TotpVerifier($this->generator);
        $this->recoveryCodeGenerator = new RecoveryCodeGenerator();
        $this->recoveryCodeVerifier = new RecoveryCodeVerifier();

        $this->manager = new TwoFactorManager(
            generator: $this->generator,
            verifier: $this->verifier,
            recoveryCodeGenerator: $this->recoveryCodeGenerator,
            recoveryCodeVerifier: $this->recoveryCodeVerifier,
            issuer: 'TestApp',
            recoveryCodeCount: 8,
        );
    }

    #[Test]
    public function beginSetupReturnsArrayWithRequiredKeys(): void
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('displayName')->willReturn('test@example.com');

        $result = $this->manager->beginSetup($identity);

        self::assertArrayHasKey('secret', $result);
        self::assertArrayHasKey('secret_base32', $result);
        self::assertArrayHasKey('provisioning_uri', $result);
        self::assertArrayHasKey('recovery_codes', $result);

        self::assertIsString($result['secret']);
        self::assertNotEmpty($result['secret']);

        self::assertIsString($result['secret_base32']);
        self::assertNotEmpty($result['secret_base32']);
        self::assertMatchesRegularExpression('/^[A-Z2-7]+$/', $result['secret_base32']);

        self::assertStringStartsWith('otpauth://totp/', $result['provisioning_uri']);

        self::assertIsArray($result['recovery_codes']);
        self::assertCount(8, $result['recovery_codes']);
    }

    #[Test]
    public function confirmSetupDelegatesToVerifier(): void
    {
        // Generate a secret and compute a code at the current time so the real
        // verifier (which defaults to time()) will accept it within its window.
        $secret = $this->generator->generateSecret();
        $code = $this->generator->computeCode($secret);

        // confirmSetup delegates to TotpVerifier::verify which should accept
        // a code generated for the current time step.
        $result = $this->manager->confirmSetup($secret, $code);

        self::assertTrue($result);
    }

    #[Test]
    public function verifyCodeDelegatesToVerifier(): void
    {
        // Generate a secret and compute a code at the current time so the real
        // verifier will accept it.
        $secret = $this->generator->generateSecret();
        $code = $this->generator->computeCode($secret);

        $result = $this->manager->verifyCode($secret, $code);

        self::assertTrue($result);

        // A wrong code should return false
        self::assertFalse($this->manager->verifyCode($secret, '000000'));
    }

    #[Test]
    public function verifyRecoveryCodeDelegatesToRecoveryCodeVerifier(): void
    {
        $validCodes = ['ABCD-1234', 'EF56-7890'];

        // Matching code should return its index
        self::assertSame(0, $this->manager->verifyRecoveryCode('ABCD-1234', $validCodes));
        self::assertSame(1, $this->manager->verifyRecoveryCode('EF56-7890', $validCodes));

        // Non-matching code should return -1
        self::assertSame(-1, $this->manager->verifyRecoveryCode('FFFF-FFFF', $validCodes));
    }
}
