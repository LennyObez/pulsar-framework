<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam\Behavior;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\AntiSpam\Behavior\BehaviorSignals;
use Pulsar\Security\AntiSpam\Behavior\HeuristicScorer;

#[CoversClass(HeuristicScorer::class)]
final class HeuristicScorerTest extends TestCase
{
    private function scorer(): HeuristicScorer
    {
        return new HeuristicScorer();
    }

    #[Test]
    public function noSignalScoresZero(): void
    {
        // A no-JS client (all defaults, hasSignal() false) is never penalised.
        self::assertSame(0, $this->scorer()->score(new BehaviorSignals()));
    }

    #[Test]
    public function aPlausibleHumanScoresZero(): void
    {
        $human = new BehaviorSignals(
            interactionPresent: true,
            fillDurationMs: 8000,
            pointerEntropy: 0.6,
            webdriver: false,
            pasteRatio: 0.0,
            keydownCount: 50,
        );

        self::assertSame(0, $this->scorer()->score($human));
    }

    #[Test]
    public function webdriverContributesItsWeight(): void
    {
        $signals = new BehaviorSignals(interactionPresent: true, fillDurationMs: 8000, pointerEntropy: 0.6, webdriver: true);

        self::assertSame(40, $this->scorer()->score($signals));
    }

    #[Test]
    public function tooFastFillContributesItsWeight(): void
    {
        $signals = new BehaviorSignals(interactionPresent: true, fillDurationMs: 200, pointerEntropy: 0.6);

        self::assertSame(20, $this->scorer()->score($signals));
    }

    #[Test]
    public function filledWithoutPointerMovementContributesItsWeight(): void
    {
        $signals = new BehaviorSignals(interactionPresent: true, fillDurationMs: 8000, pointerEntropy: 0.0);

        self::assertSame(10, $this->scorer()->score($signals));
    }

    #[Test]
    public function nearTotalPasteContributesItsWeight(): void
    {
        $signals = new BehaviorSignals(interactionPresent: true, fillDurationMs: 8000, pointerEntropy: 0.6, pasteRatio: 0.95);

        self::assertSame(15, $this->scorer()->score($signals));
    }

    #[Test]
    public function signalsAccumulate(): void
    {
        // webdriver (40) + too-fast (20) + no-pointer (10) + paste (15) = 85
        $bot = new BehaviorSignals(
            interactionPresent: true,
            fillDurationMs: 100,
            pointerEntropy: 0.0,
            webdriver: true,
            pasteRatio: 1.0,
        );

        self::assertSame(85, $this->scorer()->score($bot));
    }

    #[Test]
    public function weightsAreConfigurable(): void
    {
        $scorer = HeuristicScorer::fromWeights(['webdriver' => 99]);
        $signals = new BehaviorSignals(interactionPresent: true, fillDurationMs: 8000, pointerEntropy: 0.6, webdriver: true);

        self::assertSame(99, $scorer->score($signals));
    }
}
