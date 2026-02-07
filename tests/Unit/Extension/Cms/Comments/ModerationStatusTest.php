<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Comments;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Comments\ModerationStatus;

#[CoversClass(ModerationStatus::class)]
final class ModerationStatusTest extends TestCase
{
    // -- Valid transitions (Pending -> any other) -------------------------

    #[Test]
    #[DataProvider('validTransitionsProvider')]
    public function test_can_transition_from_pending(ModerationStatus $target): void
    {
        self::assertTrue(ModerationStatus::Pending->canTransitionTo($target));
    }

    /**
     * @return iterable<string, array{ModerationStatus}>
     */
    public static function validTransitionsProvider(): iterable
    {
        yield 'Pending -> Approved' => [ModerationStatus::Approved];
        yield 'Pending -> Rejected' => [ModerationStatus::Rejected];
        yield 'Pending -> Spam' => [ModerationStatus::Spam];
    }

    // -- Invalid transitions (non-Pending -> anything) --------------------

    #[Test]
    #[DataProvider('invalidTransitionsProvider')]
    public function test_cannot_transition_from_non_pending(ModerationStatus $from, ModerationStatus $to): void
    {
        self::assertFalse($from->canTransitionTo($to));
    }

    /**
     * @return iterable<string, array{ModerationStatus, ModerationStatus}>
     */
    public static function invalidTransitionsProvider(): iterable
    {
        yield 'Approved -> Pending' => [ModerationStatus::Approved, ModerationStatus::Pending];
        yield 'Approved -> Rejected' => [ModerationStatus::Approved, ModerationStatus::Rejected];
        yield 'Approved -> Spam' => [ModerationStatus::Approved, ModerationStatus::Spam];

        yield 'Rejected -> Pending' => [ModerationStatus::Rejected, ModerationStatus::Pending];
        yield 'Rejected -> Approved' => [ModerationStatus::Rejected, ModerationStatus::Approved];
        yield 'Rejected -> Spam' => [ModerationStatus::Rejected, ModerationStatus::Spam];

        yield 'Spam -> Pending' => [ModerationStatus::Spam, ModerationStatus::Pending];
        yield 'Spam -> Approved' => [ModerationStatus::Spam, ModerationStatus::Approved];
        yield 'Spam -> Rejected' => [ModerationStatus::Spam, ModerationStatus::Rejected];
    }

    // -- Self-transitions are forbidden -----------------------------------

    #[Test]
    #[DataProvider('allStatusesProvider')]
    public function test_cannot_transition_to_self(ModerationStatus $status): void
    {
        self::assertFalse($status->canTransitionTo($status));
    }

    /**
     * @return iterable<string, array{ModerationStatus}>
     */
    public static function allStatusesProvider(): iterable
    {
        foreach (ModerationStatus::cases() as $status) {
            yield $status->value => [$status];
        }
    }

    // -- label() ----------------------------------------------------------

    #[Test]
    #[DataProvider('labelProvider')]
    public function test_label_returns_human_readable_string(ModerationStatus $status, string $expectedLabel): void
    {
        self::assertSame($expectedLabel, $status->label());
    }

    /**
     * @return iterable<string, array{ModerationStatus, string}>
     */
    public static function labelProvider(): iterable
    {
        yield 'Pending' => [ModerationStatus::Pending, 'Pending'];
        yield 'Approved' => [ModerationStatus::Approved, 'Approved'];
        yield 'Rejected' => [ModerationStatus::Rejected, 'Rejected'];
        yield 'Spam' => [ModerationStatus::Spam, 'Spam'];
    }

    // -- Backed enum values match database values -------------------------

    #[Test]
    public function test_backed_values_match_database_values(): void
    {
        self::assertSame('pending', ModerationStatus::Pending->value);
        self::assertSame('approved', ModerationStatus::Approved->value);
        self::assertSame('rejected', ModerationStatus::Rejected->value);
        self::assertSame('spam', ModerationStatus::Spam->value);
    }

    #[Test]
    public function test_from_valid_value_returns_correct_case(): void
    {
        self::assertSame(ModerationStatus::Pending, ModerationStatus::from('pending'));
        self::assertSame(ModerationStatus::Approved, ModerationStatus::from('approved'));
        self::assertSame(ModerationStatus::Rejected, ModerationStatus::from('rejected'));
        self::assertSame(ModerationStatus::Spam, ModerationStatus::from('spam'));
    }

    #[Test]
    public function test_try_from_invalid_value_returns_null(): void
    {
        self::assertNull(ModerationStatus::tryFrom('flagged'));
        self::assertNull(ModerationStatus::tryFrom(''));
    }

    #[Test]
    public function test_cases_returns_four_statuses(): void
    {
        self::assertCount(4, ModerationStatus::cases());
    }
}
