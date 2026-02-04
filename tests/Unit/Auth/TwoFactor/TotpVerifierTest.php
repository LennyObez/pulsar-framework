<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\TwoFactor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\TwoFactor\InMemoryTotpReplayGuard;
use Pulsar\Auth\TwoFactor\TotpGenerator;
use Pulsar\Auth\TwoFactor\TotpVerifier;
use Pulsar\Auth\TwoFactor\TwoFactorPurpose;

#[CoversClass(TotpVerifier::class)]
final class TotpVerifierTest extends TestCase
{
    private TotpGenerator $generator;

    private TotpVerifier $verifier;

    protected function setUp(): void
    {
        $this->generator = new TotpGenerator();
        $this->verifier = new TotpVerifier($this->generator, window: 1);
    }

    #[Test]
    public function verifyReturnsTrueForCorrectCodeAtCurrentTime(): void
    {
        $secret = $this->generator->generateSecret();
        $timestamp = 1000000;

        $code = $this->generator->computeCode($secret, $timestamp);

        self::assertNotNull($this->verifier->verify($secret, $code, $timestamp));
    }

    #[Test]
    public function verifyReturnsFalseForWrongCode(): void
    {
        $secret = $this->generator->generateSecret();
        $timestamp = 1000000;

        self::assertNull($this->verifier->verify($secret, '000000', $timestamp));
    }

    #[Test]
    public function verifyAcceptsCodesWithinTheTimeWindow(): void
    {
        $secret = $this->generator->generateSecret();
        $timestamp = 1000000;

        // Code generated for one period ahead (window allows +1)
        $futureCode = $this->generator->computeCode($secret, $timestamp + 30);
        self::assertNotNull($this->verifier->verify($secret, $futureCode, $timestamp));

        // Code generated for one period behind (window allows -1)
        $pastCode = $this->generator->computeCode($secret, $timestamp - 30);
        self::assertNotNull($this->verifier->verify($secret, $pastCode, $timestamp));

        // Code generated for two periods ahead (outside window of 1)
        $farFutureCode = $this->generator->computeCode($secret, $timestamp + 60);
        self::assertNull($this->verifier->verify($secret, $farFutureCode, $timestamp));
    }

    #[Test]
    public function verifyReturnsAcceptedTimeStep(): void
    {
        $secret = $this->generator->generateSecret();
        $timestamp = 1000000;
        $code = $this->generator->computeCode($secret, $timestamp);

        $timeStep = $this->verifier->verify($secret, $code, $timestamp);

        self::assertNotNull($timeStep);
        self::assertSame(intdiv($timestamp, 30), $timeStep);
    }

    #[Test]
    public function verifyWithReplayGuardRejectsSecondUse(): void
    {
        $secret = $this->generator->generateSecret();
        $timestamp = 1000000;
        $code = $this->generator->computeCode($secret, $timestamp);
        $guard = new InMemoryTotpReplayGuard();

        self::assertNotNull($this->verifier->verify($secret, $code, $timestamp, $guard, 'user-1'));
        self::assertNull($this->verifier->verify($secret, $code, $timestamp, $guard, 'user-1'));
    }

    #[Test]
    public function verifyWithReplayGuardAllowsDifferentIdentities(): void
    {
        $secret = $this->generator->generateSecret();
        $timestamp = 1000000;
        $code = $this->generator->computeCode($secret, $timestamp);
        $guard = new InMemoryTotpReplayGuard();

        self::assertNotNull($this->verifier->verify($secret, $code, $timestamp, $guard, 'user-1'));
        self::assertNotNull($this->verifier->verify($secret, $code, $timestamp, $guard, 'user-2'));
    }

    #[Test]
    public function verifyWithReplayGuardAllowsDifferentPurposes(): void
    {
        $secret = $this->generator->generateSecret();
        $timestamp = 1000000;
        $code = $this->generator->computeCode($secret, $timestamp);
        $guard = new InMemoryTotpReplayGuard();

        self::assertNotNull($this->verifier->verify($secret, $code, $timestamp, $guard, 'user-1', TwoFactorPurpose::Login));
        self::assertNotNull($this->verifier->verify($secret, $code, $timestamp, $guard, 'user-1', TwoFactorPurpose::StepUp));
    }

    #[Test]
    public function verifyWithoutReplayGuardAllowsReuse(): void
    {
        $secret = $this->generator->generateSecret();
        $timestamp = 1000000;
        $code = $this->generator->computeCode($secret, $timestamp);

        self::assertNotNull($this->verifier->verify($secret, $code, $timestamp));
        self::assertNotNull($this->verifier->verify($secret, $code, $timestamp));
    }

    #[Test]
    public function verifyUsesGeneratorPeriodInsteadOfHardcoded30(): void
    {
        $generator60 = new TotpGenerator(period: 60);
        $verifier60 = new TotpVerifier($generator60, window: 1);

        $secret = $generator60->generateSecret();
        $timestamp = 1000000;

        // Code at one period ahead (60 seconds) should be accepted within window=1
        $futureCode = $generator60->computeCode($secret, $timestamp + 60);
        self::assertNotNull($verifier60->verify($secret, $futureCode, $timestamp));

        // Code at two periods ahead (120 seconds) should be rejected for window=1
        $farFutureCode = $generator60->computeCode($secret, $timestamp + 120);
        self::assertNull($verifier60->verify($secret, $farFutureCode, $timestamp));

        // Verify that a code at a timestamp within the same 60s time step produces the same code.
        // Use a timestamp at the start of a 60s boundary to ensure $timestamp and $timestamp+29
        // both fall in the same time step.
        $alignedTimestamp = 1000020; // intdiv(1000020, 60) = 16667
        $sameStepCode1 = $generator60->computeCode($secret, $alignedTimestamp);
        $sameStepCode2 = $generator60->computeCode($secret, $alignedTimestamp + 29);
        self::assertSame($sameStepCode1, $sameStepCode2, 'Same 60s time step should produce same code');
    }
}
