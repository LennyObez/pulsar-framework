<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\Behavior;

use NoDiscard;
use Pulsar\Api\Api;

use function is_array;
use function is_bool;
use function is_int;
use function is_numeric;
use function json_decode;
use function max;
use function min;

/**
 * Privacy-preserving interaction signals collected from a form submission.
 *
 * Carries ONLY non-identifying, aggregate behavioural measurements — never
 * field values, text, IP, or any persistent identifier. It is the feature
 * vector a {@see BehaviorScorerInterface} grades and a
 * {@see BehaviorFeatureSink} may log for offline model training.
 * @api
 */
#[Api(since: '1.0.0-rc.11')]
final readonly class BehaviorSignals
{
    /**
     * @param bool  $interactionPresent Any focus/keydown/pointermove/scroll occurred before submit
     * @param int   $fillDurationMs     Milliseconds from first interaction (or load) to submit
     * @param float $pointerEntropy     Normalised movement entropy in [0,1] (0 = no movement)
     * @param bool  $webdriver          navigator.webdriver was true (automation flag)
     * @param float $pasteRatio         Pasted characters / total entered characters, in [0,1]
     * @param int   $keydownCount       Total keydown events observed
     */
    public function __construct(
        public bool $interactionPresent = false,
        public int $fillDurationMs = 0,
        public float $pointerEntropy = 0.0,
        public bool $webdriver = false,
        public float $pasteRatio = 0.0,
        public int $keydownCount = 0,
    ) {}

    /**
     * Whether the submission carried any usable behavioural signal at all.
     *
     * A no-JS client (or one that stripped the field) yields "no signal": the
     * scorer must treat that neutrally rather than as either bot or human.
     */
    #[NoDiscard]
    public function hasSignal(): bool
    {
        return $this->interactionPresent
            || $this->fillDurationMs > 0
            || $this->keydownCount > 0
            || $this->pointerEntropy > 0.0;
    }

    /**
     * @return array<string, bool|float|int> The raw feature vector (for a feature sink)
     */
    #[NoDiscard]
    public function toFeatureVector(): array
    {
        return [
            'interaction_present' => $this->interactionPresent,
            'fill_duration_ms' => $this->fillDurationMs,
            'pointer_entropy' => $this->pointerEntropy,
            'webdriver' => $this->webdriver,
            'paste_ratio' => $this->pasteRatio,
            'keydown_count' => $this->keydownCount,
        ];
    }

    /**
     * Parse the compact client blob. Returns an all-default ("no signal")
     * instance for missing, malformed, or non-object input — the check is
     * score-only, so untrusted input must never throw, only score neutrally.
     */
    #[NoDiscard]
    public static function fromBlob(string $blob): self
    {
        if ($blob === '') {
            return new self();
        }

        /** @var mixed $data */
        $data = json_decode($blob, true, 8);

        if (!is_array($data)) {
            return new self();
        }

        return new self(
            interactionPresent: isset($data['i']) && is_bool($data['i']) ? $data['i'] : (($data['i'] ?? 0) === 1),
            fillDurationMs: self::clampInt($data['d'] ?? 0, 0, 86_400_000),
            pointerEntropy: self::clampFloat($data['pe'] ?? 0, 0.0, 1.0),
            webdriver: ($data['wd'] ?? 0) === 1 || ($data['wd'] ?? false) === true,
            pasteRatio: self::clampFloat($data['pr'] ?? 0, 0.0, 1.0),
            keydownCount: self::clampInt($data['kc'] ?? 0, 0, 1_000_000),
        );
    }

    private static function clampInt(mixed $value, int $lo, int $hi): int
    {
        if (!is_int($value) && !is_numeric($value)) {
            return $lo;
        }

        return max($lo, min($hi, (int) $value));
    }

    private static function clampFloat(mixed $value, float $lo, float $hi): float
    {
        if (!is_numeric($value)) {
            return $lo;
        }

        return max($lo, min($hi, (float) $value));
    }
}
