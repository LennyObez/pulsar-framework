<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dsa\Tests\Unit\TrustedFlagger;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Dsa\TrustedFlagger\FlagSubmission;

#[CoversClass(FlagSubmission::class)]
final class FlagSubmissionTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $submittedAt = new DateTimeImmutable('2026-03-15T10:00:00+00:00');
        $submission = new FlagSubmission(
            id: 'flag-1',
            flaggerId: 'tf-1',
            contentId: 'post-123',
            contentType: 'post',
            reason: 'CSAM detected',
            contentUrl: 'https://example.com/post/123',
            submittedAt: $submittedAt,
            status: 'reviewed',
            legalProvision: 'Directive 2011/93/EU',
        );

        self::assertSame('flag-1', $submission->id);
        self::assertSame('tf-1', $submission->flaggerId);
        self::assertSame('post-123', $submission->contentId);
        self::assertSame('post', $submission->contentType);
        self::assertSame('CSAM detected', $submission->reason);
        self::assertSame('https://example.com/post/123', $submission->contentUrl);
        self::assertSame($submittedAt, $submission->submittedAt);
        self::assertSame('reviewed', $submission->status);
        self::assertSame('Directive 2011/93/EU', $submission->legalProvision);
    }

    #[Test]
    public function constructorUsesDefaultsForOptionalProperties(): void
    {
        $submission = new FlagSubmission(
            id: 'flag-2',
            flaggerId: 'tf-2',
            contentId: 'post-456',
            contentType: 'comment',
            reason: 'Illegal content',
            contentUrl: 'https://example.com/post/456',
            submittedAt: new DateTimeImmutable(),
        );

        self::assertSame('pending', $submission->status);
        self::assertNull($submission->legalProvision);
    }

    #[Test]
    public function toArrayWithMinimalSubmission(): void
    {
        $submittedAt = new DateTimeImmutable('2026-06-01T12:00:00+00:00');
        $submission = new FlagSubmission(
            id: 'flag-3',
            flaggerId: 'tf-3',
            contentId: 'post-789',
            contentType: 'media',
            reason: 'Copyright violation',
            contentUrl: 'https://example.com/media/789',
            submittedAt: $submittedAt,
        );

        $array = $submission->toArray();

        self::assertSame('flag-3', $array['id']);
        self::assertSame('tf-3', $array['flagger_id']);
        self::assertSame('post-789', $array['content_id']);
        self::assertSame('media', $array['content_type']);
        self::assertSame('Copyright violation', $array['reason']);
        self::assertSame('https://example.com/media/789', $array['content_url']);
        self::assertSame('2026-06-01T12:00:00+00:00', $array['submitted_at']);
        self::assertSame('pending', $array['status']);
        self::assertArrayNotHasKey('legal_provision', $array);
    }

    #[Test]
    public function toArrayWithFullSubmission(): void
    {
        $submission = new FlagSubmission(
            id: 'flag-4',
            flaggerId: 'tf-4',
            contentId: 'post-full',
            contentType: 'post',
            reason: 'Terrorism content',
            contentUrl: 'https://example.com/post/full',
            submittedAt: new DateTimeImmutable('2026-06-01T12:00:00+00:00'),
            status: 'actioned',
            legalProvision: 'Regulation (EU) 2021/784',
        );

        $array = $submission->toArray();

        self::assertSame('actioned', $array['status']);
        self::assertSame('Regulation (EU) 2021/784', $array['legal_provision']);
    }
}
