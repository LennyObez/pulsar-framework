<?php

declare(strict_types=1);

namespace Pulsar\Core\Controller;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function implode;
use function sprintf;

/**
 * Two resolvers sealed the same handler parameter.
 *
 * A {@see SealedArgument} says "this value was decided under a rule the caller
 * cannot see, and must not be displaced". Two of them on one name is not a
 * precedence question the chain can answer — both parties assert final
 * authority, and preferring the earlier registration would put the ordering back
 * exactly where sealing removed it. So the chain refuses to pick and the request
 * fails closed with a 500 rather than serving a handler one of the two values at
 * random.
 *
 * The failure is a composition mistake, not a request-shaped one: it depends
 * only on which resolvers are registered and which parameters a handler
 * declares, so it reproduces on the first request that reaches the route and on
 * every request after it. Resolve it by deciding which resolver owns the name
 * and unsealing — or removing — the claim from the other.
 * @api
 */
#[Api(since: '1.0.0-rc.11')]
final class ConflictingSealedArgumentException extends RuntimeException
{
    /**
     * @param list<string> $parameters The contested parameter names, in claim order.
     */
    #[NoDiscard]
    public static function alreadySealed(
        array $parameters,
        HandlerSignature $signature,
        string $resolver,
    ): self {
        return new self(sprintf(
            'Resolver [%s] sealed [$%s] on handler [%s::%s], which another resolver had already sealed. '
            . 'A sealed argument cannot be displaced, so two seals on one parameter have no resolution: '
            . 'decide which resolver owns the parameter and stop the other from sealing it.',
            $resolver,
            implode('], [$', $parameters),
            $signature->class,
            $signature->method,
        ));
    }
}
