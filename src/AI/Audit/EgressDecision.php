<?php

declare(strict_types=1);

namespace Pulsar\AI\Audit;

use InvalidArgumentException;
use NoDiscard;
use Pulsar\Api\Api;

use function array_values;
use function count;
use function trim;

/**
 * What an egress control decided about one outbound inference.
 *
 * The audit decorator does not, and must not, work this out for itself. It never
 * sees the messages after the egress control has rewritten them, and inferring
 * "a refusal happened" from the class of an exception would be a status the
 * auditor invented rather than one the control measured — the defect ADR-0050
 * names. So the control that inspects the content produces this value, and the
 * auditor copies it into the record. When no control reported one, the record
 * says the egress decision was NOT OBSERVED, which is a different fact from
 * "nothing was redacted" and is written as such.
 *
 * NOTHING HERE MAY CARRY CONTENT. {@see $categories} holds classification labels
 * (`pii.email`, `phi`, `pan`) and {@see $refusalReason} holds a stable reason
 * code — never the matched text, never the surrounding sentence, never the field
 * value that triggered the rule. An audit trail that recorded what the egress
 * seam just refused to send would be a second copy of the same personal data, in
 * a file kept for years.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class EgressDecision
{
    /**
     * @param bool $redactionApplied Whether the control rewrote any part of the outbound content
     * @param int $redactedSpanCount How many spans it rewrote; 0 when it rewrote none
     * @param list<string> $categories Classification labels of what matched, never the values that matched
     * @param bool $refused Whether the control stopped the call instead of forwarding it
     * @param string $refusalReason Stable reason code for a refusal; empty when the call was not refused
     */
    private function __construct(
        public bool $redactionApplied,
        public int $redactedSpanCount,
        public array $categories,
        public bool $refused,
        public string $refusalReason,
    ) {}

    /**
     * The control inspected the content and forwarded it unchanged.
     */
    #[NoDiscard]
    public static function passed(): self
    {
        return new self(false, 0, [], false, '');
    }

    /**
     * The control rewrote part of the content and forwarded the rest.
     *
     * @param list<string> $categories Classification labels of what was rewritten
     *
     * @throws InvalidArgumentException When no span was rewritten — that is
     *                                  {@see passed()}, and recording it as a
     *                                  redaction would overstate the control.
     */
    #[NoDiscard]
    public static function redacted(int $spanCount, array $categories = []): self
    {
        if ($spanCount < 1) {
            throw new InvalidArgumentException(
                'EgressDecision::redacted() needs at least one rewritten span; use passed() when nothing was rewritten',
            );
        }

        return new self(true, $spanCount, array_values($categories), false, '');
    }

    /**
     * The control stopped the call. Nothing reached the provider.
     *
     * @param string $reason Stable reason code, e.g. `unclassified_personal_data`
     * @param list<string> $categories Classification labels that triggered the refusal
     *
     * @throws InvalidArgumentException When the reason is blank: a refusal nobody
     *                                  can explain is indistinguishable from an
     *                                  outage, and the two get triaged differently.
     */
    #[NoDiscard]
    public static function refusal(string $reason, array $categories = []): self
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('EgressDecision::refusal() needs a non-empty reason code');
        }

        return new self(false, 0, array_values($categories), true, $reason);
    }

    /**
     * How many classification labels this decision carries.
     */
    #[NoDiscard]
    public function categoryCount(): int
    {
        return count($this->categories);
    }
}
