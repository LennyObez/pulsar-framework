<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ZeroTrust\StepUp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\ZeroTrust\StepUp\StepUpAction;

#[CoversClass(StepUpAction::class)]
final class StepUpActionTest extends TestCase
{
    #[Test]
    public function hasThreeCases(): void
    {
        self::assertCount(3, StepUpAction::cases());
    }

    #[Test]
    #[DataProvider('actionProvider')]
    public function backedValues(StepUpAction $action, string $expected): void
    {
        self::assertSame($expected, $action->value);
    }

    /**
     * @return iterable<string, array{StepUpAction, string}>
     */
    public static function actionProvider(): iterable
    {
        yield 'Redirect' => [StepUpAction::Redirect, 'redirect'];
        yield 'Deny' => [StepUpAction::Deny, 'deny'];
        yield 'Allow' => [StepUpAction::Allow, 'allow'];
    }

    #[Test]
    public function fromBackedValueRoundTrips(): void
    {
        foreach (StepUpAction::cases() as $action) {
            self::assertSame($action, StepUpAction::from($action->value));
        }
    }

    #[Test]
    public function tryFromReturnsNullForInvalid(): void
    {
        self::assertNull(StepUpAction::tryFrom('challenge'));
    }
}
