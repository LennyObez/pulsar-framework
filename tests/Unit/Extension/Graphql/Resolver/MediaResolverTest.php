<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Graphql\Resolver;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Media\MediaAsset;
use Pulsar\Extension\Cms\Media\MediaRepositoryInterface;
use Pulsar\Extension\Cms\Media\MediaVisibility;
use Pulsar\Extension\Graphql\Resolver\MediaResolver;

#[CoversClass(MediaResolver::class)]
final class MediaResolverTest extends TestCase
{
    private MediaRepositoryInterface&Stub $mediaRepo;
    private MediaResolver $resolver;

    protected function setUp(): void
    {
        $this->mediaRepo = $this->createStub(MediaRepositoryInterface::class);
        $this->resolver = new MediaResolver($this->mediaRepo);
    }

    #[Test]
    public function resolve_by_id_returns_null_when_not_found(): void
    {
        $this->mediaRepo->method('findById')->willReturn(null);

        self::assertNull($this->resolver->resolveById('nonexistent'));
    }

    #[Test]
    public function resolve_by_id_returns_media_data(): void
    {
        $now = new DateTimeImmutable('2025-02-20T08:30:00+00:00');
        $asset = new MediaAsset(
            id: 'media-001',
            tenantId: null,
            uploaderId: 'u-001',
            filename: 'photo.jpg',
            storagePath: 'uploads/2025/02/photo.jpg',
            disk: 'local',
            mimeType: 'image/jpeg',
            fileSize: 524288,
            fileHash: 'abc123def456',
            width: 1920,
            height: 1080,
            exifData: null,
            altTextDefault: 'A beautiful photo',
            visibility: MediaVisibility::Public,
            dataClassification: DataClassification::Public,
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null,
        );

        $this->mediaRepo->method('findById')->willReturn($asset);

        $result = $this->resolver->resolveById('media-001');

        self::assertNotNull($result);
        self::assertSame('media-001', $result['id']);
        self::assertSame('photo.jpg', $result['filename']);
        self::assertSame('image/jpeg', $result['mimeType']);
        self::assertSame(524288, $result['fileSize']);
        self::assertSame(1920, $result['width']);
        self::assertSame(1080, $result['height']);
        self::assertSame('A beautiful photo', $result['altTextDefault']);
        self::assertSame('public', $result['visibility']);
    }
}
