<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dsa\Tests\Unit\NoticeAction;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Dsa\NoticeAction\IllegalContentNotice;

#[CoversClass(IllegalContentNotice::class)]
final class IllegalContentNoticeTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $submittedAt = new DateTimeImmutable('2026-03-15T10:00:00+00:00');
        $notice = new IllegalContentNotice(
            id: 'notice-1',
            contentId: 'post-123',
            contentUrl: 'https://example.com/post/123',
            reason: 'Contains illegal hate speech',
            submitterName: 'Jane Doe',
            submitterEmail: 'jane@example.com',
            submittedAt: $submittedAt,
            submitterId: 'user-42',
            legalProvision: 'Framework Decision 2008/913/JHA',
            territory: 'DE',
        );

        self::assertSame('notice-1', $notice->id);
        self::assertSame('post-123', $notice->contentId);
        self::assertSame('https://example.com/post/123', $notice->contentUrl);
        self::assertSame('Contains illegal hate speech', $notice->reason);
        self::assertSame('Jane Doe', $notice->submitterName);
        self::assertSame('jane@example.com', $notice->submitterEmail);
        self::assertSame($submittedAt, $notice->submittedAt);
        self::assertSame('user-42', $notice->submitterId);
        self::assertSame('Framework Decision 2008/913/JHA', $notice->legalProvision);
        self::assertSame('DE', $notice->territory);
    }

    #[Test]
    public function constructorDefaultsOptionalFieldsToNull(): void
    {
        $notice = new IllegalContentNotice(
            id: 'notice-2',
            contentId: 'post-456',
            contentUrl: 'https://example.com/post/456',
            reason: 'Illegal content',
            submitterName: 'John Doe',
            submitterEmail: 'john@example.com',
            submittedAt: new DateTimeImmutable(),
        );

        self::assertNull($notice->submitterId);
        self::assertNull($notice->legalProvision);
        self::assertNull($notice->territory);
    }

    #[Test]
    public function hasLegalProvisionReturnsTrueWhenSet(): void
    {
        $notice = new IllegalContentNotice(
            id: 'notice-3',
            contentId: 'post-789',
            contentUrl: 'https://example.com/post/789',
            reason: 'Copyright violation',
            submitterName: 'Submitter',
            submitterEmail: 'sub@example.com',
            submittedAt: new DateTimeImmutable(),
            legalProvision: 'Directive 2001/29/EC',
        );

        self::assertTrue($notice->hasLegalProvision());
    }

    #[Test]
    public function hasLegalProvisionReturnsFalseWhenNull(): void
    {
        $notice = new IllegalContentNotice(
            id: 'notice-4',
            contentId: 'post-abc',
            contentUrl: 'https://example.com/post/abc',
            reason: 'Bad content',
            submitterName: 'Submitter',
            submitterEmail: 'sub@example.com',
            submittedAt: new DateTimeImmutable(),
        );

        self::assertFalse($notice->hasLegalProvision());
    }

    #[Test]
    public function toArrayWithMinimalNotice(): void
    {
        $submittedAt = new DateTimeImmutable('2026-06-01T12:00:00+00:00');
        $notice = new IllegalContentNotice(
            id: 'notice-5',
            contentId: 'post-min',
            contentUrl: 'https://example.com/post/min',
            reason: 'Illegal content',
            submitterName: 'Reporter',
            submitterEmail: 'reporter@example.com',
            submittedAt: $submittedAt,
        );

        $array = $notice->toArray();

        self::assertSame('notice-5', $array['id']);
        self::assertSame('post-min', $array['content_id']);
        self::assertSame('https://example.com/post/min', $array['content_url']);
        self::assertSame('Illegal content', $array['reason']);
        self::assertSame('Reporter', $array['submitter_name']);
        self::assertSame('reporter@example.com', $array['submitter_email']);
        self::assertSame('2026-06-01T12:00:00+00:00', $array['submitted_at']);
        self::assertArrayNotHasKey('submitter_id', $array);
        self::assertArrayNotHasKey('legal_provision', $array);
        self::assertArrayNotHasKey('territory', $array);
    }

    #[Test]
    public function toArrayWithFullNotice(): void
    {
        $notice = new IllegalContentNotice(
            id: 'notice-6',
            contentId: 'post-full',
            contentUrl: 'https://example.com/post/full',
            reason: 'Full notice',
            submitterName: 'Full Reporter',
            submitterEmail: 'full@example.com',
            submittedAt: new DateTimeImmutable('2026-06-01T12:00:00+00:00'),
            submitterId: 'user-99',
            legalProvision: 'Art. 3 Regulation 2021/784',
            territory: 'FR',
        );

        $array = $notice->toArray();

        self::assertSame('user-99', $array['submitter_id']);
        self::assertSame('Art. 3 Regulation 2021/784', $array['legal_provision']);
        self::assertSame('FR', $array['territory']);
    }
}
