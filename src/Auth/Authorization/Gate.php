<?php

declare(strict_types=1);

namespace Pulsar\Auth\Authorization;

use Fiber;
use Override;
use Psr\Log\LoggerInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use stdClass;
use Throwable;
use WeakMap;

use function count;
use function in_array;
use function microtime;

/**
 * Authorization gate combining RBAC and ABAC strategies.
 *
 * Evaluation order:
 * 1. Super-role bypass (configurable roles that skip all checks)
 * 2. ABAC policies: explicit deny short-circuits immediately
 * 3. RBAC: role→permission check via RoleRegistry
 * 4. ABAC policies: explicit allow can grant access without RBAC match
 * 5. Default: deny
 *
 * ## Why the decision record does not go through the event dispatcher
 *
 * Every decision this class reaches is handed to an
 * {@see AuthorizationDecisionSinkInterface} when the composition root supplies
 * one, because "show me the access decision" is the first question an assessor
 * asks and a decision nobody wrote down is not an answer.
 *
 * It used to be announced by dispatching `AuthorizationGranted` /
 * `AuthorizationDenied` through the application's `EventDispatcherInterface`,
 * and that mechanism was wrong in three ways that a dispatcher cannot be
 * configured out of:
 *
 * - **It put the application inside the decision.** Every listener registered
 *   anywhere ran within `allows()`, and `EventDispatcher` re-throws the first
 *   listener error after its loop — so an unrelated listener throwing turned a
 *   grant into a `500`, and a slow one made every authorization check slow.
 *   Measured on one machine, wiring the dispatcher took a decision from 4.0 µs
 *   to 58 µs, and to 60 µs with twelve unrelated listeners also registered.
 * - **Re-entry was real.** A listener asking the Gate a question re-entered
 *   `allows()` → dispatch → the same listener. Nothing bounded that except
 *   `StormGuard` eventually throwing, which unwound the decision and left the
 *   audit trail holding the nested records but not the outer one.
 * - **The audit trail depended on an application's listener registry.** What
 *   was recorded, and whether anything was, moved with whatever else the
 *   application had subscribed.
 *
 * The sink is a single named collaborator instead, chosen by the composition
 * root. Nothing else runs inside a decision. The events remain part of the
 * public surface and are still constructed — by the sink, after the decision
 * has been returned.
 */
final class Gate implements GateInterface
{
    /**
     * Ceiling on decisions a sink may cause while one is being recorded.
     *
     * Both the nested queue's length and the number of nested records drained
     * per outer decision. The two together are what makes a sink that asks the
     * Gate a question for every record it takes terminate instead of looping:
     * the queue cannot outgrow the ceiling, and the drain stops at it even if
     * draining keeps refilling it.
     */
    private const int MAX_NESTED_DECISIONS = 16;

    /** @var list<PolicyInterface> */
    private array $policies = [];

    /**
     * The open {@see record()} frame of each Fiber, and of the code that runs
     * outside every Fiber.
     *
     * Keyed the way this framework keys per-Fiber state everywhere else — see
     * {@see \Pulsar\Context\RequestContextHolder}, {@see \Pulsar\Tenancy\TenantContext},
     * {@see \Pulsar\Http\RouteContext} — by `Fiber::getCurrent()` in a `WeakMap`,
     * with a stable root key standing in outside any Fiber. A completed Fiber's
     * slot is reclaimed when the Fiber is collected, so an abandoned recording
     * cannot pin memory.
     *
     * @var WeakMap<object, DecisionRecordingState>
     */
    private WeakMap $recordings;

    /** Stands in for `Fiber::getCurrent()` when there is no current Fiber. */
    private readonly stdClass $rootKey;

    /**
     * @param list<string> $superRoles Roles that bypass all permission checks
     */
    public function __construct(
        private readonly RoleRegistryInterface $roleRegistry,
        private readonly array $superRoles = [],
        private readonly ?AuthorizationDecisionSinkInterface $decisionSink = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        /** @var WeakMap<object, DecisionRecordingState> $recordings */
        $recordings = new WeakMap();
        $this->recordings = $recordings;
        $this->rootKey = new stdClass();
    }

    /**
     * Register an ABAC policy.
     */
    public function addPolicy(PolicyInterface $policy): void
    {
        $this->policies[] = $policy;
    }

    #[Override]
    public function allows(IdentityInterface $identity, string $permission, ?PolicyContext $context = null): bool
    {
        // The resource is read once, before the policy context is (or is not)
        // built, because it is the only field of the context the decision
        // record needs and it must read the same on every branch below.
        $resource = $context?->resource;

        // 1. Super-role bypass. The membership test is skipped outright when no
        // super roles are configured — the default — rather than walking the
        // identity's roles to compare each against an empty list.
        if ($this->superRoles !== [] && $this->hasSuperRole($identity)) {
            $this->record($identity, $permission, $resource, true, 'super-role');

            return true;
        }

        // 2. ABAC. Walk the policy list exactly once and capture every verdict:
        // evaluating N policies separately for explicit-deny and for
        // explicit-allow would cost 2N evaluations. An explicit deny
        // short-circuits immediately, but an explicit allow is only remembered
        // until after the RBAC check runs, so RBAC stays the primary grant path.
        //
        // A PolicyContext is observable only by a policy, so with none
        // registered — the common case — the default one is never built.
        $explicitAllow = false;

        if ($this->policies !== []) {
            $context ??= new PolicyContext(permission: $permission);

            foreach ($this->policies as $policy) {
                $result = $policy->evaluate($identity, $context);

                if ($result === false) {
                    $this->record($identity, $permission, $resource, false, 'ABAC-deny');

                    return false;
                }

                if ($result === true) {
                    $explicitAllow = true;
                }
            }
        }

        // 3. RBAC: check role→permission.
        foreach ($this->roleRegistry->permissionsForRoles($identity->roles()) as $candidate) {
            if ($candidate->matches($permission)) {
                $this->record($identity, $permission, $resource, true, 'RBAC');

                return true;
            }
        }

        // 4. ABAC explicit allow (granted without RBAC match).
        if ($explicitAllow) {
            $this->record($identity, $permission, $resource, true, 'ABAC');

            return true;
        }

        // 5. Default: deny.
        $this->record($identity, $permission, $resource, false, 'default-deny');

        return false;
    }

