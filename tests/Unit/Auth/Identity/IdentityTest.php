<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Identity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Identity\TwoFactorStatus;

#[CoversClass(Identity::class)]
final class IdentityTest extends TestCase
{
    #[Test]
    public function constructorSetsAllPropertiesCorrectly(): void
    {
        $identity = new Identity(
            id: 'user-42',
            displayName: 'Jane Doe',
            roles: ['admin', 'editor'],
            twoFactorStatus: TwoFactorStatus::Verified,
            attributes: ['email' => 'jane@example.com', 'locale' => 'en'],
        );

        self::assertSame('user-42', $identity->id());
        self::assertSame('Jane Doe', $identity->displayName());
        self::assertSame(['admin', 'editor'], $identity->roles());
        self::assertSame(TwoFactorStatus::Verified, $identity->twoFactorStatus());
        self::assertSame(['email' => 'jane@example.com', 'locale' => 'en'], $identity->attributes());
    }

    #[Test]
    public function isAuthenticatedReturnsTrue(): void
    {
        $identity = new Identity(id: 'user-1', displayName: 'Test');

        self::assertTrue($identity->isAuthenticated());
    }

    #[Test]
    public function hasRoleReturnsTrueForAssignedRole(): void
    {
        $identity = new Identity(
            id: 'user-1',
            displayName: 'Test',
            roles: ['admin', 'editor'],
        );

        self::assertTrue($identity->hasRole('admin'));
        self::assertTrue($identity->hasRole('editor'));
    }

    #[Test]
    public function hasRoleReturnsFalseForUnassignedRole(): void
    {
        $identity = new Identity(
            id: 'user-1',
            displayName: 'Test',
            roles: ['editor'],
        );

        self::assertFalse($identity->hasRole('admin'));
        self::assertFalse($identity->hasRole('superadmin'));
    }

    #[Test]
    public function attributeReturnsValueWhenKeyExists(): void
    {
        $identity = new Identity(
            id: 'user-1',
            displayName: 'Test',
            attributes: ['email' => 'test@example.com', 'age' => 30],
        );

        self::assertSame('test@example.com', $identity->attribute('email'));
        self::assertSame(30, $identity->attribute('age'));
    }

    #[Test]
    public function attributeReturnsDefaultWhenKeyDoesNotExist(): void
    {
        $identity = new Identity(
            id: 'user-1',
            displayName: 'Test',
            attributes: [],
        );

        self::assertNull($identity->attribute('missing'));
        self::assertSame('fallback', $identity->attribute('missing', 'fallback'));
    }

    #[Test]
    public function toArraySerializesAllFields(): void
    {
        $identity = new Identity(
            id: 'user-99',
            displayName: 'Serializable User',
            roles: ['viewer'],
            twoFactorStatus: TwoFactorStatus::Pending,
            attributes: ['department' => 'engineering'],
        );

        $array = $identity->toArray();

        self::assertSame('user-99', $array['id']);
        self::assertSame('Serializable User', $array['display_name']);
        self::assertSame(['viewer'], $array['roles']);
        self::assertSame('pending', $array['two_factor_status']);
        self::assertSame(['department' => 'engineering'], $array['attributes']);
    }

    #[Test]
    public function fromArrayDeserializesCorrectly(): void
    {
        $data = [
            'id' => 'user-77',
            'display_name' => 'Deserialized User',
            'roles' => ['admin', 'auditor'],
            'two_factor_status' => 'verified',
            'attributes' => ['region' => 'us-east'],
        ];

        $identity = Identity::fromArray($data);

        self::assertSame('user-77', $identity->id());
        self::assertSame('Deserialized User', $identity->displayName());
        self::assertSame(['admin', 'auditor'], $identity->roles());
        self::assertSame(TwoFactorStatus::Verified, $identity->twoFactorStatus());
        self::assertSame(['region' => 'us-east'], $identity->attributes());
    }

    #[Test]
    public function withTwoFactorStatusReturnsNewInstanceWithChangedStatus(): void
    {
        $original = new Identity(
            id: 'user-1',
            displayName: 'Test',
            roles: ['admin'],
            twoFactorStatus: TwoFactorStatus::Disabled,
            attributes: ['key' => 'value'],
        );

        $updated = $original->withTwoFactorStatus(TwoFactorStatus::Verified);

        // Original is unchanged
        self::assertSame(TwoFactorStatus::Disabled, $original->twoFactorStatus());

        // New instance has the updated status
        self::assertSame(TwoFactorStatus::Verified, $updated->twoFactorStatus());

        // All other properties are preserved
        self::assertSame('user-1', $updated->id());
        self::assertSame('Test', $updated->displayName());
        self::assertSame(['admin'], $updated->roles());
        self::assertSame(['key' => 'value'], $updated->attributes());

        // They are not the same instance
        self::assertNotSame($original, $updated);
    }

    #[Test]
    public function roundtripFromArrayToArrayPreservesAllData(): void
    {
        $original = new Identity(
            id: 'roundtrip-user',
            displayName: 'Roundtrip Test',
            roles: ['admin', 'manager', 'viewer'],
            twoFactorStatus: TwoFactorStatus::Verified,
            attributes: ['email' => 'rt@example.com', 'level' => 5, 'active' => true],
        );

        $restored = Identity::fromArray($original->toArray());

        self::assertSame($original->id(), $restored->id());
        self::assertSame($original->displayName(), $restored->displayName());
        self::assertSame($original->roles(), $restored->roles());
        self::assertSame($original->twoFactorStatus(), $restored->twoFactorStatus());
        self::assertSame($original->attributes(), $restored->attributes());
        self::assertSame($original->isAuthenticated(), $restored->isAuthenticated());
    }
}
