<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Whether a decision this system makes can actually be explained to the person it affected.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class AiExplainabilityProbe extends CapabilityProbe
{
    #[Override]
    #[NoDiscard]
    public function id(): string
    {
        return 'probe.ai_explainability';
    }

    #[Override]
    #[NoDiscard]
    public function describe(): string
    {
        return 'Whether the AI governance extension is active and an explainability implementation resolved.';
    }

    /**
     * @return non-empty-list<ObservationId>
     */
    #[Override]
    #[NoDiscard]
    protected function required(): array
    {
        return [
            ObservationId::AiGovernanceExtensionActive,
            ObservationId::AiExplainabilityResolved,
            // Added in rc.12, and this is the control where durability is the
            // whole substance. A person contesting an automated decision asks
            // days or weeks later; an explanation held in the memory of the
            // worker that produced the decision is gone before they ask, so a
            // bound explainability store proves nothing about whether the
            // explanation can still be given.
            ObservationId::AiGovernanceRecordsDurable,
        ];
    }

    /**
     * @return list<ObservationId>
     */
    #[Override]
    #[NoDiscard]
    protected function supporting(): array
    {
        return [
            ObservationId::AiAuditLoggerResolved,
        ];
    }

    /**
     * @return non-empty-list<non-empty-string>
     */
    #[Override]
    #[NoDiscard]
    protected function remediations(): array
    {
        return [
            'Install and enable the pulsar/ai-governance extension and bind an '
                . 'ExplainabilityInterface implementation.',
            'Point the ai_governance store keys at a durable implementation. The extension ships '
                . 'one for each store — "database" is the default in config/ai-governance.php since '
                . 'rc.12 — and `pulsar migrate` creates the tables. An in-memory store loses the '
                . 'record at the next restart, which is a gap this check reports rather than '
                . 'assumes.',
            'If this deployment operates no AI system, remove Iso42001 from '
                . 'enabled_frameworks.',
        ];
    }
}
