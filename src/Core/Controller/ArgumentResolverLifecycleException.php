<?php

declare(strict_types=1);

namespace Pulsar\Core\Controller;

use NoDiscard;
use Pulsar\Api\Internal;
use RuntimeException;

/**
 * Something asked an {@see ArgumentResolverChain} for a second lifecycle handle.
 *
 * The handle carries the right to replace every resolver on the chain, which is
 * the right to remove a {@see SealedArgument} claim by removing the resolver that
 * makes it. It is issued once, to the {@see \Pulsar\Core\Kernel} constructor,
 * which runs before any wiring, extension or route file — so a second request
 * for it always comes from code that ran later than the kernel, and there is no
 * legitimate caller in that position.
 *
 * Failing here rather than returning a second handle is what makes the refusal
 * visible: the attempt names itself in a stack trace instead of silently
 * succeeding and taking effect on the next shutdown.
 */
#[Internal(reason: 'Refusal raised by ArgumentResolverChain::issueLifecycle()')]
final class ArgumentResolverLifecycleException extends RuntimeException
{
    #[NoDiscard]
    public static function alreadyIssued(): self
    {
        return new self(
            'The argument-resolver chain has already issued its lifecycle handle. '
            . 'Snapshot and restore belong to the kernel boot/shutdown cycle and are issued once, to the kernel that '
            . 'owns the chain; contribute resolvers through ArgumentResolverRegistryInterface::add() instead. '
            . 'If you are constructing a second kernel, give it a chain of its own — two kernels sharing one chain '
            . 'stack their resolvers on top of each other whether or not this succeeds.',
        );
    }
}
