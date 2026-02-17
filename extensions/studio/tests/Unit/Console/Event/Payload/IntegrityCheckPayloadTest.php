<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Event\Payload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Event\Payload\IntegrityCheckPayload;

#[CoversClass(IntegrityCheckPayload::class)]
final class IntegrityCheckPayloadTest extends TestCase
{
    #[Test]
    public function eventTypeReturnsIntegrityCheck(): void
    {
        self::assertSame(EventType::IntegrityCheck, $this->createPayload()->eventType());
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

        self::assertTrue($array['passed']);
        self::assertSame(150, $array['verified']);
        self::assertSame(0, $array['modified']);
        self::assertSame(0, $array['missing']);
        self::assertSame(2, $array['added']);
        self::assertSame(1711900000, $array['checked_at']);
    }

    #[Test]
    public function failedCheck(): void
    {
        $payload = new IntegrityCheckPayload(
            passed: false,
            verified: 140,
            modified: 5,
            missing: 3,
            added: 0,
            checkedAt: 1711900100,
        );

        self::assertFalse($payload->passed);
        self::assertSame(5, $payload->modified);
        self::assertSame(3, $payload->missing);
    }

    private function createPayload(): IntegrityCheckPayload
    {
        return new IntegrityCheckPayload(
            passed: true,
            verified: 150,
            modified: 0,
            missing: 0,
            added: 2,
            checkedAt: 1711900000,
        );
    }
}
