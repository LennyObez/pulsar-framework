<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Whether the deployment knows which of its own entry points handle regulated
 * data.
 *
 * An asset inventory that lists the framework's features is not an inventory of
 * the deployment. What is observable here is the route table the router will
 * dispatch and the classifications attached to it.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class AssetInventoryProbe extends CapabilityProbe
{
    #[Override]
    #[NoDiscard]
    public function id(): string
    {
        return 'probe.asset_inventory';
    }

    #[Override]
    #[NoDiscard]
    public function describe(): string
    {
        return 'Whether the routes handling regulated data are classified and inventoried.';
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
            ObservationId::ComplianceProfileResolved,
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
            'Tag the routes that handle regulated data with the data_classification '
                . 'route attribute; an untagged route is invisible to every route-level '
                . 'control.',
        ];
    }
}
