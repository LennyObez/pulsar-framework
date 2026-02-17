<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Session;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Session\HijackAction;

#[CoversClass(HijackAction::class)]
final class HijackActionTest extends TestCase
{
    #[Test]
    public function hasFourCases(): void
    {
        self::assertCount(4, HijackAction::cases());
    }

    #[Test]
    #[DataProvider('actionProvider')]
    public function backedValues(HijackAction $action, string $expected): void
    {
        self::assertSame($expected, $action->value);
    }

    /**
     * @return iterable<string, array{HijackAction, string}>
     */
    public static function actionProvider(): iterable
    {
        yield 'Allow' => [HijackAction::Allow, 'allow'];
        yield 'Invalidate' => [HijackAction::Invalidate, 'invalidate'];
        yield 'Challenge' => [HijackAction::Challenge, 'challenge'];
        yield 'Warn' => [HijackAction::Warn, 'warn'];
    }

    #[Test]
    public function fromBackedValueRoundTrips(): void
    {
        foreach (HijackAction::cases() as $action) {
            self::assertSame($action, HijackAction::from($action->value));
        }
    }

    #[Test]
    public function tryFromReturnsNullForUnknownValue(): void
    {
        self::assertNull(HijackAction::tryFrom('reject'));
    }
}
