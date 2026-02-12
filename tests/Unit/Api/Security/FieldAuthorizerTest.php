<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Resource\FieldPolicy;
use Pulsar\Api\Security\ClearanceSnapshot;
use Pulsar\Api\Security\FieldAuthorizationResult;
use Pulsar\Api\Security\FieldAuthorizer;
use Pulsar\Security\Compliance\DataClassification;

#[CoversClass(FieldAuthorizer::class)]
final class FieldAuthorizerTest extends TestCase
{
    private FieldAuthorizer $authorizer;

    protected function setUp(): void
    {
        $this->authorizer = new FieldAuthorizer();
    }

    #[Test]
    public function publicFieldAllowedWithAnyClearance(): void
    {
        $policy = new FieldPolicy(name: 'id', propertyName: 'id');
        $clearance = ClearanceSnapshot::anonymous('anon');

        $result = $this->authorizer->authorize($policy, $clearance);

        self::assertSame(FieldAuthorizationResult::Allowed, $result);
    }

    #[Test]
    public function classificationDeniedForUnauthenticatedUser(): void
    {
        $policy = new FieldPolicy(
            name: 'email',
            propertyName: 'email',
            classification: DataClassification::Confidential,
        );
        $clearance = ClearanceSnapshot::anonymous('anon');

        $result = $this->authorizer->authorize($policy, $clearance);

        self::assertSame(FieldAuthorizationResult::Denied, $result);
    }

    #[Test]
    public function classificationRedactedForAuthenticatedUserBelowClearance(): void
    {
        $policy = new FieldPolicy(
            name: 'email',
            propertyName: 'email',
            classification: DataClassification::Confidential,
        );
        $clearance = new ClearanceSnapshot(
            snapshotId: 's1',
            maxClassification: DataClassification::Internal,
            permissions: [],
            roles: [],
            authenticated: true,
        );

        $result = $this->authorizer->authorize($policy, $clearance);

        self::assertSame(FieldAuthorizationResult::Redacted, $result);
    }

    #[Test]
    public function classificationAllowedWhenClearanceMeets(): void
    {
        $policy = new FieldPolicy(
            name: 'email',
            propertyName: 'email',
            classification: DataClassification::Confidential,
        );
        $clearance = new ClearanceSnapshot(
            snapshotId: 's2',
            maxClassification: DataClassification::Confidential,
            permissions: [],
            roles: [],
            authenticated: true,
        );

        $result = $this->authorizer->authorize($policy, $clearance);

        self::assertSame(FieldAuthorizationResult::Allowed, $result);
    }

    #[Test]
    public function permissionCheckDeniesWhenMissing(): void
    {
        $policy = new FieldPolicy(
            name: 'ssn',
            propertyName: 'ssn',
            requiredPermissions: ['users.view-ssn'],
        );
        $clearance = new ClearanceSnapshot(
            snapshotId: 's3',
            maxClassification: DataClassification::Restricted,
            permissions: ['users.read'],
            roles: [],
            authenticated: true,
        );

        $result = $this->authorizer->authorize($policy, $clearance);

        self::assertSame(FieldAuthorizationResult::Denied, $result);
    }

    #[Test]
    public function permissionCheckAllowsWhenPresent(): void
    {
        $policy = new FieldPolicy(
            name: 'ssn',
            propertyName: 'ssn',
            requiredPermissions: ['users.view-ssn'],
        );
        $clearance = new ClearanceSnapshot(
            snapshotId: 's4',
            maxClassification: DataClassification::Restricted,
            permissions: ['users.view-ssn', 'users.read'],
            roles: [],
            authenticated: true,
        );

        $result = $this->authorizer->authorize($policy, $clearance);

        self::assertSame(FieldAuthorizationResult::Allowed, $result);
    }

    #[Test]
    public function allPermissionsRequired(): void
    {
        $policy = new FieldPolicy(
            name: 'ssn',
            propertyName: 'ssn',
            requiredPermissions: ['users.view-ssn', 'compliance.pii'],
        );
        $clearance = new ClearanceSnapshot(
            snapshotId: 's5',
            maxClassification: DataClassification::Restricted,
            permissions: ['users.view-ssn'],
            roles: [],
            authenticated: true,
        );

        $result = $this->authorizer->authorize($policy, $clearance);

        self::assertSame(FieldAuthorizationResult::Denied, $result, 'Missing one of multiple required permissions');
    }

    #[Test]
    public function roleCheckDeniesWhenNoneMatch(): void
    {
        $policy = new FieldPolicy(
            name: 'notes',
            propertyName: 'notes',
            requiredRoles: ['admin', 'superadmin'],
        );
        $clearance = new ClearanceSnapshot(
            snapshotId: 's6',
            maxClassification: DataClassification::Restricted,
            permissions: [],
            roles: ['viewer', 'editor'],
            authenticated: true,
        );

        $result = $this->authorizer->authorize($policy, $clearance);

        self::assertSame(FieldAuthorizationResult::Denied, $result);
    }

    #[Test]
    public function roleCheckAllowsWhenAnyMatches(): void
    {
        $policy = new FieldPolicy(
            name: 'notes',
            propertyName: 'notes',
            requiredRoles: ['admin', 'superadmin'],
        );
        $clearance = new ClearanceSnapshot(
            snapshotId: 's7',
            maxClassification: DataClassification::Restricted,
            permissions: [],
            roles: ['editor', 'admin'],
            authenticated: true,
        );

        $result = $this->authorizer->authorize($policy, $clearance);

        self::assertSame(FieldAuthorizationResult::Allowed, $result);
    }

    #[Test]
    public function classificationCheckedBeforePermissions(): void
    {
        // Field requires Confidential classification AND a permission
        $policy = new FieldPolicy(
            name: 'ssn',
            propertyName: 'ssn',
            classification: DataClassification::Confidential,
            requiredPermissions: ['users.view-ssn'],
        );

        // User has Public clearance but has the permission
        $clearance = new ClearanceSnapshot(
            snapshotId: 's8',
            maxClassification: DataClassification::Public,
            permissions: ['users.view-ssn'],
            roles: [],
            authenticated: false,
        );

        $result = $this->authorizer->authorize($policy, $clearance);

        // Classification should deny before permission is checked
        self::assertSame(FieldAuthorizationResult::Denied, $result);
    }
}
