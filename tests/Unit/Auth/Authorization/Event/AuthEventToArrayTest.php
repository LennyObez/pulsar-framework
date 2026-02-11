<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Authorization\Event;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Authorization\Event\AuthenticationFailed;
use Pulsar\Auth\Authorization\Event\AuthenticationSucceeded;
use Pulsar\Auth\Authorization\Event\AuthorizationDenied;
use Pulsar\Auth\Authorization\Event\AuthorizationGranted;
use Pulsar\Auth\Authorization\Event\PrivilegeEscalated;
use Pulsar\Auth\Authorization\Event\StepUpAuthRequired;

#[CoversClass(AuthenticationFailed::class)]
#[CoversClass(AuthenticationSucceeded::class)]
#[CoversClass(AuthorizationDenied::class)]
#[CoversClass(AuthorizationGranted::class)]
#[CoversClass(PrivilegeEscalated::class)]
#[CoversClass(StepUpAuthRequired::class)]
final class AuthEventToArrayTest extends TestCase
{
    #[Test]
    public function authenticationFailedToArrayContainsAllKeys(): void
    {
        $ts = new DateTimeImmutable('2025-03-07T10:00:00.000000+00:00', new DateTimeZone('UTC'));

        $event = new AuthenticationFailed(
            attemptedIdentity: 'attacker@suspicious.net',
            guardName: 'session',
            failureReason: 'invalid_credentials',
            correlationId: 'corr-fail-001',
            nonce: 'abc123def456',
            occurredAt: $ts,
        );

        $array = $event->toArray();

        self::assertSame('attacker@suspicious.net', $array['attempted_identity']);
        self::assertSame('session', $array['guard_name']);
        self::assertSame('invalid_credentials', $array['failure_reason']);
        self::assertSame('corr-fail-001', $array['correlation_id']);
        self::assertSame('abc123def456', $array['nonce']);
        self::assertSame('2025-03-07T10:00:00.000000+00:00', $array['occurred_at']);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function authenticationFailedFromArrayHandlesMissingFields(): void
    {
        $event = AuthenticationFailed::fromArray([]);

        self::assertSame('', $event->attemptedIdentity);
        self::assertSame('', $event->guardName);
        self::assertSame('', $event->failureReason);
        self::assertSame('', $event->correlationId);
        self::assertSame('', $event->nonce);
    }

    #[Test]
    public function authenticationSucceededToArrayContainsAllKeys(): void
    {
        $ts = new DateTimeImmutable('2025-03-07T10:05:00.000000+00:00', new DateTimeZone('UTC'));

        $event = new AuthenticationSucceeded(
            identityId: 'user-12345',
            guardName: 'token',
            method: 'bearer_token',
            correlationId: 'corr-succ-001',
            nonce: 'nonce-hex-value',
            occurredAt: $ts,
        );

        $array = $event->toArray();

        self::assertSame('user-12345', $array['identity_id']);
        self::assertSame('token', $array['guard_name']);
        self::assertSame('bearer_token', $array['method']);
        self::assertSame('corr-succ-001', $array['correlation_id']);
        self::assertSame('nonce-hex-value', $array['nonce']);
        self::assertSame('2025-03-07T10:05:00.000000+00:00', $array['occurred_at']);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function authorizationDeniedToArrayContainsAllKeys(): void
    {
        $ts = new DateTimeImmutable('2025-03-07T10:10:00.000000+00:00', new DateTimeZone('UTC'));

        $event = new AuthorizationDenied(
            identityId: 'user-low-perms',
            permission: 'admin.users.delete',
            resource: '/admin/users/42',
            denialReason: 'insufficient_privileges',
            correlationId: 'corr-denied-001',
            nonce: 'denied-nonce',
            occurredAt: $ts,
        );

        $array = $event->toArray();

        self::assertSame('user-low-perms', $array['identity_id']);
        self::assertSame('admin.users.delete', $array['permission']);
        self::assertSame('/admin/users/42', $array['resource']);
        self::assertSame('insufficient_privileges', $array['denial_reason']);
        self::assertSame('corr-denied-001', $array['correlation_id']);
        self::assertSame('denied-nonce', $array['nonce']);
        self::assertSame('2025-03-07T10:10:00.000000+00:00', $array['occurred_at']);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function authorizationGrantedToArrayContainsAllKeys(): void
    {
        $ts = new DateTimeImmutable('2025-03-07T10:15:00.000000+00:00', new DateTimeZone('UTC'));

        $event = new AuthorizationGranted(
            identityId: 'user-admin',
            permission: 'reports.view',
            resource: '/reports/quarterly',
            grantReason: 'role_match',
            correlationId: 'corr-grant-001',
            nonce: 'granted-nonce',
            occurredAt: $ts,
        );

        $array = $event->toArray();

        self::assertSame('user-admin', $array['identity_id']);
        self::assertSame('reports.view', $array['permission']);
        self::assertSame('/reports/quarterly', $array['resource']);
        self::assertSame('role_match', $array['grant_reason']);
        self::assertSame('corr-grant-001', $array['correlation_id']);
        self::assertSame('granted-nonce', $array['nonce']);
        self::assertSame('2025-03-07T10:15:00.000000+00:00', $array['occurred_at']);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function privilegeEscalatedToArrayContainsAllKeys(): void
    {
        $ts = new DateTimeImmutable('2025-03-07T10:20:00.000000+00:00', new DateTimeZone('UTC'));

        $event = new PrivilegeEscalated(
            identityId: 'user-escalate',
            fromRoles: ['user'],
            toRoles: ['user', 'admin'],
            reason: 'Approved by security officer',
            correlationId: 'corr-esc-001',
            nonce: 'esc-nonce',
            occurredAt: $ts,
        );

        $array = $event->toArray();

        self::assertSame('user-escalate', $array['identity_id']);
        self::assertSame(['user'], $array['from_roles']);
        self::assertSame(['user', 'admin'], $array['to_roles']);
        self::assertSame('Approved by security officer', $array['reason']);
        self::assertSame('corr-esc-001', $array['correlation_id']);
        self::assertSame('esc-nonce', $array['nonce']);
        self::assertSame('2025-03-07T10:20:00.000000+00:00', $array['occurred_at']);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function stepUpAuthRequiredToArrayContainsAllKeys(): void
    {
        $ts = new DateTimeImmutable('2025-03-07T10:25:00.000000+00:00', new DateTimeZone('UTC'));

        $event = new StepUpAuthRequired(
            identityId: 'user-step-up',
            permission: 'wire_transfer.execute',
            trustScore: 0.45,
            requiredLevel: 'mfa',
            correlationId: 'corr-step-001',
            nonce: 'step-nonce',
            occurredAt: $ts,
        );

        $array = $event->toArray();

        self::assertSame('user-step-up', $array['identity_id']);
        self::assertSame('wire_transfer.execute', $array['permission']);
        self::assertSame(0.45, $array['trust_score']);
        self::assertSame('mfa', $array['required_level']);
        self::assertSame('corr-step-001', $array['correlation_id']);
        self::assertSame('step-nonce', $array['nonce']);
        self::assertSame('2025-03-07T10:25:00.000000+00:00', $array['occurred_at']);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function authenticationFailedFromArrayHandlesNonStringValues(): void
    {
        $event = AuthenticationFailed::fromArray([
            'attempted_identity' => 12345,
            'guard_name' => null,
            'failure_reason' => false,
            'correlation_id' => [],
            'nonce' => 0,
        ]);

        self::assertSame('', $event->attemptedIdentity);
        self::assertSame('', $event->guardName);
        self::assertSame('', $event->failureReason);
        self::assertSame('', $event->correlationId);
        self::assertSame('', $event->nonce);
    }

    #[Test]
    public function authenticationSucceededFromArrayHandlesMissingFields(): void
    {
        $event = AuthenticationSucceeded::fromArray([]);

        self::assertSame('', $event->identityId);
        self::assertSame('', $event->guardName);
        self::assertSame('', $event->method);
    }

    #[Test]
    public function authorizationDeniedFromArrayHandlesMissingFields(): void
    {
        $event = AuthorizationDenied::fromArray([]);

        self::assertSame('', $event->identityId);
        self::assertSame('', $event->permission);
        self::assertNull($event->resource);
        self::assertSame('', $event->denialReason);
    }

    #[Test]
    public function authorizationGrantedFromArrayHandlesMissingFields(): void
    {
        $event = AuthorizationGranted::fromArray([]);

        self::assertSame('', $event->identityId);
        self::assertSame('', $event->permission);
        self::assertNull($event->resource);
        self::assertSame('', $event->grantReason);
    }

    #[Test]
    public function privilegeEscalatedFromArrayHandlesMissingFields(): void
    {
        $event = PrivilegeEscalated::fromArray([]);

        self::assertSame('', $event->identityId);
        self::assertSame([], $event->fromRoles);
        self::assertSame([], $event->toRoles);
        self::assertSame('', $event->reason);
    }

    #[Test]
    public function stepUpAuthRequiredFromArrayHandlesMissingFields(): void
    {
        $event = StepUpAuthRequired::fromArray([]);

        self::assertSame('', $event->identityId);
        self::assertSame('', $event->permission);
        self::assertSame(0.0, $event->trustScore);
        self::assertSame('', $event->requiredLevel);
    }

    #[Test]
    public function stepUpAuthRequiredFromArrayConvertsIntTrustScore(): void
    {
        $event = StepUpAuthRequired::fromArray([
            'trust_score' => 1,
            'occurred_at' => '2025-01-01T00:00:00.000000+00:00',
        ]);

        self::assertSame(1.0, $event->trustScore);
    }
}
