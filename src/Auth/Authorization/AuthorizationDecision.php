<?php

declare(strict_types=1);

namespace Pulsar\Auth\Authorization;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

use function number_format;

/**
 * One decision {@see Gate::allows()} reached, as an auditable record of it.
 *
 * The Gate builds exactly one of these per decision and hands it to its
 * {@see AuthorizationDecisionSinkInterface}. It carries what an assessor asks
 * for — who, what permission, over which resource, allowed or refused, on what
 * grounds, and when — and nothing that would make constructing it expensive:
 * the object is allocated on the authorization path of every request, so its
 * cost is the framework's cost.
 *
 * That is why the instant is a Unix timestamp rather than a
 * `DateTimeImmutable`. Constructing a `DateTimeImmutable` costs roughly a
 * quarter of the whole RBAC evaluation it would be attached to, and every
 * reader of this field is a sink formatting the value for a record it is about
 * to write — off the decision path, where the conversion is free.
 * {@see decidedAt()} performs it.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AuthorizationDecision
{
    /**
     * @param string      $identityId    Identity the decision was made about.
     * @param string      $permission    Permission that was asked for.
     * @param string|null $resource      Resource named by the policy context, if any.
     * @param bool        $allowed       True for a grant, false for a refusal.
     * @param string      $reason        Ground the Gate decided on: `super-role`,
     *                                   `RBAC`, `ABAC`, `ABAC-deny`, `default-deny`.
     * @param float       $decidedAtUnix Unix timestamp with microsecond fraction,
     *                                   as returned by `microtime(true)`.
     */
    public function __construct(
        public string $identityId,
        public string $permission,
        public ?string $resource,
        public bool $allowed,
        public string $reason,
        public float $decidedAtUnix,
    ) {}

    /**
     * The instant the decision was reached, in UTC.
     *
     * This is the decision's own time, which is not the time its record is
     * written: a sink that batches writes stamps its audit entries when it
     * flushes. A reader comparing the two is reading queue latency, not a
     * discrepancy.
     */
    #[NoDiscard]
    public function decidedAt(): DateTimeImmutable
    {
        $instant = DateTimeImmutable::createFromFormat(
            'U.u',
            number_format($this->decidedAtUnix, 6, '.', ''),
        );

        // `U.u` accepts everything `number_format` produces for a timestamp in
        // range, so the fallback is unreachable in practice. It exists because
        // the alternative on a false return is a record with no time on it,
        // and a second of precision is worth more than that.
        return $instant === false
            ? new DateTimeImmutable('@' . (int) $this->decidedAtUnix)
            : $instant;
    }
}
