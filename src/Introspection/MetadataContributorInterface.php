<?php

declare(strict_types=1);

namespace Pulsar\Introspection;

use Pulsar\Api\Api;

/**
 * Contract for components that contribute metadata to the introspection snapshot.
 *
 * Extensions and framework subsystems implement this interface to expose
 * runtime information (bindings, configuration, state) to the introspection
 * layer. Each contributor is identified by a unique string ID and populates
 * a scoped {@see ProjectMetadataBuilder}.
 * @api
 */
#[Api(since: '1.0.0')]
interface MetadataContributorInterface
{
    /**
     * Unique identifier for this contributor (e.g. "router", "container", "ext:payments").
     */
    public function id(): string;

    /**
     * Populate the builder with this contributor's metadata sections.
     */
    public function contribute(ProjectMetadataBuilder $builder): void;
}
