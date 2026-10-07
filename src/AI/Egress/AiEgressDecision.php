<?php

declare(strict_types=1);

namespace Pulsar\AI\Egress;

use NoDiscard;
use Pulsar\AI\Audit\EgressDecision;
use Pulsar\Api\Api;
use Pulsar\Security\Dlp\DlpMatch;
use Pulsar\Security\Dlp\SensitiveDataType;

use function array_map;
use function array_values;
use function count;
use function in_array;

/**
 * What the guard decided about one outbound call, and why.
 *
 * Carries the masked form of every match rather than the matched value. A
 * decision record describing a refusal is written to an audit sink and read by
 * people, and a record that quotes the card number it stopped has moved the
 * personal data rather than protected it. {@see DlpMatch::$maskedValue} is the
 * classifier's own masked rendering, so the masking rule is not re-implemented
 * here either.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class AiEgressDecision
{
    /** The declared destination is not on the operator's allow-list. */
    public const string REASON_DESTINATION_NOT_PERMITTED = 'destination_not_permitted';

    /** The wrapped client does not answer to the declared provider name. */
    public const string REASON_DESTINATION_MISMATCH = 'destination_mismatch';

    /** The classifier did not examine the payload, so it is unclassified. */
    public const string REASON_UNCLASSIFIED_PAYLOAD = 'unclassified_payload';

    /** Classified data found and the policy is to block. */
    public const string REASON_SENSITIVE_DATA_BLOCKED = 'sensitive_data_blocked';

    /** Classified data found where masking would corrupt the document. */
    public const string REASON_SENSITIVE_DATA_IN_STRUCTURED_FIELD = 'sensitive_data_in_structured_field';

    /** Nothing was refused. */
    public const string REASON_NONE = '';

    /**
     * @param string         $operation   The client method: `chat`, `stream_chat`,
     *                                    `complete`, `embed`, `structured_output`
     * @param list<DlpMatch> $matches     What the classifier found, masked
     * @param string         $reasonCode  Stable code, one of the `REASON_*`
     *                                    constants; empty when nothing was refused
     * @param string         $reason      The same thing in prose, for an operator
     */
    public function __construct(
        public AiEgressOutcome $outcome,
        public AiDestination $destination,
        public string $operation,
        public array $matches,
        public string $reasonCode,
        public string $reason,
    ) {}

    /**
     * The kinds of sensitive data involved, de-duplicated.
     *
     * Computed from the matches rather than stored alongside them, so the two
     * cannot come apart.
     *
     * @return list<SensitiveDataType>
     */
    #[NoDiscard]
    public function sensitiveDataTypes(): array
    {
        $types = [];

        foreach ($this->matches as $match) {
            if (!in_array($match->type, $types, true)) {
                $types[] = $match->type;
            }
        }

        return $types;
    }

    /**
     * The classification labels involved, as the classifier spells them.
     *
     * Labels, never values. The strings come straight from
     * {@see SensitiveDataType}: translating them into a second vocabulary for the
     * audit record would be a mapping that can drift from the classifier that
     * produced them.
     *
     * @return list<string>
     */
    #[NoDiscard]
    public function categoryLabels(): array
    {
        return array_values(array_map(
            static fn(SensitiveDataType $type): string => $type->value,
            $this->sensitiveDataTypes(),
        ));
    }

    /**
     * This decision in the audit seam's vocabulary.
     *
     * {@see AiEgressOutcome::Allowed} maps to {@see EgressDecision::passed()}
     * whether or not the classifier found anything — under
     * {@see \Pulsar\Security\Dlp\DlpAction::Alert} the content was inspected and
     * forwarded unchanged, which is exactly what `passed()` says. The categories
     * are lost in that one case, because the audit vocabulary has no "forwarded
     * carrying" factory; they survive in full through
     * {@see AiEgressObserverInterface}, which is told about every alert-only call.
     */
    #[NoDiscard]
    public function toAuditDecision(): EgressDecision
    {
        return match ($this->outcome) {
            AiEgressOutcome::Allowed => EgressDecision::passed(),
            AiEgressOutcome::Redacted => EgressDecision::redacted(
                count($this->matches),
                $this->categoryLabels(),
            ),
            AiEgressOutcome::Refused => EgressDecision::refusal(
                $this->reasonCode,
                $this->categoryLabels(),
            ),
        };
    }

    /**
     * The decision as audit metadata: counts and kinds, never values.
     *
     * @return array<string, mixed>
     */
    #[NoDiscard]
    public function toAuditMetadata(): array
    {
        return [
            'outcome' => $this->outcome->value,
            'operation' => $this->operation,
            'provider' => $this->destination->providerName,
            'host' => $this->destination->host,
            'match_count' => count($this->matches),
            'types' => $this->categoryLabels(),
            'reason_code' => $this->reasonCode,
            'reason' => $this->reason,
        ];
    }
}
