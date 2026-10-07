<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Whether the deployment's OWN routes that handle regulated data carry the
 * middleware their classification requires.
 *
 * This is the difference between a claim about the framework and a statement
 * about the deployment. A framework that offers RBAC middleware proves nothing
 * about a route that forgot to apply it.
 *
 * The inert-feature fact is required, not supporting: a security control that
 * is bound but disabled by a missing optional binding restricts nothing, and
 * that is the one failure the wiring-contract inspector exists to catch.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class AccessRestrictionProbe extends CapabilityProbe
{
    #[Override]
    #[NoDiscard]
    public function id(): string
    {
        return 'probe.access_restriction';
    }

    #[Override]
    #[NoDiscard]
    public function describe(): string
    {
        return 'Whether every classified route carries its required middleware and no security feature is inert.';
    }

    /**
     * @return non-empty-list<ObservationId>
     */
    #[Override]
    #[NoDiscard]
    protected function required(): array
    {
        return [
            ObservationId::ClassifiedRouteCoverage,
            ObservationId::SecurityFeaturesIntact,
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
            ObservationId::MfaSubsystemResolved,
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
            'Tag every route that handles regulated data with the data_classification '
                . 'route attribute, so its middleware can be checked at all.',
            'Add the missing middleware named in the evidence to the routes that lack '
                . 'it.',
            'Bind the optional services the wiring contracts name, so no security '
                . 'feature is left bound-but-inert.',
        ];
    }
}
