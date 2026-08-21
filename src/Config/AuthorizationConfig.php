<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Typed configuration DTO for authorization settings.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AuthorizationConfig implements ReportsUnknownKeys
{
    /** Keys read from the `auth.authorization` sub-array of config/security.php. */
    private const array KNOWN_KEYS = ['roles', 'super_roles', 'decision_audit_buffer'];

    /**
     * Decisions the audit sink may hold.
     *
     * Matches {@see \Pulsar\Auth\Internal\Authorization\BufferedAuthorizationDecisionSink::DEFAULT_CAPACITY}.
     */
    public const int DEFAULT_DECISION_AUDIT_BUFFER = 1024;

    /**
     * @param array<string, array<string, mixed>> $roles Role definitions keyed by name
     * @param list<string> $superRoles Roles that bypass all permission checks
     * @param list<string> $unknownKeys Keys present in the raw `authorization` array
     *     that this DTO does not read — `super_role` written singular silently grants
     *     nobody the bypass the operator intended to grant.
     * @param int $decisionAuditBuffer How many authorization decisions the audit sink
     *     may hold. `1` writes each decision through as the Gate reaches it, at the
     *     cost of an HMAC-chained write inside every authorization check. Above `1`
     *     nothing is written inside a decision: the entries are chained at a drain
     *     point — the kernel's terminate event, a queue job ending, or the sink's
     *     destructor — which bounds what a hard process death can lose to the
     *     decisions of the unit of work in flight. The capacity is the memory ceiling
     *     behind those, not a batch size; reaching it means a drain point should have
     *     run and did not, and is reported at `critical`.
     */
    public function __construct(
        public array $roles = [],
        public array $superRoles = [],
        public array $unknownKeys = [],
        public int $decisionAuditBuffer = self::DEFAULT_DECISION_AUDIT_BUFFER,
    ) {}

    /**
     * @return list<string>
     */
    public function unknownConfigKeys(): array
    {
        return $this->unknownKeys;
    }

    /**
     * Build from a raw authorization config array.
     *
     * @param array{
     *     roles?: array<string, array<string, mixed>>,
     *     super_roles?: list<string>,
     *     decision_audit_buffer?: int,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            roles: $data['roles'] ?? [],
            superRoles: $data['super_roles'] ?? [],
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
            decisionAuditBuffer: $data['decision_audit_buffer'] ?? self::DEFAULT_DECISION_AUDIT_BUFFER,
        );
    }
}
