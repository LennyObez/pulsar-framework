<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Identity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Identity\TwoFactorStatus;

#[CoversClass(Identity::class)]
final class IdentityTest extends TestCase
{
    #[Test]
    public function defaultsApplyWhenOptionalParametersOmitted(): void
    {
        $identity = new Identity(id: 'user-1', displayName: 'Test');

        self::assertSame([], $identity->roles());
        self::assertSame(TwoFactorStatus::Disabled, $identity->twoFactorStatus());
        self::assertSame([], $identity->attributes());
    }

    #[Test]
    public function fromArrayDefaultsTwoFactorStatusToDisabled(): void
    {
        $identity = Identity::fromArray([
            'id' => 'user-1',
            'display_name' => 'Test',
        ]);

        self::assertSame(TwoFactorStatus::Disabled, $identity->twoFactorStatus());
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
    public function fromArrayRejectsEmptyId(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('id must be a non-empty string');

        (void) Identity::fromArray(['id' => '', 'display_name' => 'Test']);
    }

    #[Test]
    public function fromArrayRejectsNonStringId(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('id must be a non-empty string');

        (void) Identity::fromArray(['id' => 123, 'display_name' => 'Test']);
    }

    #[Test]
    public function fromArrayRejectsNonStringDisplayName(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('display_name must be a string');

        (void) Identity::fromArray(['id' => 'user-1', 'display_name' => 42]);
    }

    #[Test]
    public function fromArrayRejectsNonStringRoles(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('roles must be a list of strings');

        (void) Identity::fromArray(['id' => 'user-1', 'display_name' => 'Test', 'roles' => [1, 2]]);
    }

    #[Test]
    public function fromArrayRejectsNonArrayAttributes(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('attributes must be an array');

        (void) Identity::fromArray(['id' => 'user-1', 'display_name' => 'Test', 'attributes' => 'bad']);
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
