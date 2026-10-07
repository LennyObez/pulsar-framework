<?php

declare(strict_types=1);

namespace Pulsar\Core\Controller;

use InvalidArgumentException;
use Pulsar\Api\Api;

/**
 * A claim no other resolver may displace, whatever the registration order.
 *
 * Wrap a claimed value in this and {@see ArgumentResolverChain} delivers YOUR
 * value to that parameter: an unsealed claim on the same name loses whether it
 * was made by a resolver registered before you or after you. Position stops
 * deciding, which is the whole point — the resolver that produced the value is
 * the one that knows it must not be second-guessed, and it cannot see, let alone
 * choose, where on the chain it sits.
 *
 * ## What sealing asserts
 *
 * "This value was produced under a decision that outranks a naming collision."
 * The framework's own case is {@see \Pulsar\Routing\Binding\BoundModelArgumentResolver}:
 * every object it hands over was loaded, tenant-scoped and passed through an
 * authorization hook by {@see \Pulsar\Routing\Binding\ModelBindingMiddleware}
 * before the handler was reached. Substituting another object for it does not
 * produce a different value, it produces an UNAUTHORIZED one — on a parameter
 * whose type hint is exactly what makes the route look safe to an auditor.
 *
 * It is not a framework privilege. Any resolver may seal, because the property
 * that earns a seal — "I decided this, under a rule the caller cannot see" —
 * belongs to applications and extensions too: a resolver that returns the
 * subject of a signed request, or a payload a policy engine already validated,
 * is in the same position. Sealing is safe to open because a seal cannot silently
 * take a name from another seal; see the conflict rule below.
 *
 * ## Two seals on one name are refused, not ranked
 *
 * When two resolvers both seal the same parameter, each is asserting final
 * authority over one argument and the chain has no basis to prefer either.
 * Choosing the earlier one would reintroduce, at the exact point where it
 * matters most, the ordering this class exists to remove. So the chain throws
 * {@see ConflictingSealedArgumentException} and the request fails closed. That
 * is loud, it names the parameter and both resolvers, and it cannot be reached
 * by a route that was working: a second seal on a name only ever appears when
 * someone adds a resolver, and it appears on the first request that route
 * serves rather than as a wrong value months later.
 *
 * ## Delivery
 *
 * The chain unwraps exactly one layer, so the handler receives {@see $value} and
 * never this object. A `SealedArgument` whose value is itself a
 * `SealedArgument` is a construction mistake rather than a nested seal, and the
 * constructor refuses it: unwrapping one layer would hand the handler a wrapper
 * where it declared a domain type, and the resulting TypeError names neither the
 * resolver nor the parameter.
 *
 * `null` seals like anything else — a sealed null is a claim of null that no
 * later resolver can overwrite, the same distinction the unsealed map draws
 * between a key present with a null value and a key absent.
 * @api
 */
#[Api(since: '1.0.0-rc.11')]
final readonly class SealedArgument
{
    /**
     * @param mixed $value The value to deliver to the parameter this claim names.
     *
     * @throws InvalidArgumentException If $value is itself a SealedArgument.
     */
    public function __construct(public mixed $value)
    {
        if ($value instanceof self) {
            throw new InvalidArgumentException(
                'A SealedArgument must wrap the value delivered to the handler, not another SealedArgument.',
            );
        }
    }
}
