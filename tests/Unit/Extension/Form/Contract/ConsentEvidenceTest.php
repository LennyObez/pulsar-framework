<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Form\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Field\Regulated\ConsentEvidence;

#[CoversClass(ConsentEvidence::class)]
final class ConsentEvidenceTest extends TestCase
{
    #[Test]
    public function toArrayReturnsAllFields(): void
    {
        $evidence = new ConsentEvidence(
            timestamp: '2026-03-08T12:00:00+00:00',
            purpose: 'marketing',
            policyVersion: 'v2.1',
            locale: 'en-US',
            subject: 'user-42',
            correlationId: 'req-abc-123',
            templateHash: 'sha256-template',
            policyTextHash: 'sha256-policy',
        );

        $array = $evidence->toArray();

        self::assertSame('2026-03-08T12:00:00+00:00', $array['timestamp']);
        self::assertSame('marketing', $array['purpose']);
        self::assertSame('v2.1', $array['policy_version']);
        self::assertSame('en-US', $array['locale']);
        self::assertSame('user-42', $array['subject']);
        self::assertSame('req-abc-123', $array['correlation_id']);
        self::assertSame('sha256-template', $array['template_hash']);
        self::assertSame('sha256-policy', $array['policy_text_hash']);
    }

    #[Test]
    public function toArrayReturnsExactlyEightKeys(): void
    {
        $evidence = new ConsentEvidence(
            timestamp: 't',
            purpose: 'p',
            policyVersion: 'v',
            locale: 'l',
            subject: 's',
            correlationId: 'c',
            templateHash: 'th',
            policyTextHash: 'ph',
        );

        self::assertCount(8, $evidence->toArray());
    }

    #[Test]
    public function propertiesAreAccessible(): void
    {
        $evidence = new ConsentEvidence(
            timestamp: '2026-01-01T00:00:00Z',
            purpose: 'consent',
            policyVersion: 'v1',
            locale: 'fr',
            subject: 'user-1',
            correlationId: 'corr-1',
            templateHash: 'tmpl-hash',
            policyTextHash: 'policy-hash',
        );

        self::assertSame('2026-01-01T00:00:00Z', $evidence->timestamp);
        self::assertSame('consent', $evidence->purpose);
        self::assertSame('v1', $evidence->policyVersion);
        self::assertSame('fr', $evidence->locale);
        self::assertSame('user-1', $evidence->subject);
        self::assertSame('corr-1', $evidence->correlationId);
        self::assertSame('tmpl-hash', $evidence->templateHash);
        self::assertSame('policy-hash', $evidence->policyTextHash);
    }
}
