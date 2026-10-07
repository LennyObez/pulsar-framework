<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Dto;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\AiGovernance\Contracts\MonitoringResult;

/**
 * One retained monitoring result: what was checked, about which model, when.
 *
 * ISO 42001:2023 Clause 9.1 asks four things of an organisation — what is
 * monitored, by which method, when the monitoring happens, and when the results
 * are analysed — and then closes with the sentence the rest of this extension
 * had no answer for: *the organization shall retain appropriate documented
 * information as evidence of the results*. A {@see MonitoringResult} answers the
 * first four and evaporates. This type is that result with the two facts
 * retention needs bolted on — the model it is about and the instant it was
 * taken — so a store can hold it and an assessor can read it back.
 *
 * The instant is HANDED IN rather than read from a clock here, for the same
 * reason {@see \Pulsar\Extension\AiGovernance\Transparency\SyntheticContentMark}
 * takes its generation time: whatever ran the check knows when it ran, and a
 * record that stamped itself at construction would be recording the time it was
 * turned into a row and calling it the time of observation.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class MonitoringRecord
{
    /**
     * @param non-empty-string     $modelId  The model the check was run against
     * @param non-empty-string     $hookName The hook that produced the result
     * @param bool                 $healthy  Whether the check passed
     * @param non-empty-string     $message  The hook's human-readable summary
     * @param array<string, mixed> $metrics  Whatever the hook measured
     */
    public function __construct(
        public string $modelId,
        public string $hookName,
        public bool $healthy,
        public string $message,
        public array $metrics,
        public DateTimeImmutable $observedAt,
    ) {}

    /**
     * Build the retainable record from a hook's result.
     *
     * @param non-empty-string $modelId
     */
    #[NoDiscard]
    public static function of(
        string $modelId,
        MonitoringResult $result,
        DateTimeImmutable $observedAt,
    ): self {
        return new self(
            modelId: $modelId,
            hookName: $result->hookName,
            healthy: $result->healthy,
            message: $result->message,
            metrics: $result->metrics,
            observedAt: $observedAt,
        );
    }
}
