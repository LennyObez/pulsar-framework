<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\TwoFactor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\TwoFactor\TotpGenerator;
use Pulsar\Auth\TwoFactor\TotpVerifier;

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

        self::assertTrue($this->verifier->verify($secret, $code, $timestamp));
    }

    #[Test]
    public function verifyReturnsFalseForWrongCode(): void
    {
        $secret = $this->generator->generateSecret();
        $timestamp = 1000000;

        self::assertFalse($this->verifier->verify($secret, '000000', $timestamp));
    }

    #[Test]
    public function verifyAcceptsCodesWithinTheTimeWindow(): void
    {
        $secret = $this->generator->generateSecret();
        $timestamp = 1000000;

        // Code generated for one period ahead (window allows +1)
        $futureCode = $this->generator->computeCode($secret, $timestamp + 30);
        self::assertTrue($this->verifier->verify($secret, $futureCode, $timestamp));

        // Code generated for one period behind (window allows -1)
        $pastCode = $this->generator->computeCode($secret, $timestamp - 30);
        self::assertTrue($this->verifier->verify($secret, $pastCode, $timestamp));

        // Code generated for two periods ahead (outside window of 1)
        $farFutureCode = $this->generator->computeCode($secret, $timestamp + 60);
        self::assertFalse($this->verifier->verify($secret, $farFutureCode, $timestamp));
    }
}
