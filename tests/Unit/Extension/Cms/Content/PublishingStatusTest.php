<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Content;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\PublishingStatus;

#[CoversClass(PublishingStatus::class)]
final class PublishingStatusTest extends TestCase
{
    // ── Standard mode transitions ────────────────────────────────────

    #[Test]
    #[DataProvider('validStandardTransitionsProvider')]
    public function test_can_transition_to_valid_standard_pair(PublishingStatus $from, PublishingStatus $to): void
    {
        self::assertTrue($from->canTransitionTo($to, editorialWorkflow: false));
    }

    /**
     * @return iterable<string, array{PublishingStatus, PublishingStatus}>
     */
    public static function validStandardTransitionsProvider(): iterable
    {
        yield 'Draft -> Published' => [PublishingStatus::Draft, PublishingStatus::Published];
        yield 'Draft -> Scheduled' => [PublishingStatus::Draft, PublishingStatus::Scheduled];
        yield 'Scheduled -> Published' => [PublishingStatus::Scheduled, PublishingStatus::Published];
        yield 'Published -> Archived' => [PublishingStatus::Published, PublishingStatus::Archived];
        yield 'Archived -> Draft' => [PublishingStatus::Archived, PublishingStatus::Draft];
    }

    #[Test]
    #[DataProvider('invalidStandardTransitionsProvider')]
    public function test_cannot_transition_to_invalid_standard_pair(PublishingStatus $from, PublishingStatus $to): void
    {
        self::assertFalse($from->canTransitionTo($to, editorialWorkflow: false));
    }

    /**
     * @return iterable<string, array{PublishingStatus, PublishingStatus}>
     */
    public static function invalidStandardTransitionsProvider(): iterable
    {
        // Draft cannot go to InReview without editorial workflow
        yield 'Draft -> InReview (standard)' => [PublishingStatus::Draft, PublishingStatus::InReview];
        yield 'Draft -> Approved' => [PublishingStatus::Draft, PublishingStatus::Approved];
        yield 'Draft -> Archived' => [PublishingStatus::Draft, PublishingStatus::Archived];

        yield 'Published -> Draft' => [PublishingStatus::Published, PublishingStatus::Draft];
        yield 'Published -> Scheduled' => [PublishingStatus::Published, PublishingStatus::Scheduled];
        yield 'Published -> InReview' => [PublishingStatus::Published, PublishingStatus::InReview];

        yield 'Archived -> Published' => [PublishingStatus::Archived, PublishingStatus::Published];
        yield 'Archived -> Scheduled' => [PublishingStatus::Archived, PublishingStatus::Scheduled];
        yield 'Archived -> InReview' => [PublishingStatus::Archived, PublishingStatus::InReview];

        yield 'Scheduled -> Draft' => [PublishingStatus::Scheduled, PublishingStatus::Draft];
        yield 'Scheduled -> Archived' => [PublishingStatus::Scheduled, PublishingStatus::Archived];

        // InReview and Approved are editorial-only, invalid in standard mode
        yield 'InReview -> Approved (standard)' => [PublishingStatus::InReview, PublishingStatus::Approved];
        yield 'InReview -> Draft (standard)' => [PublishingStatus::InReview, PublishingStatus::Draft];
        yield 'Approved -> Published (standard)' => [PublishingStatus::Approved, PublishingStatus::Published];
    }

    // ── Editorial workflow transitions ───────────────────────────────

    #[Test]
    #[DataProvider('validEditorialTransitionsProvider')]
    public function test_can_transition_to_valid_editorial_pair(PublishingStatus $from, PublishingStatus $to): void
    {
        self::assertTrue($from->canTransitionTo($to, editorialWorkflow: true));
    }

    /**
     * @return iterable<string, array{PublishingStatus, PublishingStatus}>
     */
    public static function validEditorialTransitionsProvider(): iterable
    {
        // All standard transitions remain valid
        yield 'Draft -> Published (editorial)' => [PublishingStatus::Draft, PublishingStatus::Published];
        yield 'Draft -> Scheduled (editorial)' => [PublishingStatus::Draft, PublishingStatus::Scheduled];
        yield 'Scheduled -> Published (editorial)' => [PublishingStatus::Scheduled, PublishingStatus::Published];
        yield 'Published -> Archived (editorial)' => [PublishingStatus::Published, PublishingStatus::Archived];
        yield 'Archived -> Draft (editorial)' => [PublishingStatus::Archived, PublishingStatus::Draft];

        // Additional editorial transitions
        yield 'Draft -> InReview' => [PublishingStatus::Draft, PublishingStatus::InReview];
        yield 'InReview -> Approved' => [PublishingStatus::InReview, PublishingStatus::Approved];
        yield 'InReview -> Draft (rejection)' => [PublishingStatus::InReview, PublishingStatus::Draft];
        yield 'Approved -> Published' => [PublishingStatus::Approved, PublishingStatus::Published];
        yield 'Approved -> Scheduled' => [PublishingStatus::Approved, PublishingStatus::Scheduled];
        yield 'Approved -> Draft (re-draft)' => [PublishingStatus::Approved, PublishingStatus::Draft];
    }

