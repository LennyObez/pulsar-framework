<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Build;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Build\BuildMetadata;

#[CoversClass(BuildMetadata::class)]
final class BuildMetadataTest extends TestCase
{
    #[Test]
    public function fromArrayCreatesMetadataWithAllFields(): void
    {
        $metadata = BuildMetadata::fromArray([
            'builtAt' => '2026-02-18T12:00:00Z',
            'phpVersion' => '8.5.0',
            'pulsarVersion' => '1.0.0-rc.11',
            'host' => 'build-server-01',
        ]);

        self::assertSame('2026-02-18T12:00:00Z', $metadata->builtAt);
        self::assertSame('8.5.0', $metadata->phpVersion);
        self::assertSame('1.0.0-rc.11', $metadata->pulsarVersion);
        self::assertSame('build-server-01', $metadata->host);
    }

    #[Test]
    public function fromArrayDefaultsMissingFields(): void
    {
        $metadata = BuildMetadata::fromArray([]);

        self::assertSame('', $metadata->builtAt);
        self::assertSame('', $metadata->phpVersion);
        self::assertSame('', $metadata->pulsarVersion);
        self::assertNull($metadata->host);
    }

    #[Test]
    public function toArrayExportsAllFields(): void
    {
        $metadata = new BuildMetadata(
            builtAt: '2026-02-18T12:00:00Z',
            phpVersion: '8.5.0',
            pulsarVersion: '1.0.0-rc.11',
            host: 'ci-node-3',
        );

        self::assertSame([
            'builtAt' => '2026-02-18T12:00:00Z',
            'phpVersion' => '8.5.0',
            'pulsarVersion' => '1.0.0-rc.11',
            'host' => 'ci-node-3',
        ], $metadata->toArray());
    }

    #[Test]
    public function fromArrayToArrayRoundTrip(): void
    {
        $data = [
            'builtAt' => '2026-01-15T08:30:00Z',
            'phpVersion' => '8.5.1',
            'pulsarVersion' => '1.0.0',
            'host' => 'prod-builder',
        ];

        $metadata = BuildMetadata::fromArray($data);

        self::assertSame($data, $metadata->toArray());
    }

    #[Test]
    public function toJsonProducesValidJson(): void
    {
        $metadata = new BuildMetadata(
            builtAt: '2026-02-18T12:00:00Z',
            phpVersion: '8.5.0',
            pulsarVersion: '1.0.0-rc.11',
        );

        $json = $metadata->toJson();
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertSame('2026-02-18T12:00:00Z', $decoded['builtAt']);
        self::assertSame('8.5.0', $decoded['phpVersion']);
        self::assertSame('1.0.0-rc.11', $decoded['pulsarVersion']);
        self::assertNull($decoded['host']);
    }
}
