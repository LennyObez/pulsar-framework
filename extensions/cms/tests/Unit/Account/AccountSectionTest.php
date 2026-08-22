<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Account;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Account\AccountSection;

#[CoversClass(AccountSection::class)]
final class AccountSectionTest extends TestCase
{
    #[Test]
    public function constructSetsAllProperties(): void
    {
        $section = new AccountSection(
            id: 'orders',
            label: 'My Orders',
            icon: 'shopping-bag',
            priority: 10,
            badgeCount: '3',
        );

        self::assertSame('orders', $section->id);
        self::assertSame('My Orders', $section->label);
        self::assertSame('shopping-bag', $section->icon);
        self::assertSame(10, $section->priority);
        self::assertSame('3', $section->badgeCount);
    }

    #[Test]
    public function defaultPriorityIsFifty(): void
    {
        $section = new AccountSection(
            id: 'profile',
            label: 'Profile',
            icon: 'user',
        );

        self::assertSame(50, $section->priority);
    }

    #[Test]
    public function badgeCountDefaultsToNull(): void
    {
        $section = new AccountSection(
            id: 'settings',
            label: 'Settings',
            icon: 'cog',
        );

        self::assertNull($section->badgeCount);
    }
}
