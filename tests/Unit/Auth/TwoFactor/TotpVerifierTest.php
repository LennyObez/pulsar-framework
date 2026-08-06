<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\TwoFactor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\TwoFactor\InMemoryTotpReplayGuard;
use Pulsar\Auth\TwoFactor\TotpGenerator;
use Pulsar\Auth\TwoFactor\TotpVerifier;

use function intdiv;
use function sprintf;

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

    /**
     * Configurations whose acceptance envelope differs from the guard's former
     * fixed retention: shipped defaults, a longer period, and a wider window.
     *
     * @return iterable<string, array{int, int}> period, window
     */
    public static function envelopeConfigurations(): iterable
    {
        yield 'shipped defaults' => [30, 1];
        yield 'longer period' => [60, 1];
        yield 'wider window' => [30, 2];
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
    public function verifyWithReplayGuardRejectsReplayAtA61SecondGap(): void
    {
        // Gap zero is the one gap every possible retention survives. At shipped
        // defaults the verifier keeps accepting a code for 90 s, so a guard that
        // forgets after 60 s hands the attacker the tail of the envelope.
        $secret = $this->generator->generateSecret();
        $timeStep = 33333;
        $firstUse = 999980;              // step 33332: the code is reached via +1
        $replay = $firstUse + 61;        // step 33334: the code is reached via -1
        $code = $this->generator->computeCode($secret, $timeStep * 30);
        $guard = new InMemoryTotpReplayGuard();

        // Both submissions are inside the acceptance envelope, so a rejection can
        // only come from the guard.
        self::assertNotNull($this->verifier->verify($secret, $code, $firstUse));
        self::assertNotNull($this->verifier->verify($secret, $code, $replay));

        self::assertNotNull($this->verifier->verify($secret, $code, $firstUse, $guard, 'user-1'));
        self::assertNull($this->verifier->verify($secret, $code, $replay, $guard, 'user-1'));
    }

    /**
     * A code is redeemable exactly once, at every pair of submission instants the
     * verifier will accept it -- not merely at the identical instant.
     */
    #[Test]
    #[DataProvider('envelopeConfigurations')]
    public function verifyWithReplayGuardRejectsEveryReplayInsideTheEnvelope(int $period, int $window): void
    {
        $generator = new TotpGenerator(period: $period);
        $verifier = new TotpVerifier($generator, window: $window);
        $secret = $generator->generateSecret();

        $timeStep = intdiv(1000000, $period);
        $code = $generator->computeCode($secret, $timeStep * $period);

        $first = ($timeStep - $window) * $period;
        $last = ($timeStep + $window + 1) * $period - 1;

        // Sampling stride keeps the pair count bounded while still crossing every
        // period boundary in the envelope.
        $stride = 7;

        for ($use = $first; $use <= $last; $use += $stride) {
            for ($replay = $use; $replay <= $last; $replay += $stride) {
                $guard = new InMemoryTotpReplayGuard($period, $window);

                self::assertNotNull(
                    $verifier->verify($secret, $code, $use, $guard, 'user-1'),
                    sprintf('First use at offset %d must be accepted', $use - $first),
                );
                self::assertNull(
                    $verifier->verify($secret, $code, $replay, $guard, 'user-1'),
                    sprintf(
                        'Replay %d s later (offset %d) must be rejected',
                        $replay - $use,
                        $replay - $first,
                    ),
                );
            }
        }
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
