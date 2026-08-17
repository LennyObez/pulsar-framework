<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification\Consent;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Notification\Consent\NotificationClassification;

#[CoversNothing]
final class NotificationClassificationTest extends TestCase
{
    #[Test]
    public function transactionalHasCorrectValue(): void
    {
        self::assertSame('transactional', NotificationClassification::Transactional->value);
    }

    #[Test]
    public function marketingHasCorrectValue(): void
    {
        self::assertSame('marketing', NotificationClassification::Marketing->value);
    }

    #[Test]
    #[DataProvider('validBackingValues')]
    public function fromReturnsCorrectCase(string $value, NotificationClassification $expected): void
    {
        self::assertSame($expected, NotificationClassification::from($value));
    }

    /**
     * @return iterable<string, array{string, NotificationClassification}>
     */
    public static function validBackingValues(): iterable
    {
        yield 'transactional' => ['transactional', NotificationClassification::Transactional];
        yield 'marketing' => ['marketing', NotificationClassification::Marketing];
    }

    #[Test]
    public function tryFromReturnsNullForUnknownValue(): void
    {
        self::assertNull(NotificationClassification::tryFrom('promotional'));
    }

    #[Test]
    public function casesReturnsAllEnumValues(): void
    {
        $cases = NotificationClassification::cases();

        self::assertCount(2, $cases);
        self::assertContains(NotificationClassification::Transactional, $cases);
        self::assertContains(NotificationClassification::Marketing, $cases);
    }
}
