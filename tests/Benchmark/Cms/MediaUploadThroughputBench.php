<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark\Cms;

use DateTimeImmutable;
use Override;
use PhpBench\Attributes\Assert;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Subject;
use PhpBench\Attributes\Warmup;
use Pulsar\Extension\Cms\Http\Controller\MediaController;
use Pulsar\Extension\Cms\Media\MediaAsset;
use Pulsar\Extension\Cms\Media\MediaDerivative;
use Pulsar\Extension\Cms\Media\MediaDiskInterface;
use Pulsar\Extension\Cms\Media\MediaRepositoryInterface;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Tests\Benchmark\Cms\Support\CmsBenchmarkFactory;

use function str_repeat;

/**
 * Media upload and serve throughput benchmark.
 *
 * Measures MediaController::serve() and ::serveOriginal() with in-memory
 * repository and disk. Validates that media serving overhead is minimal
 * (lookup + header construction).
 *
 * Target: p99 < 100 microseconds for derivative serving.
 */
#[BeforeMethods('setUp')]
#[Revs(1000)]
#[Iterations(5)]
#[Warmup(2)]
final class MediaUploadThroughputBench
{
    private MediaController $controller;
    private ServerRequest $request;
    private MediaAsset $asset;

    public function setUp(): void
    {
        $factory = new CmsBenchmarkFactory();
        $this->asset = $factory->createMediaAsset();
        $fakeContent = str_repeat("\xFF\xD8\xFF\xE0", 1024); // 4KB of fake JPEG data

        $derivative = new MediaDerivative(
            id: $factory->generateUuidV7(),
            mediaAssetId: $this->asset->id,
            variant: 'thumbnail',
            format: 'webp',
            storagePath: 'derivatives/' . $this->asset->fileHash . '/thumbnail.webp',
            fileSize: 4096,
            width: 300,
            height: 200,
            fileHash: 'derivhash123',
            createdAt: new DateTimeImmutable(),
        );

        $mediaRepo = new class ($this->asset, $derivative) implements MediaRepositoryInterface {
            public function __construct(
                private readonly MediaAsset $asset,
                private readonly MediaDerivative $derivative,
            ) {}

            #[Override]
            public function findById(string $id): MediaAsset
            {
                return $this->asset;
            }

            #[Override]
            public function findByHash(string $hash): MediaAsset
            {
                return $this->asset;
            }

            #[Override]
            public function listAssets(?string $tenantId, int $page, int $perPage, ?string $mimeType = null, ?string $visibility = null): \Pulsar\Api\Pagination\PaginationResult
            {
                return new \Pulsar\Api\Pagination\PaginationResult(items: [$this->asset], total: 1, hasMore: false, perPage: $perPage);
            }

            #[Override]
            public function save(MediaAsset $asset): void {}

            #[Override]
            public function delete(MediaAsset $asset): void {}

            #[Override]
            public function findDerivatives(string $assetId): array
            {
                return [$this->derivative];
            }

            #[Override]
            public function saveDerivative(MediaDerivative $derivative): void {}

            #[Override]
            public function saveTranslation(\Pulsar\Extension\Cms\Media\MediaAssetTranslation $translation): void {}

            #[Override]
            public function findTranslations(string $assetId): array
            {
                return [];
            }
        };

        $disk = new class ($fakeContent) implements MediaDiskInterface {
            public function __construct(private readonly string $content) {}

            #[Override]
            public function read(string $path): string
            {
                return $this->content;
            }

            #[Override]
            public function write(string $path, string $contents): void {}

            #[Override]
            public function delete(string $path): void {}

            #[Override]
            public function exists(string $path): bool
            {
                return true;
            }

            #[Override]
            public function url(string $path): string
            {
                return '/media/' . $path;
            }
        };

        $this->controller = new MediaController($mediaRepo, $disk);
        $this->request = new ServerRequest(method: 'GET', uri: '/media/thumbnail/' . $this->asset->fileHash . '/image.webp');
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 100 microseconds')]
    public function benchServeDerivative(): void
    {
        $response = $this->controller->serve(
            $this->request,
            'thumbnail',
            $this->asset->fileHash,
            'image',
            'webp',
        );
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 100 microseconds')]
    public function benchServeOriginal(): void
    {
        $response = $this->controller->serveOriginal(
            $this->request,
            $this->asset->fileHash,
            'benchmark-image.jpg',
        );
    }
}