    #[Test]
    #[DataProvider('invalidEditorialTransitionsProvider')]
    public function test_cannot_transition_to_invalid_editorial_pair(PublishingStatus $from, PublishingStatus $to): void
    {
        self::assertFalse($from->canTransitionTo($to, editorialWorkflow: true));
    }

    /**
     * @return iterable<string, array{PublishingStatus, PublishingStatus}>
     */
    public static function invalidEditorialTransitionsProvider(): iterable
    {
        yield 'Published -> Draft (editorial)' => [PublishingStatus::Published, PublishingStatus::Draft];
        yield 'Published -> InReview' => [PublishingStatus::Published, PublishingStatus::InReview];
        yield 'Archived -> Published (editorial)' => [PublishingStatus::Archived, PublishingStatus::Published];
        yield 'InReview -> Published (skip approved)' => [PublishingStatus::InReview, PublishingStatus::Published];
        yield 'InReview -> Scheduled (skip approved)' => [PublishingStatus::InReview, PublishingStatus::Scheduled];
        yield 'Scheduled -> Draft (editorial)' => [PublishingStatus::Scheduled, PublishingStatus::Draft];
    }

    // ── Self-transition forbidden ────────────────────────────────────

    #[Test]
    #[DataProvider('allStatusesProvider')]
    public function test_cannot_transition_to_self(PublishingStatus $status): void
    {
        self::assertFalse($status->canTransitionTo($status));
        self::assertFalse($status->canTransitionTo($status, editorialWorkflow: true));
    }

    /**
     * @return iterable<string, array{PublishingStatus}>
     */
    public static function allStatusesProvider(): iterable
    {
        foreach (PublishingStatus::cases() as $status) {
            yield $status->value => [$status];
        }
    }

    // ── isPubliclyVisible ────────────────────────────────────────────

    #[Test]
    public function test_is_publicly_visible_only_for_published(): void
    {
        self::assertTrue(PublishingStatus::Published->isPubliclyVisible());
        self::assertFalse(PublishingStatus::Draft->isPubliclyVisible());
        self::assertFalse(PublishingStatus::InReview->isPubliclyVisible());
        self::assertFalse(PublishingStatus::Approved->isPubliclyVisible());
        self::assertFalse(PublishingStatus::Scheduled->isPubliclyVisible());
        self::assertFalse(PublishingStatus::Archived->isPubliclyVisible());
    }

    // ── label ────────────────────────────────────────────────────────

    #[Test]
    #[DataProvider('labelProvider')]
    public function test_label_returns_human_readable_string(PublishingStatus $status, string $expectedLabel): void
    {
        self::assertSame($expectedLabel, $status->label());
    }

    /**
     * @return iterable<string, array{PublishingStatus, string}>
     */
    public static function labelProvider(): iterable
    {
        yield 'Draft' => [PublishingStatus::Draft, 'Draft'];
        yield 'InReview' => [PublishingStatus::InReview, 'In Review'];
        yield 'Approved' => [PublishingStatus::Approved, 'Approved'];
        yield 'Scheduled' => [PublishingStatus::Scheduled, 'Scheduled'];
        yield 'Published' => [PublishingStatus::Published, 'Published'];
        yield 'Archived' => [PublishingStatus::Archived, 'Archived'];
    }

    // ── Backed enum values ───────────────────────────────────────────

    #[Test]
    public function test_all_statuses_have_expected_string_values(): void
    {
        self::assertSame('draft', PublishingStatus::Draft->value);
        self::assertSame('in_review', PublishingStatus::InReview->value);
        self::assertSame('approved', PublishingStatus::Approved->value);
        self::assertSame('scheduled', PublishingStatus::Scheduled->value);
        self::assertSame('published', PublishingStatus::Published->value);
        self::assertSame('archived', PublishingStatus::Archived->value);
    }

    #[Test]
    public function test_from_valid_value_returns_correct_case(): void
    {
        self::assertSame(PublishingStatus::Draft, PublishingStatus::from('draft'));
        self::assertSame(PublishingStatus::Published, PublishingStatus::from('published'));
    }

    #[Test]
    public function test_try_from_invalid_value_returns_null(): void
    {
        self::assertNull(PublishingStatus::tryFrom('nonexistent'));
    }
}
