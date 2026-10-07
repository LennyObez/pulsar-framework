<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\Compliance;

use Pulsar\Api\Api;

/**
 * Supplies the compliance badge block with observed assessment results.
 *
 * The block renders nothing that does not come through here, which is the whole
 * point of the indirection: a page badge asserting conformity to a standard is
 * a claim made to the public, and the only defensible basis for it is an
 * assessment that was actually run against the deployment doing the asserting.
 *
 * Bind your own implementation if your reports live somewhere other than a file
 * on disk — an object store, a control plane, a database. What an implementation
 * must not do is synthesise a status: returning `null` for a framework nobody
 * assessed is correct and expected behaviour.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
interface ObservedComplianceSourceInterface
{
    /**
     * The most recent observed status for a framework, or null when the
     * framework was not part of the assessment.
     *
     * @param non-empty-string $frameworkKey Report key, e.g. `soc2`, `pci_dss`
     */
    public function statusFor(string $frameworkKey): ?ObservedFrameworkStatus;
}
