<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dsa\Tests\Unit\ContentModeration;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Dsa\ContentModeration\ModerationDecision;

#[CoversClass(ModerationDecision::class)]
final class ModerationDecisionTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $decidedAt = new DateTimeImmutable('2026-03-15T10:00:00+00:00');
        $decision = new ModerationDecision(
            id: 'dec-1',
            contentId: 'post-123',
            contentType: 'post',
            decision: 'remove',
            reason: 'Violates hate speech policy per Framework Decision 2008/913/JHA',
            policyId: 'policy-hate-speech',
            detectionMethod: 'human',
            decidedAt: $decidedAt,
            appealUrl: 'https://example.com/appeal/dec-1',
            moderatorId: 'mod-42',
            territory: 'DE',
        );

        self::assertSame('dec-1', $decision->id);
        self::assertSame('post-123', $decision->contentId);
        self::assertSame('post', $decision->contentType);
        self::assertSame('remove', $decision->decision);
        self::assertSame('policy-hate-speech', $decision->policyId);
        self::assertSame('human', $decision->detectionMethod);
        self::assertSame($decidedAt, $decision->decidedAt);
        self::assertSame('https://example.com/appeal/dec-1', $decision->appealUrl);
        self::assertSame('mod-42', $decision->moderatorId);
        self::assertSame('DE', $decision->territory);
    }

    #[Test]
    public function constructorDefaultsOptionalFieldsToNull(): void
    {
        $decision = new ModerationDecision(
            id: 'dec-2',
            contentId: 'post-456',
            contentType: 'comment',
            decision: 'restrict',
            reason: 'Spam detected',
            policyId: 'policy-spam',
            detectionMethod: 'automated',
            decidedAt: new DateTimeImmutable(),
            appealUrl: 'https://example.com/appeal/dec-2',
        );

        self::assertNull($decision->moderatorId);
        self::assertNull($decision->territory);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function automatedDetectionProvider(): iterable
    {
        yield 'automated is automated' => ['automated', true];
        yield 'human is not automated' => ['human', false];
        yield 'trusted_flagger is not automated' => ['trusted_flagger', false];
        yield 'notice is not automated' => ['notice', false];
    }

    #[Test]
    #[DataProvider('automatedDetectionProvider')]
    public function isAutomatedReturnsCorrectResult(string $method, bool $expected): void
    {
        $decision = new ModerationDecision(
            id: 'dec-3',
            contentId: 'post-789',
            contentType: 'post',
            decision: 'remove',
            reason: 'Test',
            policyId: 'policy-1',
            detectionMethod: $method,
            decidedAt: new DateTimeImmutable(),
            appealUrl: 'https://example.com/appeal',
        );

        self::assertSame($expected, $decision->isAutomated());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function trustedFlaggerDetectionProvider(): iterable
    {
        yield 'trusted_flagger is from trusted flagger' => ['trusted_flagger', true];
        yield 'human is not from trusted flagger' => ['human', false];
        yield 'automated is not from trusted flagger' => ['automated', false];
        yield 'notice is not from trusted flagger' => ['notice', false];
    }

    #[Test]
    #[DataProvider('trustedFlaggerDetectionProvider')]
    public function isFromTrustedFlaggerReturnsCorrectResult(string $method, bool $expected): void
    {
        $decision = new ModerationDecision(
            id: 'dec-4',
            contentId: 'post-abc',
            contentType: 'post',
            decision: 'label',
            reason: 'Misinformation',
            policyId: 'policy-misinfo',
            detectionMethod: $method,
            decidedAt: new DateTimeImmutable(),
            appealUrl: 'https://example.com/appeal',
        );

        self::assertSame($expected, $decision->isFromTrustedFlagger());
    }

    #[Test]
    public function toArrayWithMinimalDecision(): void
    {
        $decidedAt = new DateTimeImmutable('2026-06-01T14:30:00+02:00');
        $decision = new ModerationDecision(
            id: 'dec-5',
            contentId: 'post-min',
            contentType: 'comment',
            decision: 'demote',
            reason: 'Low quality content',
            policyId: 'policy-quality',
            detectionMethod: 'automated',
            decidedAt: $decidedAt,
            appealUrl: 'https://example.com/appeal/dec-5',
        );

        $array = $decision->toArray();

        self::assertSame('dec-5', $array['id']);
        self::assertSame('post-min', $array['content_id']);
        self::assertSame('comment', $array['content_type']);
        self::assertSame('demote', $array['decision']);
        self::assertSame('Low quality content', $array['reason']);
        self::assertSame('policy-quality', $array['policy_id']);
        self::assertSame('automated', $array['detection_method']);
        self::assertSame('2026-06-01T14:30:00+02:00', $array['decided_at']);
        self::assertSame('https://example.com/appeal/dec-5', $array['appeal_url']);
        self::assertArrayNotHasKey('moderator_id', $array);
        self::assertArrayNotHasKey('territory', $array);
    }

    #[Test]
    public function toArrayWithFullDecision(): void
    {
        $decidedAt = new DateTimeImmutable('2026-06-01T14:30:00+00:00');
        $decision = new ModerationDecision(
            id: 'dec-6',
            contentId: 'post-full',
            contentType: 'media',
            decision: 'remove',
            reason: 'Illegal content',
            policyId: 'policy-illegal',
            detectionMethod: 'trusted_flagger',
            decidedAt: $decidedAt,
            appealUrl: 'https://example.com/appeal/dec-6',
            moderatorId: 'mod-99',
            territory: 'FR',
        );

        $array = $decision->toArray();

        self::assertSame('mod-99', $array['moderator_id']);
        self::assertSame('FR', $array['territory']);
    }
}
