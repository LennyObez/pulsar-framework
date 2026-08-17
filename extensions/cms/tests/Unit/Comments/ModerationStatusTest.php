<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Comments;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Comments\ModerationStatus;

#[CoversNothing]
final class ModerationStatusTest extends TestCase
{
    /**
     * @return iterable<string, array{ModerationStatus, ModerationStatus, bool}>
     */
    public static function transitionProvider(): iterable
    {
        yield 'pending to approved' => [ModerationStatus::Pending, ModerationStatus::Approved, true];
        yield 'pending to rejected' => [ModerationStatus::Pending, ModerationStatus::Rejected, true];
        yield 'pending to spam' => [ModerationStatus::Pending, ModerationStatus::Spam, true];
        yield 'approved to rejected' => [ModerationStatus::Approved, ModerationStatus::Rejected, false];
        yield 'approved to spam' => [ModerationStatus::Approved, ModerationStatus::Spam, false];
        yield 'rejected to approved' => [ModerationStatus::Rejected, ModerationStatus::Approved, false];
        yield 'spam to approved' => [ModerationStatus::Spam, ModerationStatus::Approved, false];
    }

    #[Test]
    #[DataProvider('transitionProvider')]
    public function canTransitionTo_validates_rules(
        ModerationStatus $from,
        ModerationStatus $to,
        bool $expected,
    ): void {
        self::assertSame($expected, $from->canTransitionTo($to));
    }

    #[Test]
    public function canTransitionTo_rejects_self_transition(): void
    {
        foreach (ModerationStatus::cases() as $status) {
            self::assertFalse($status->canTransitionTo($status));
        }
    }

    #[Test]
    public function label_returns_human_readable_names(): void
    {
        self::assertSame('Pending', ModerationStatus::Pending->label());
        self::assertSame('Approved', ModerationStatus::Approved->label());
        self::assertSame('Rejected', ModerationStatus::Rejected->label());
        self::assertSame('Spam', ModerationStatus::Spam->label());
    }
}
