<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\WebAuthn\Contract;

use Pulsar\Api\Api;
use Pulsar\Extension\Auth\WebAuthn\Authenticator\AuthenticatorRecord;

/**
 * Repository for authenticator metadata and management.
 *
 * Provides CRUD operations for managing registered authenticators,
 * including human-friendly naming and listing.
 *
 * Implementations MUST write every mutation — {@see self::register()},
 * {@see self::rename()} and {@see self::revoke()} — to the audit chain via
 * {@see \Pulsar\Audit\AuditLoggerInterface} before the change takes effect.
 * Removing a second factor with no record is a control gap under SOC 2
 * CC6.1/CC7.2, ISO/IEC 27001 A.8.15 and HIPAA §164.312(b), and the interface
 * cannot enforce it in the type system, so it is stated here as a contract
 * obligation that a persistent implementation inherits along with the methods.
 * Reads are deliberately not auditable events: they run on every settings
 * render and would bury the mutations.
 * @api
 */
#[Api(since: '1.0.0')]
interface AuthenticatorRepositoryInterface
{
    /**
     * Find an authenticator record by credential ID.
     */
    public function findByCredentialId(string $credentialId): ?AuthenticatorRecord;

    /**
     * List all authenticators for a user.
     *
     * @return list<AuthenticatorRecord>
     */
    public function listByUserId(string $userId): array;

    /**
     * Register a new authenticator.
     *
     * Audited: adding a factor is as material to an account's security posture
     * as removing one.
     */
    public function register(AuthenticatorRecord $record): void;

    /**
     * Update an authenticator's display name.
     *
     * Audited: the entry names the previous and the new display name.
     */
    public function rename(string $credentialId, string $newName): void;

    /**
     * Revoke (deactivate) an authenticator.
     *
     * Does not delete the record, and writes a security event to the audit
     * chain: the record surviving in the store is not by itself an audit trail,
     * because nothing in it says who removed the factor or when.
     */
    public function revoke(string $credentialId): void;

    /**
     * Count active authenticators for a user.
     */
    public function countActive(string $userId): int;
}