    #[Override]
    public function denies(IdentityInterface $identity, string $permission, ?PolicyContext $context = null): bool
    {
        return !$this->allows($identity, $permission, $context);
    }

    private function hasSuperRole(IdentityInterface $identity): bool
    {
        foreach ($identity->roles() as $role) {
            if (in_array($role, $this->superRoles, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hand the settled decision to the sink.
     *
     * Called once per decision, after the outcome is known and immediately
     * before `allows()` returns it. Two things are guaranteed here and nowhere
     * else, because a sink is application-supplied code:
     *
     * **A sink failure never changes a decision.** Everything the sink raises
     * is caught and reported at `critical` — loud in the application log,
     * invisible to the caller. The alternative is a transient audit fault
     * turning an allowed request into a `500`.
     *
     * **A sink cannot recurse through the Gate.** A sink that asks `allows()` a
     * question re-enters this method. The nested decision is queued rather than
     * handed straight to the sink, and drained by the outer call once the sink
     * has returned — so it is recorded, in order, without a second frame of
     * `record()` ever being open. Beyond {@see MAX_NESTED_DECISIONS} the queue
     * refuses further nesting and says so at `critical`: a sink that generates
     * a decision per record is a defect, and the Gate's job is to keep serving
     * requests while it is fixed rather than to let it run.
     *
     * **The guard is scoped to the call stack that opened it.** It is held in a
     * `WeakMap` keyed by the current Fiber ({@see $recordings}), not in a
     * property of the Gate, because this framework interleaves Fibers on one
     * worker and the sink it binds reaches an `AuditLogger` whose chain lock
     * suspends the Fiber cooperatively. With the guard on the instance, a
     * decision reached by Fiber B while Fiber A's sink was suspended read as B
     * re-entering A's record: B's decision was queued against A, drained by A,
     * counted against A's ceiling, named as A's in the `critical` above, and
     * dropped entirely by A's reset once that ceiling was reached. Per Fiber,
     * "a state exists for me" can only mean "this frame is nested inside my own
     * outer one", which is what re-entry means and what a shared flag could not
     * say. Two Fibers deciding at once now each open, drain and close their own
     * record.
     */
    private function record(
        IdentityInterface $identity,
        string $permission,
        ?string $resource,
        bool $allowed,
        string $reason,
    ): void {
        if ($this->decisionSink === null) {
            return;
        }

        $decision = new AuthorizationDecision(
            identityId: $identity->id(),
            permission: $permission,
            resource: $resource,
            allowed: $allowed,
            reason: $reason,
            decidedAtUnix: microtime(true),
        );

        $key = Fiber::getCurrent() ?? $this->rootKey;
        $open = $this->recordings[$key] ?? null;

        if ($open !== null) {
            if (count($open->nested) < self::MAX_NESTED_DECISIONS) {
                $open->queue($decision);

                return;
            }

            $open->refuse();

            return;
        }

        $state = new DecisionRecordingState();
        $this->recordings[$key] = $state;
        $budget = self::MAX_NESTED_DECISIONS;

        try {
            $this->decisionSink->record($decision);

            while ($budget > 0) {
                $next = $state->shift();

                if ($next === null) {
                    break;
                }

                $budget--;
                $this->decisionSink->record($next);
            }
        } catch (Throwable $e) {
            $this->reportCritical('Authorization decision could not be recorded', [
                'permission' => $permission,
                'allowed' => $allowed,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        } finally {
            $refused = $state->refused + count($state->nested);

            // Closing the frame is removing the state, not clearing fields on a
            // shared one: another Fiber's queue is not this frame's to empty.
            unset($this->recordings[$key]);

            if ($refused > 0) {
                $this->reportCritical(
                    'Authorization decision sink re-entered the Gate; nested decisions were refused',
                    [
                        'permission' => $permission,
                        'refused' => $refused,
                        'ceiling' => self::MAX_NESTED_DECISIONS,
                    ],
                );
            }
        }
    }

    /**
     * Say what went wrong, without the saying of it changing the decision.
     *
     * The logger is application-supplied, and both places that call this are
     * reporting a failure they have already contained: one from a `catch`, one
     * from a `finally`. A logger that throws from either would surface out of
     * `allows()` and turn a settled grant into a `500` — the exact defect the
     * sink is caught to prevent, moved one frame further out, and from the
     * `finally` it would additionally replace the exception being unwound.
     *
     * A failed report is dropped. There is nothing left to report it to: the
     * only channel for saying "the log could not be written" is the log. The
     * decision has already been reached correctly and returned, which is the
     * property this method exists to protect.
     *
     * @param array<string, scalar> $context
     */
    private function reportCritical(string $message, array $context): void
    {
        if ($this->logger === null) {
            return;
        }

        try {
            $this->logger->critical($message, $context);
        } catch (Throwable) {
            // Deliberately empty: see above.
        }
    }
}
