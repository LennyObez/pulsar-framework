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
}
