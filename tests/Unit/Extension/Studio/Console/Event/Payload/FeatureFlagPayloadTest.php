<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Console\Event\Payload;

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
        $payload = new FeatureFlagPayload(
            flagName: 'dark-mode',
            result: true,
            reason: 'targeting_match',
        );

        self::assertSame(EventType::FeatureFlagEval, $payload->eventType());
    }

    #[Test]
    public function schemaVersionReturnsV1(): void
    {
        $payload = new FeatureFlagPayload(
            flagName: 'test',
            result: false,
            reason: 'default',
        );

        self::assertSame(EventVersion::V1, $payload->schemaVersion());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $payload = new FeatureFlagPayload(
            flagName: 'beta-feature',
            result: true,
            reason: 'percentage_rollout',
            contextIdentifier: 'user:42',
        );

        $data = $payload->toArray();

        self::assertSame('beta-feature', $data['flag_name']);
        self::assertTrue($data['result']);
        self::assertSame('percentage_rollout', $data['reason']);
        self::assertSame('user:42', $data['context_identifier']);
    }
}
