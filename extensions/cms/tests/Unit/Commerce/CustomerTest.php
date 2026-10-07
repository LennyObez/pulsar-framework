<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Commerce;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\Customer;

#[CoversClass(Customer::class)]
final class CustomerTest extends TestCase
{
    #[Test]
    public function hasLinkedUser_returns_true_when_userId_set(): void
    {
        $now = new DateTimeImmutable();
        $customer = new Customer(
            id: 'cust-1',
            tenantId: null,
            userId: 'user-1',
            email: 'test@example.com',
            displayName: 'Test User',
            billingAddress: null,
            shippingAddress: null,
            notes: null,
            createdAt: $now,
            updatedAt: $now,
        );

        self::assertTrue($customer->hasLinkedUser());
    }

    #[Test]
    public function hasLinkedUser_returns_false_when_userId_null(): void
    {
        $now = new DateTimeImmutable();
        $customer = new Customer(
            id: 'cust-1',
            tenantId: null,
            userId: null,
            email: 'guest@example.com',
            displayName: null,
            billingAddress: null,
            shippingAddress: null,
            notes: null,
            createdAt: $now,
            updatedAt: $now,
        );

        self::assertFalse($customer->hasLinkedUser());
    }

    #[Test]
    public function withProfile_replaces_profile_fields_and_refreshes_timestamp(): void
    {
        $createdAt = new DateTimeImmutable('2020-01-01T00:00:00+00:00');
        $originalUpdatedAt = new DateTimeImmutable('2020-01-02T00:00:00+00:00');
        $customer = new Customer(
            id: 'cust-1',
            tenantId: 'tenant-1',
            userId: 'user-1',
            email: 'test@example.com',
            displayName: 'Old Name',
            billingAddress: ['line1' => 'Old billing'],
            shippingAddress: ['line1' => 'Old shipping'],
            notes: 'keep me',
            createdAt: $createdAt,
            updatedAt: $originalUpdatedAt,
        );

        $updated = $customer->withProfile(
            displayName: 'New Name',
            billingAddress: ['line1' => 'New billing'],
            shippingAddress: ['line1' => 'New shipping'],
        );

        // New instance carries the updated profile.
        self::assertSame('New Name', $updated->displayName);
        self::assertSame(['line1' => 'New billing'], $updated->billingAddress);
        self::assertSame(['line1' => 'New shipping'], $updated->shippingAddress);
        // Unrelated fields are preserved verbatim.
        self::assertSame('cust-1', $updated->id);
        self::assertSame('tenant-1', $updated->tenantId);
        self::assertSame('user-1', $updated->userId);
        self::assertSame('test@example.com', $updated->email);
        self::assertSame('keep me', $updated->notes);
        self::assertSame($createdAt, $updated->createdAt);
        // updatedAt is refreshed forward from the original.
        self::assertGreaterThan($originalUpdatedAt, $updated->updatedAt);
        // Source instance is untouched (immutable copy semantics).
        self::assertSame('Old Name', $customer->displayName);
        self::assertSame($originalUpdatedAt, $customer->updatedAt);
    }

    #[Test]
    public function withProfile_accepts_null_profile_fields(): void
    {
        $now = new DateTimeImmutable();
        $customer = new Customer(
            id: 'cust-1',
            tenantId: null,
            userId: null,
            email: 'test@example.com',
            displayName: 'Has Name',
            billingAddress: ['line1' => 'billing'],
            shippingAddress: ['line1' => 'shipping'],
            notes: null,
            createdAt: $now,
            updatedAt: $now,
        );

        $updated = $customer->withProfile(
            displayName: null,
            billingAddress: null,
            shippingAddress: null,
        );

        self::assertNull($updated->displayName);
        self::assertNull($updated->billingAddress);
        self::assertNull($updated->shippingAddress);
    }

    #[Test]
    public function withNotes_replaces_notes_and_refreshes_timestamp(): void
    {
        $createdAt = new DateTimeImmutable('2020-01-01T00:00:00+00:00');
        $originalUpdatedAt = new DateTimeImmutable('2020-01-02T00:00:00+00:00');
        $customer = new Customer(
            id: 'cust-1',
            tenantId: null,
            userId: 'user-1',
            email: 'test@example.com',
            displayName: 'Test User',
            billingAddress: null,
            shippingAddress: null,
            notes: 'first note',
            createdAt: $createdAt,
            updatedAt: $originalUpdatedAt,
        );

        $updated = $customer->withNotes("first note\n[2020-01-03 10:00] (admin) second note");

        self::assertSame("first note\n[2020-01-03 10:00] (admin) second note", $updated->notes);
        // Unrelated fields are preserved verbatim.
        self::assertSame('Test User', $updated->displayName);
        self::assertSame($createdAt, $updated->createdAt);
        // updatedAt is refreshed forward.
        self::assertGreaterThan($originalUpdatedAt, $updated->updatedAt);
        // Source instance is untouched.
        self::assertSame('first note', $customer->notes);
        self::assertSame($originalUpdatedAt, $customer->updatedAt);
    }
}
