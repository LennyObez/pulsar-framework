<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\Signal\Internal;

use DateTimeImmutable;
use DateTimeZone;
use Pulsar\Api\Internal;
use Pulsar\Security\ZeroTrust\Claim\Claim;
use Pulsar\Security\ZeroTrust\Claim\ClaimSet;
use Pulsar\Security\ZeroTrust\Claim\ClaimSource;
use Pulsar\Security\ZeroTrust\Signal\SignalContext;
use Pulsar\Security\ZeroTrust\Signal\SignalProviderInterface;

use function in_array;

/**
 * Produces time-based trust claims.
 *
 * Compares request time against configurable business hours and the user's
 * historical access patterns to flag off-hours and unusual access times.
 *
 * Claims produced:
 * - `time.off_hours` (bool): Whether the request is outside configured business hours
 * - `time.unusual` (bool): Whether the request time deviates from the user's typical patterns
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final readonly class TimeSignalProvider implements SignalProviderInterface
{
    private const float CONFIDENCE_KNOWN_TIMEZONE = 0.9;
    private const float CONFIDENCE_FALLBACK = 0.5;

    private const int DEFAULT_BUSINESS_START = 6;
    private const int DEFAULT_BUSINESS_END = 22;

    public function __construct(
        private int $businessHourStart = self::DEFAULT_BUSINESS_START,
        private int $businessHourEnd = self::DEFAULT_BUSINESS_END,
    ) {}

    public function evaluate(SignalContext $context): ClaimSet
    {
        $now = new DateTimeImmutable();

        /** @var string|null $timezone */
        $timezone = $context->attribute('timezone');
        $hasKnownTimezone = $timezone !== null && $timezone !== '';

        $localHour = $this->resolveLocalHour($now, $timezone);
        $isOffHours = $localHour < $this->businessHourStart || $localHour >= $this->businessHourEnd;

        /** @var list<int>|null $typicalHours */
        $typicalHours = $context->attribute('typical_hours');
        $isUnusual = $typicalHours !== null && !in_array($localHour, $typicalHours, true);

        $confidence = $hasKnownTimezone ? self::CONFIDENCE_KNOWN_TIMEZONE : self::CONFIDENCE_FALLBACK;

        return new ClaimSet([
            new Claim(
                name: 'time.off_hours',
                value: $isOffHours,
                source: ClaimSource::TimeSignal,
                confidence: $confidence,
                timestamp: $now,
            ),
            new Claim(
                name: 'time.unusual',
                value: $isUnusual,
                source: ClaimSource::TimeSignal,
                confidence: $typicalHours !== null ? $confidence : self::CONFIDENCE_FALLBACK,
                timestamp: $now,
            ),
        ]);
    }

    public function name(): string
    {
        return 'time';
    }

    private function resolveLocalHour(DateTimeImmutable $now, ?string $timezone): int
    {
        if ($timezone !== null && $timezone !== '') {
            $tz = new DateTimeZone($timezone);
            $local = $now->setTimezone($tz);

            return (int) $local->format('G');
        }

        return (int) $now->format('G');
    }
}
