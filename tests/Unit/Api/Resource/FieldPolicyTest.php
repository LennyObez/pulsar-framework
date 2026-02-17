<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Resource\FieldPolicy;
use Pulsar\Security\Compliance\DataClassification;

#[CoversClass(FieldPolicy::class)]
final class FieldPolicyTest extends TestCase
{
    #[Test]
    public function constructs_with_required_fields_only(): void
    {
        $policy = new FieldPolicy(name: 'email', propertyName: 'email');

        self::assertSame('email', $policy->name);
        self::assertSame('email', $policy->propertyName);
        self::assertSame(DataClassification::Public, $policy->classification);
        self::assertSame([], $policy->requiredPermissions);
        self::assertSame([], $policy->requiredRoles);
        self::assertFalse($policy->filterable);
        self::assertSame([], $policy->filterOperators);
        self::assertFalse($policy->sortable);
    }

    #[Test]
    public function constructs_with_all_fields(): void
    {
        $policy = new FieldPolicy(
            name: 'ssn',
            propertyName: 'socialSecurityNumber',
            classification: DataClassification::Restricted,
            requiredPermissions: ['view:pii'],
            requiredRoles: ['admin', 'compliance'],
            filterable: true,
            filterOperators: ['eq', 'neq'],
            sortable: true,
        );

        self::assertSame('ssn', $policy->name);
        self::assertSame('socialSecurityNumber', $policy->propertyName);
        self::assertSame(DataClassification::Restricted, $policy->classification);
        self::assertSame(['view:pii'], $policy->requiredPermissions);
        self::assertSame(['admin', 'compliance'], $policy->requiredRoles);
        self::assertTrue($policy->filterable);
        self::assertSame(['eq', 'neq'], $policy->filterOperators);
        self::assertTrue($policy->sortable);
    }

    #[Test]
    public function requires_authorization_false_when_no_permissions_or_roles(): void
    {
        $policy = new FieldPolicy(name: 'name', propertyName: 'name');

        self::assertFalse($policy->requiresAuthorization());
    }

    #[Test]
    public function requires_authorization_true_with_permissions(): void
    {
        $policy = new FieldPolicy(
            name: 'salary',
            propertyName: 'salary',
            requiredPermissions: ['hr:view-salary'],
        );

        self::assertTrue($policy->requiresAuthorization());
    }

    #[Test]
    public function requires_authorization_true_with_roles(): void
    {
        $policy = new FieldPolicy(
            name: 'audit_log',
            propertyName: 'auditLog',
            requiredRoles: ['admin'],
        );

        self::assertTrue($policy->requiresAuthorization());
    }

    #[Test]
    public function requires_authorization_true_with_both(): void
    {
        $policy = new FieldPolicy(
            name: 'classified',
            propertyName: 'classified',
            requiredPermissions: ['view:classified'],
            requiredRoles: ['security-officer'],
        );

        self::assertTrue($policy->requiresAuthorization());
    }
}
