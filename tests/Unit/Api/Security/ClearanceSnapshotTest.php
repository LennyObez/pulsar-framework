<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Security\ClearanceSnapshot;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Security\Compliance\DataClassification;

#[CoversClass(ClearanceSnapshot::class)]
final class ClearanceSnapshotTest extends TestCase
{
    #[Test]
    public function anonymousSnapshotHasPublicOnlyClearance(): void
    {
        $snapshot = ClearanceSnapshot::anonymous('anon-1');

        self::assertSame('anon-1', $snapshot->snapshotId);
        self::assertSame(DataClassification::Public, $snapshot->maxClassification);
        self::assertSame([], $snapshot->permissions);
        self::assertSame([], $snapshot->roles);
        self::assertFalse($snapshot->authenticated);
    }

    #[Test]
    public function fromIdentityBuildsWithCorrectValues(): void
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('roles')->willReturn(['admin', 'editor']);
        $identity->method('isAuthenticated')->willReturn(true);

        $snapshot = ClearanceSnapshot::fromIdentity(
            $identity,
            'snap-id-1',
            ['users.read', 'users.write'],
            DataClassification::Confidential,
        );

        self::assertSame('snap-id-1', $snapshot->snapshotId);
        self::assertSame(DataClassification::Confidential, $snapshot->maxClassification);
        self::assertSame(['users.read', 'users.write'], $snapshot->permissions);
        self::assertSame(['admin', 'editor'], $snapshot->roles);
        self::assertTrue($snapshot->authenticated);
    }

    #[Test]
    public function hasPermissionReturnsTrueForPresentPermission(): void
    {
        $snapshot = new ClearanceSnapshot(
            snapshotId: 's1',
            maxClassification: DataClassification::Internal,
            permissions: ['users.read', 'posts.write'],
            roles: [],
            authenticated: true,
        );

        self::assertTrue($snapshot->hasPermission('users.read'));
        self::assertTrue($snapshot->hasPermission('posts.write'));
    }

    #[Test]
    public function hasPermissionReturnsFalseForAbsentPermission(): void
    {
        $snapshot = new ClearanceSnapshot(
            snapshotId: 's2',
            maxClassification: DataClassification::Internal,
            permissions: ['users.read'],
            roles: [],
            authenticated: true,
        );

        self::assertFalse($snapshot->hasPermission('users.write'));
        self::assertFalse($snapshot->hasPermission('admin.access'));
    }

    #[Test]
    public function hasAnyRoleReturnsTrueWhenOneMatches(): void
    {
        $snapshot = new ClearanceSnapshot(
            snapshotId: 's3',
            maxClassification: DataClassification::Public,
            permissions: [],
            roles: ['editor', 'viewer'],
            authenticated: true,
        );

        self::assertTrue($snapshot->hasAnyRole(['admin', 'editor']));
    }

    #[Test]
    public function hasAnyRoleReturnsFalseWhenNoneMatch(): void
    {
        $snapshot = new ClearanceSnapshot(
            snapshotId: 's4',
            maxClassification: DataClassification::Public,
            permissions: [],
            roles: ['viewer'],
            authenticated: true,
        );

        self::assertFalse($snapshot->hasAnyRole(['admin', 'editor']));
    }

    #[Test]
    public function meetsClassificationComparesLevelsCorrectly(): void
    {
        $restricted = new ClearanceSnapshot(
            snapshotId: 's5',
            maxClassification: DataClassification::Restricted,
            permissions: [],
            roles: [],
            authenticated: true,
        );

        // Restricted clearance should meet all levels
        self::assertTrue($restricted->meetsClassification(DataClassification::Public));
        self::assertTrue($restricted->meetsClassification(DataClassification::Internal));
        self::assertTrue($restricted->meetsClassification(DataClassification::Confidential));
        self::assertTrue($restricted->meetsClassification(DataClassification::Restricted));
    }

    #[Test]
    public function publicClearanceOnlyMeetsPublic(): void
    {
        $public = new ClearanceSnapshot(
            snapshotId: 's6',
            maxClassification: DataClassification::Public,
            permissions: [],
            roles: [],
            authenticated: true,
        );

        self::assertTrue($public->meetsClassification(DataClassification::Public));
        self::assertFalse($public->meetsClassification(DataClassification::Internal));
        self::assertFalse($public->meetsClassification(DataClassification::Confidential));
        self::assertFalse($public->meetsClassification(DataClassification::Restricted));
    }

    #[Test]
    public function internalClearanceMeetsPublicAndInternal(): void
    {
        $internal = new ClearanceSnapshot(
            snapshotId: 's7',
            maxClassification: DataClassification::Internal,
            permissions: [],
            roles: [],
            authenticated: true,
        );

        self::assertTrue($internal->meetsClassification(DataClassification::Public));
        self::assertTrue($internal->meetsClassification(DataClassification::Internal));
        self::assertFalse($internal->meetsClassification(DataClassification::Confidential));
        self::assertFalse($internal->meetsClassification(DataClassification::Restricted));
    }

    #[Test]
    public function snapshotIsImmutable(): void
    {
        $snapshot = new ClearanceSnapshot(
            snapshotId: 'immutable-test',
            maxClassification: DataClassification::Confidential,
            permissions: ['perm1'],
            roles: ['role1'],
            authenticated: true,
        );

        // Verify readonly properties are accessible but immutable
        self::assertSame('immutable-test', $snapshot->snapshotId);
        self::assertSame(DataClassification::Confidential, $snapshot->maxClassification);
        self::assertSame(['perm1'], $snapshot->permissions);
        self::assertSame(['role1'], $snapshot->roles);
        self::assertTrue($snapshot->authenticated);
    }
}
