<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam\Behavior;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\AntiSpam\Behavior\BehaviorSignals;

use function json_encode;

#[CoversClass(BehaviorSignals::class)]
final class BehaviorSignalsTest extends TestCase
{
    #[Test]
    public function parsesACompactBlob(): void
    {
        $blob = json_encode(['i' => 1, 'd' => 4200, 'pe' => 0.73, 'wd' => 0, 'pr' => 0.1, 'kc' => 42]);
        $signals = BehaviorSignals::fromBlob((string) $blob);

        self::assertTrue($signals->interactionPresent);
        self::assertSame(4200, $signals->fillDurationMs);
        self::assertSame(0.73, $signals->pointerEntropy);
        self::assertFalse($signals->webdriver);
        self::assertSame(0.1, $signals->pasteRatio);
        self::assertSame(42, $signals->keydownCount);
        self::assertTrue($signals->hasSignal());
    }

    #[Test]
    public function emptyBlobIsNoSignal(): void
    {
        $signals = BehaviorSignals::fromBlob('');

        self::assertFalse($signals->hasSignal());
        self::assertSame(0, $signals->fillDurationMs);
    }

    #[Test]
    public function malformedBlobIsNoSignalRatherThanThrowing(): void
    {
        self::assertFalse(BehaviorSignals::fromBlob('not json')->hasSignal());
        self::assertFalse(BehaviorSignals::fromBlob('"a string"')->hasSignal());
        self::assertFalse(BehaviorSignals::fromBlob('[1,2,3]')->hasSignal());
    }

    #[Test]
    public function outOfRangeValuesAreClamped(): void
    {
        $blob = json_encode(['pe' => 9.9, 'pr' => -3, 'd' => -10, 'kc' => 'x']);
        $signals = BehaviorSignals::fromBlob((string) $blob);

        self::assertSame(1.0, $signals->pointerEntropy);
        self::assertSame(0.0, $signals->pasteRatio);
        self::assertSame(0, $signals->fillDurationMs);
        self::assertSame(0, $signals->keydownCount);
    }

    #[Test]
    public function webdriverFlagIsParsedFromBothBoolAndInt(): void
    {
        self::assertTrue(BehaviorSignals::fromBlob('{"wd":1}')->webdriver);
        self::assertTrue(BehaviorSignals::fromBlob('{"wd":true}')->webdriver);
        self::assertFalse(BehaviorSignals::fromBlob('{"wd":0}')->webdriver);
    }

    #[Test]
    public function featureVectorRoundTripsTheSignals(): void
    {
        $signals = new BehaviorSignals(
            interactionPresent: true,
            fillDurationMs: 1000,
            pointerEntropy: 0.5,
            webdriver: true,
            pasteRatio: 0.2,
            keydownCount: 10,
        );

        self::assertSame([
            'interaction_present' => true,
            'fill_duration_ms' => 1000,
            'pointer_entropy' => 0.5,
            'webdriver' => true,
            'paste_ratio' => 0.2,
            'keydown_count' => 10,
        ], $signals->toFeatureVector());
    }
}
