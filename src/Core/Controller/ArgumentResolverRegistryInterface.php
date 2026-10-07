<?php

declare(strict_types=1);

namespace Pulsar\Core\Controller;

use Pulsar\Api\Api;

/**
 * Registration side of the kernel's argument-resolver chain.
 *
 * Bound in the container by the kernel before any wiring runs, so a wiring, a
 * project bootstrap or an extension can contribute a resolver without
 * depending on the concrete chain — the same split as
 * {@see \Pulsar\Http\Middleware\MiddlewarePipelineInterface}.
 * @api
 */
#[Api(since: '1.0.0-rc.11')]
interface ArgumentResolverRegistryInterface
{
    /**
     * Append a resolver.
     *
     * There is no "add first" and there is deliberately no priority argument.
     * Where two resolvers claim one parameter name, an ordinary claim is decided
     * by registration order (earlier wins) and a {@see SealedArgument} is decided
     * by the claim itself, whatever the order — so a value that must not be
     * displaced is protected by the resolver that produced it rather than by
     * where the composition root happened to put it. A knob here could only
     * relitigate the first case, which is the one where the two candidates are
     * interchangeable, while inviting integrators to reach for it in the second,
     * where it would not help: the framework's own bound-model resolver is
     * registered last by construction and no ordering call can change that.
     */
    public function add(HandlerArgumentResolverInterface $resolver): void;
}
