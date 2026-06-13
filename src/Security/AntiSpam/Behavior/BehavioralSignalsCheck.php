<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\Behavior;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Security\AntiSpam\AntiSpamCheckInterface;
use Pulsar\Security\AntiSpam\AntiSpamCheckResult;
use Pulsar\Security\AntiSpam\AntiSpamContext;

use function is_string;

/**
 * Self-hosted, privacy-preserving behavioural signal check (SCORE-ONLY).
 *
 * Reads the compact, non-identifying interaction blob the client collector
 * wrote into a hidden field, grades it with a {@see BehaviorScorerInterface},
 * and contributes the score to the pipeline aggregate. It ALWAYS passes — it
 * never hard-rejects — because behavioural heuristics are probabilistic and a
 * false positive must never block a legitimate user; the score is advisory and
 * combines with the other checks. The graded vector is offered to a
 * {@see BehaviorFeatureSink} for optional offline model training.
 *
 * With no JavaScript (or a stripped field) the signals carry no information and
 * the scorer returns 0, so the check is a no-op for no-JS clients.
 *
 * It narrows the gap to Turnstile's behavioural layer with NO third party, but
 * has a real limitation: there is no global cross-site reputation — see the docs.
 */
#[Internal(reason: 'Use AntiSpamCheckInterface')]
final readonly class BehavioralSignalsCheck implements AntiSpamCheckInterface
{
    public function __construct(
        private BehaviorScorerInterface $scorer,
        private BehaviorFeatureSink $sink,
        private string $fieldName = 'pulsar-bx',
    ) {}

    #[Override]
    public function name(): string
    {
        return 'behavior';
    }

    #[Override]
    public function check(AntiSpamContext $context): AntiSpamCheckResult
    {
        /** @var mixed $value */
        $value = $context->formFields[$this->fieldName] ?? null;
        $blob = is_string($value) ? $value : '';

        $signals = BehaviorSignals::fromBlob($blob);
        $score = $this->scorer->score($signals);

        $this->sink->record($signals->toFeatureVector(), $score);

        // SCORE-ONLY: always pass. The score feeds the aggregate so operators
        // can act on the total, but this check alone never fails a submission.
        return new AntiSpamCheckResult(
            passed: true,
            checkName: $this->name(),
            score: $score,
        );
    }
}
