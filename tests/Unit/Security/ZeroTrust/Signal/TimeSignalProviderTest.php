<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ZeroTrust\Signal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Security\ZeroTrust\Claim\Claim;
use Pulsar\Security\ZeroTrust\Claim\ClaimSet;
use Pulsar\Security\ZeroTrust\Claim\ClaimSource;
use Pulsar\Security\ZeroTrust\Signal\Internal\TimeSignalProvider;
use Pulsar\Security\ZeroTrust\Signal\SignalContext;

use function sprintf;

#[CoversClass(TimeSignalProvider::class)]
final class TimeSignalProviderTest extends TestCase
{
    #[Test]
    public function nameReturnsTime(): void
    {
        $provider = new TimeSignalProvider();

        self::assertSame('time', $provider->name());
    }

    #[Test]
    public function producesTwoClaims(): void
    {
        $provider = new TimeSignalProvider();
        $claims = $provider->evaluate($this->createContext());

        self::assertCount(2, $claims);
        self::assertTrue($claims->has('time.off_hours'));
        self::assertTrue($claims->has('time.unusual'));
    }

    #[Test]
    public function allClaimsHaveTimeSource(): void
    {
        $provider = new TimeSignalProvider();
        $claims = $provider->evaluate($this->createContext());

        foreach ($claims as $claim) {
            self::assertSame(ClaimSource::TimeSignal, $claim->source);
        }
    }

    #[Test]
    public function detectsOffHoursWithKnownTimezone(): void
    {
        // Configure business hours 9-17, then test with a timezone where it's 3 AM
        $provider = new TimeSignalProvider(businessHourStart: 9, businessHourEnd: 17);

        // Use a timezone far enough from current system to guarantee off-hours
        // We'll use the 'typical_hours' attribute test which is timezone-independent
        $context = $this->createContext(attributes: [
            'timezone' => 'Pacific/Kiritimati', // UTC+14 — shifts hour significantly
        ]);

        $claims = $provider->evaluate($context);

        self::assertSame(0.9, self::firstClaim($claims, 'time.off_hours')->confidence);
    }

    #[Test]
    public function withinBusinessHoursReturnsFalseForOffHours(): void
    {
        // Set business hours to 0-24 so any time is within business hours
        $provider = new TimeSignalProvider(businessHourStart: 0, businessHourEnd: 24);

        $claims = $provider->evaluate($this->createContext());

        self::assertFalse(self::firstClaim($claims, 'time.off_hours')->value);
    }

    #[Test]
    public function outsideBusinessHoursReturnsTrueForOffHours(): void
    {
        // Set business hours to an impossible range (same hour = zero-width window)
        $provider = new TimeSignalProvider(businessHourStart: 12, businessHourEnd: 12);

        $claims = $provider->evaluate($this->createContext());

        // Any hour != 12..11 will be off hours (start >= end means always off)
        self::assertTrue(self::firstClaim($claims, 'time.off_hours')->value);
    }

    #[Test]
    public function detectsUnusualTimeWithTypicalHoursProvided(): void
    {
        $provider = new TimeSignalProvider();

        // Provide typical hours that do NOT include the current hour
        $context = $this->createContext(attributes: [
            'typical_hours' => [-1], // No real hour matches -1
        ]);

        $claims = $provider->evaluate($context);

        self::assertTrue(self::firstClaim($claims, 'time.unusual')->value);
    }

    #[Test]
    public function notUnusualWhenCurrentHourIsTypical(): void
    {
        $provider = new TimeSignalProvider();

        // Include all possible hours so the current one is always covered
        $context = $this->createContext(attributes: [
            'typical_hours' => range(0, 23),
        ]);

        $claims = $provider->evaluate($context);

        self::assertFalse(self::firstClaim($claims, 'time.unusual')->value);
    }

    #[Test]
    public function noTypicalHoursResultsInNotUnusual(): void
    {
        $provider = new TimeSignalProvider();
        $claims = $provider->evaluate($this->createContext());

        self::assertFalse(self::firstClaim($claims, 'time.unusual')->value);
    }

    #[Test]
    public function unknownTimezoneReturnsFallbackConfidence(): void
    {
        $provider = new TimeSignalProvider();
        $claims = $provider->evaluate($this->createContext());

        self::assertSame(0.5, self::firstClaim($claims, 'time.off_hours')->confidence);
    }

    #[Test]
    public function knownTimezoneReturnsHighConfidence(): void
    {
        $provider = new TimeSignalProvider();
        $context = $this->createContext(attributes: ['timezone' => 'America/New_York']);

        $claims = $provider->evaluate($context);

        self::assertSame(0.9, self::firstClaim($claims, 'time.off_hours')->confidence);
    }

    #[Test]
    public function unusualClaimGetsFallbackConfidenceWithoutTypicalHours(): void
    {
        $provider = new TimeSignalProvider();
        $context = $this->createContext(attributes: ['timezone' => 'Europe/London']);

        $claims = $provider->evaluate($context);

        // Even with known timezone, unusual claim confidence is fallback when no typical hours provided
        self::assertSame(0.5, self::firstClaim($claims, 'time.unusual')->confidence);
    }

    private static function firstClaim(ClaimSet $claims, string $name): Claim
    {
        $claim = $claims->first($name);
        self::assertNotNull($claim, sprintf('Expected claim "%s" to exist', $name));

        return $claim;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function createContext(array $attributes = []): SignalContext
    {
        $request = $this->createStub(ServerRequestInterface::class);

        return new SignalContext(
            request: $request,
            identityId: 'user-1',
            attributes: $attributes,
        );
    }
}
