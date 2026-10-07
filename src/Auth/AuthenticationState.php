<?php

declare(strict_types=1);

namespace Pulsar\Auth;

use Fiber;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Runtime\ResettableInterface;
use stdClass;
use WeakMap;

/**
 * Where authentication publishes its result, for the frames that must not read
 * it off the request.
 *
 * ## The channel this replaces, and why it was not one
 *
 * The caller used to be read from the `_identity` or `identity` REQUEST
 * ATTRIBUTE. A PSR-7 attribute is writable by every frame in the pipeline —
 * application middleware, a route middleware alias, an extension, anything an
 * extension prepends — and the binding layer authorized against whatever it
 * found there. So "who is calling" was decided by whoever wrote last, and the
 * `#[Internal]` authorization hook was answering a question an arbitrary frame
 * had set up. Those attributes are still WRITTEN, because roughly thirty
 * extension controllers read them, but nothing in the framework decides
 * authorization from them any more: they are an output of the auth stack, never
 * an input to it.
 *
 * {@see Middleware\AuthenticationMiddleware}, which the composition root pipes
 * globally, is the only framework frame that publishes here. It publishes the
 * lazy {@see SecurityContext} it builds from the container's
 * {@see AuthManagerInterface}, so this holder carries the framework's own
 * answer and carries it MEMOISED — the guards run at most once per request
 * however many frames ask. Reaching past it to the manager instead would double
 * the session read or the token verification on every `auth`-guarded route.
 *
 * ## What this does and does not claim
 *
 * It is not a capability. The class is `#[Internal]`, which the boundary check
 * enforces: no extension may import it, and the composition root — the only
 * caller that may — is where an application already decides which
 * {@see AuthManagerInterface} and which {@see Authorization\GateInterface} the
 * whole stack runs on. Anything holding the container can already replace
 * those, and no arrangement of this class changes that. What it does close is
 * the case the attribute made trivial: a frame INSIDE the request pipeline,
 * with no container access, naming the caller.
 *
 * ## Per-Fiber, and reset between requests
 *
 * Storage is keyed by `Fiber::getCurrent()` — or a stable `$rootKey` outside any
 * Fiber — in a `WeakMap`, the shape {@see \Pulsar\Context\RequestContextHolder}
 * established. ADR-0010 guarantees that individual request handling is
 * sequential, so under today's runtimes every request lands on the root key;
 * the Fiber key costs nothing and is already correct if that guarantee is ever
 * relaxed. It is deliberately NOT the shape {@see \Pulsar\Routing\Binding\BindingProvenance}
 * uses — that one is a process-wide pass counter and is the property ADR-0010's
 * sequential guarantee is actually load-bearing for.
 *
 * The holder is registered with the {@see \Pulsar\Runtime\RequestResetRegistry}
 * by RuntimeWiring, and that registration is not optional. A resident worker
 * reuses the root key for every request it serves, so an identity left behind
 * is the NEXT caller's identity — a cross-request identity leak, which is worse
 * than the attribute this class replaces. {@see resetRequestState()} is what
 * makes the publication request-scoped.
 */
#[Internal(reason: 'Authentication publishes here; composition root and the auth middleware only')]
final class AuthenticationState implements ResettableInterface
{
    /** @var WeakMap<object, SecurityContext> Per-Fiber (or root) established security context. */
    private WeakMap $contexts;

    private readonly stdClass $rootKey;

    public function __construct()
    {
        /** @var WeakMap<object, SecurityContext> $map */
        $map = new WeakMap();
        $this->contexts = $map;
        $this->rootKey = new stdClass();
    }

    /**
     * Publish the context authentication established for this request.
     *
     * Last write wins, and there is exactly one framework writer. The
     * alternative — refusing a second publication — would turn a re-entrant
     * dispatch into a hard failure, and it would buy nothing: a caller able to
     * reach this object can reach the {@see AuthManagerInterface} behind it too.
     */
    public function establish(SecurityContext $context): void
    {
        $this->contexts[$this->currentKey()] = $context;
    }

    /**
     * The established context, or null when authentication has not run.
     *
     * Null means the auth stack is not composed, or has not reached this
     * request yet. It never means "anonymous": an anonymous caller has an
     * established context whose identity answers `isAuthenticated()` with
     * false.
     */
    public function context(): ?SecurityContext
    {
        return $this->contexts[$this->currentKey()] ?? null;
    }

    /**
     * The caller, when there is an authenticated one.
     *
     * Resolving here is the point: the globally piped middleware publishes a
     * LAZY context and consults no guard, so on a route carrying no `auth`
     * alias nothing has asked who is calling by the time a bound model needs a
     * subject. This asks, once, through the memo.
     *
     * An unauthenticated result is null rather than an
     * {@see Identity\AnonymousIdentity}. The question is "who may I authorize
     * against", and the anonymous null object answers it with an object — which
     * is how a request with nobody behind it became a request with an identity
     * present.
     */
    public function authenticatedIdentity(): ?IdentityInterface
    {
        $identity = $this->context()?->identity();

        return $identity?->isAuthenticated() === true ? $identity : null;
    }

    /**
     * Drop this request's publication.
     *
     * The only way to unpublish, and it is the runtime's to call. A resident
     * worker reuses one key for every request it serves, so a publication left
     * behind is the NEXT caller's identity — which is why RuntimeWiring
     * registers this holder and why nothing else needs a second door to it.
     */
    #[Override]
    public function resetRequestState(): void
    {
        unset($this->contexts[$this->currentKey()]);
    }

    private function currentKey(): object
    {
        return Fiber::getCurrent() ?? $this->rootKey;
    }
}
