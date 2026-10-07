<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\Behavior;

use NoDiscard;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Support\Coerce;

/**
 * Default "ML-lite" behavioural scorer with hand-tuned, configurable weights.
 *
 * A transparent linear model over the {@see BehaviorSignals} feature vector: it
 * adds a weight for each bot-like signal (automation flag, no interaction,
 * implausibly fast fill, no pointer movement, near-total paste). It returns 0
 * when no behavioural signal is present, so a no-JS client is scored neutrally
 * rather than penalised. An application can bind a trained
 * {@see BehaviorScorerInterface} to replace it without touching the framework.
 */
#[Internal(reason: 'Use BehaviorScorerInterface')]
final readonly class HeuristicScorer implements BehaviorScorerInterface
{
    public function __construct(
        private int $webdriverWeight = 40,
        private int $noInteractionWeight = 30,
        private int $tooFastWeight = 20,
        private int $noPointerEntropyWeight = 10,
        private int $highPasteWeight = 15,
        private int $minHumanFillMs = 800,
        private float $highPasteRatio = 0.9,
    ) {}

    /**
     * @param array<string, mixed> $weights
     */
    #[NoDiscard]
    public static function fromWeights(array $weights): self
    {
        return new self(
            webdriverWeight: Coerce::int($weights['webdriver'] ?? null, 40),
            noInteractionWeight: Coerce::int($weights['no_interaction'] ?? null, 30),
            tooFastWeight: Coerce::int($weights['too_fast'] ?? null, 20),
            noPointerEntropyWeight: Coerce::int($weights['no_pointer_entropy'] ?? null, 10),
            highPasteWeight: Coerce::int($weights['high_paste'] ?? null, 15),
            minHumanFillMs: Coerce::int($weights['min_human_fill_ms'] ?? null, 800),
            highPasteRatio: Coerce::float($weights['high_paste_ratio'] ?? null, 0.9),
        );
    }

    #[Override]
    public function score(BehaviorSignals $signals): int
    {
        // No usable signal (no-JS, stripped field): neutral, never penalised.
        if (!$signals->hasSignal()) {
            return 0;
        }

        $score = 0;

        if ($signals->webdriver) {
            $score += $this->webdriverWeight;
        }

        if (!$signals->interactionPresent) {
            $score += $this->noInteractionWeight;
        }

        if ($signals->fillDurationMs > 0 && $signals->fillDurationMs < $this->minHumanFillMs) {
            $score += $this->tooFastWeight;
        }

        // Filled the form but the pointer never moved (no mouse, no touch drag).
        if ($signals->interactionPresent && $signals->pointerEntropy <= 0.0) {
            $score += $this->noPointerEntropyWeight;
        }

        if ($signals->pasteRatio >= $this->highPasteRatio) {
            $score += $this->highPasteWeight;
        }

        return $score;
    }
}
