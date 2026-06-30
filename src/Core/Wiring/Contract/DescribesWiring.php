<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring\Contract;

use Pulsar\Api\Api;

/**
 * Implemented by a {@see \Pulsar\Core\Wiring\ServiceWiringInterface} that
 * describes its config + binding contract.
 *
 * Opt-in and backward compatible: wirings that do not implement it are simply
 * absent from the contract graph. Implementing it lets the framework verify the
 * full-boot graph (no required binding left unprovided) and report any feature
 * degraded by a missing optional binding.
 * @api
 */
#[Api(since: '1.0.0-rc.11')]
interface DescribesWiring
{
    public function describeWiring(): WiringContract;
}
