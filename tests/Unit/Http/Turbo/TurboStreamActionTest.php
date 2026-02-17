<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Turbo;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Turbo\TurboStreamAction;

#[CoversClass(TurboStreamAction::class)]
final class TurboStreamActionTest extends TestCase
{
    /**
     * @return iterable<string, array{TurboStreamAction, string}>
     */
    public static function actionProvider(): iterable
    {
        yield 'append' => [TurboStreamAction::Append, 'append'];
        yield 'prepend' => [TurboStreamAction::Prepend, 'prepend'];
        yield 'replace' => [TurboStreamAction::Replace, 'replace'];
        yield 'update' => [TurboStreamAction::Update, 'update'];
        yield 'remove' => [TurboStreamAction::Remove, 'remove'];
        yield 'before' => [TurboStreamAction::Before, 'before'];
        yield 'after' => [TurboStreamAction::After, 'after'];
    }

    #[Test]
    #[DataProvider('actionProvider')]
    public function action_has_correct_value(TurboStreamAction $action, string $expected): void
    {
        self::assertSame($expected, $action->value);
    }

    #[Test]
    public function all_actions(): void
    {
        self::assertCount(7, TurboStreamAction::cases());
    }
}
