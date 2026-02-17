<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Event\Payload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Event\Payload\FeatureFlagPayload;

#[CoversClass(FeatureFlagPayload::class)]
final class FeatureFlagPayloadTest extends TestCase
{
    #[Test]
    public function eventTypeReturnsFeatureFlagEval(): void
    {
        self::assertSame(EventType::FeatureFlagEval, $this->createPayload()->eventType());
    }

    #[Test]
    public function schemaVersionReturnsV1(): void
    {
        self::assertSame(EventVersion::V1, $this->createPayload()->schemaVersion());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $payload = $this->createPayload();
        $array = $payload->toArray();

        self::assertSame('dark-mode', $array['flag_name']);
        self::assertTrue($array['result']);
        self::assertSame('percentage-rollout', $array['reason']);
        self::assertSame('user-42', $array['context_identifier']);
    }

    #[Test]
    public function defaultContextIdentifierIsNull(): void
    {
        $payload = new FeatureFlagPayload(
            flagName: 'beta-feature',
            result: false,
            reason: 'disabled',
        );

        self::assertNull($payload->contextIdentifier);
        self::assertNull($payload->toArray()['context_identifier']);
    }

    #[Test]
    public function flagEvaluatedToFalse(): void
    {
        $payload = new FeatureFlagPayload(
            flagName: 'new-checkout',
            result: false,
            reason: 'not-in-segment',
            contextIdentifier: 'org-99',
        );

        self::assertFalse($payload->result);
        self::assertSame('not-in-segment', $payload->reason);
    }

    private function createPayload(): FeatureFlagPayload
    {
        return new FeatureFlagPayload(
            flagName: 'dark-mode',
            result: true,
            reason: 'percentage-rollout',
            contextIdentifier: 'user-42',
        );
    }
}
