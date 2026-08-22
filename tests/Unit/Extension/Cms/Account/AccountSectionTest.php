<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Account;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Account\AccountSection;

#[CoversClass(AccountSection::class)]
final class AccountSectionTest extends TestCase
{
    #[Test]
    public function constructsSectionWithAllProperties(): void
    {
        $section = new AccountSection(
            id: 'orders',
            label: 'My Orders',
            icon: 'shopping-bag',
            priority: 10,
            badgeCount: '5',
        );

        self::assertSame('orders', $section->id);
        self::assertSame('My Orders', $section->label);
        self::assertSame('shopping-bag', $section->icon);
        self::assertSame(10, $section->priority);
        self::assertSame('5', $section->badgeCount);
    }

    #[Test]
    public function defaultPriorityIsFifty(): void
    {
        $section = new AccountSection('test', 'Test', 'star');

        self::assertSame(50, $section->priority);
    }

    #[Test]
    public function defaultBadgeCountIsNull(): void
    {
        $section = new AccountSection('test', 'Test', 'star');

        self::assertNull($section->badgeCount);
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function priorityOrderingProvider(): iterable
    {
        yield 'lower priority sorts first' => [10, 50, -1];
        yield 'equal priority sorts equal' => [50, 50, 0];
        yield 'higher priority sorts last' => [90, 50, 1];
    }

    #[Test]
    #[DataProvider('priorityOrderingProvider')]
    public function priorityComparesCorrectly(int $a, int $b, int $expected): void
    {
        $result = $a <=> $b;

        self::assertSame($expected, $result);
    }
}
