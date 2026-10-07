<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Whether the AI models in service are recorded anywhere.
 *
 * Like every ISO 42001 probe this requires the ai-governance extension to be
 * ACTIVE, not merely installable. An interface shipped in a package that no
 * deployment enabled documents nothing.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class AiModelRegistryProbe extends CapabilityProbe
{
    #[Override]
    #[NoDiscard]
    public function id(): string
    {
        return 'probe.ai_model_registry';
    }

    #[Override]
    #[NoDiscard]
    public function describe(): string
    {
        return 'Whether the AI governance extension is active and a model registry resolved.';
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
            ObservationId::AiModelRegistryResolved,
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
            'Install and enable the pulsar/ai-governance extension; every ISO/IEC '
                . '42001 control depends on it.',
            'If this deployment operates no AI system, remove Iso42001 from '
                . 'enabled_frameworks rather than leaving its controls claimed and '
                . 'unobserved.',
        ];
    }
}
