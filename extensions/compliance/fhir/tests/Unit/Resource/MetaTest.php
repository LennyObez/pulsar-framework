<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Tests\Unit\Resource;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Resource\Coding;
use Pulsar\Extension\Fhir\Resource\Meta;

#[CoversClass(Meta::class)]
final class MetaTest extends TestCase
{
    #[Test]
    public function minimalToArrayReturnsEmpty(): void
    {
        $meta = new Meta();

        self::assertSame([], $meta->toArray());
    }

    #[Test]
    public function fullToArrayIncludesAllFields(): void
    {
        $lastUpdated = new DateTimeImmutable('2026-03-15T10:30:00+00:00');
        $meta = new Meta(
            versionId: '3',
            lastUpdated: $lastUpdated,
            source: 'http://hospital.example.com',
            profile: ['http://hl7.org/fhir/us/core/StructureDefinition/us-core-patient'],
            security: [new Coding(system: 'http://terminology.hl7.org/CodeSystem/v3-ActCode', code: 'R')],
            tag: [new Coding(system: 'http://example.com/tags', code: 'reviewed')],
        );

        $array = $meta->toArray();

        self::assertSame('3', $array['versionId']);
        self::assertStringContainsString('2026-03-15', $array['lastUpdated']);
        self::assertSame('http://hospital.example.com', $array['source']);
        self::assertCount(1, $array['profile']);
        self::assertSame('R', $array['security'][0]['code']);
        self::assertSame('reviewed', $array['tag'][0]['code']);
    }

    #[Test]
    public function fromArrayParsesLastUpdated(): void
    {
        $data = [
            'versionId' => '5',
            'lastUpdated' => '2026-03-15T10:00:00Z',
            'source' => 'http://example.com',
            'profile' => ['http://hl7.org/fhir/StructureDefinition/Patient'],
            'security' => [['code' => 'N']],
            'tag' => [['code' => 'test']],
        ];

        $meta = Meta::fromArray($data);

        self::assertSame('5', $meta->versionId);
        self::assertNotNull($meta->lastUpdated);
        self::assertSame('2026', $meta->lastUpdated->format('Y'));
        self::assertSame('http://example.com', $meta->source);
        self::assertCount(1, $meta->profile);
        self::assertCount(1, $meta->security);
        self::assertSame('N', $meta->security[0]->code);
        self::assertCount(1, $meta->tag);
    }

    #[Test]
    public function fromArrayEmptyReturnsDefaults(): void
    {
        $meta = Meta::fromArray([]);

        self::assertNull($meta->versionId);
        self::assertNull($meta->lastUpdated);
        self::assertNull($meta->source);
        self::assertSame([], $meta->profile);
        self::assertSame([], $meta->security);
        self::assertSame([], $meta->tag);
    }

    #[Test]
    public function fromArrayRejectsNonStringScalars(): void
    {
        $meta = Meta::fromArray([
            'versionId' => 42,
            'lastUpdated' => false,
            'source' => ['array'],
        ]);

        self::assertNull($meta->versionId);
        self::assertNull($meta->lastUpdated);
        self::assertNull($meta->source);
    }

    #[Test]
    public function lastUpdatedFormattedWithMilliseconds(): void
    {
        $meta = new Meta(
            lastUpdated: new DateTimeImmutable('2026-03-15T10:30:00.123+00:00'),
        );

        $array = $meta->toArray();

        self::assertStringContainsString('123', $array['lastUpdated']);
    }
}
