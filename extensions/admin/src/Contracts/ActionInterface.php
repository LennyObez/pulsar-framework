<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Contracts;

use Pulsar\Api\Api;
use Pulsar\Audit\MutationContext;
use Pulsar\Extension\Admin\Domain\ActionResult;

/**
 * Contract for custom admin actions on resources.
 *
 * @psalm-api Implemented by user-land admin actions registered with
 *            the action registry; never resolved by name in framework code.
 * @api
 */
#[Api(since: '1.0.0')]
interface ActionInterface
{
    /**
     * Unique action name.
     */
    public function name(): string;

    /**
     * Human-readable label.
     */
    public function label(): string;

    /**
     * Execute the action on the given record IDs.
     *
     * @param list<string> $ids
     * @param array<string, mixed> $parameters
     */
    public function execute(
        DataResourceInterface $resource,
        array $ids,
        array $parameters,
        MutationContext $context,
    ): ActionResult;
}
