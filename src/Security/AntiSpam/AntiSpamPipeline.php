<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam;

use Override;
use Pulsar\Api\Internal;

/**
 * Default anti-spam pipeline that runs registered checks in order.
 *
 * Supports short-circuit mode (stop on first failure) and
 * full-evaluation mode (run all checks, aggregate scores).
 */
#[Internal(reason: 'Use AntiSpamPipelineInterface')]
final readonly class AntiSpamPipeline implements AntiSpamPipelineInterface
{
    /**
     * @param list<AntiSpamCheckInterface> $checks Ordered list of checks to run
     * @param bool $shortCircuit Stop on first failure when true
     */
    public function __construct(
        private array $checks,
        private bool $shortCircuit = false,
    ) {}

    #[Override]
    public function evaluate(AntiSpamContext $context): AntiSpamResult
    {
        $results = [];

        foreach ($this->checks as $check) {
            $result = $check->check($context);
            $results[] = $result;

            if ($this->shortCircuit && !$result->passed) {
                break;
            }
        }

        return AntiSpamResult::fromCheckResults($results);
    }
}
