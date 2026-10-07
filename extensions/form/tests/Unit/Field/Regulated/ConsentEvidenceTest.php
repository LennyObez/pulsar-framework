<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Tests\Unit\Field\Regulated;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Field\Regulated\ConsentEvidence;

final class ConsentEvidenceTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $evidence = new ConsentEvidence(
            timestamp: '2024-01-01T00:00:00+00:00',
            purpose: 'marketing',
            policyVersion: 'v1.0',
            locale: 'en_US',
            subject: 'user@example.com',
            correlationId: 'abc-123',
            templateHash: 'tpl_hash',
            policyTextHash: 'policy_hash',
        );

        self::assertSame('2024-01-01T00:00:00+00:00', $evidence->timestamp);
        self::assertSame('marketing', $evidence->purpose);
        self::assertSame('v1.0', $evidence->policyVersion);
        self::assertSame('en_US', $evidence->locale);
        self::assertSame('user@example.com', $evidence->subject);
        self::assertSame('abc-123', $evidence->correlationId);
    }

    #[Test]
    public function toArrayProducesExpectedKeys(): void
    {
        $evidence = new ConsentEvidence(
            timestamp: '2024-01-01T00:00:00+00:00',
            purpose: 'analytics',
            policyVersion: 'v2.0',
            locale: 'fr_FR',
            subject: 'user123',
            correlationId: 'corr-456',
            templateHash: 'tpl_abc',
            policyTextHash: 'pol_def',
        );

        $array = $evidence->toArray();

        self::assertSame('2024-01-01T00:00:00+00:00', $array['timestamp']);
        self::assertSame('analytics', $array['purpose']);
        self::assertSame('v2.0', $array['policy_version']);
        self::assertSame('fr_FR', $array['locale']);
        self::assertSame('user123', $array['subject']);
        self::assertSame('corr-456', $array['correlation_id']);
        self::assertSame('tpl_abc', $array['template_hash']);
        self::assertSame('pol_def', $array['policy_text_hash']);
    }
}
