<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Storage\StorageMetadata;

#[CoversClass(StorageMetadata::class)]
final class StorageMetadataTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $meta = new StorageMetadata();

        self::assertNull($meta->contentType);
        self::assertNull($meta->cacheControl);
        self::assertSame([], $meta->customHeaders);
    }

    #[Test]
    public function withAllValues(): void
    {
        $meta = new StorageMetadata(
            contentType: 'application/pdf',
            cacheControl: 'max-age=3600',
            customHeaders: ['X-Custom' => 'value'],
        );

        self::assertSame('application/pdf', $meta->contentType);
        self::assertSame('max-age=3600', $meta->cacheControl);
        self::assertSame(['X-Custom' => 'value'], $meta->customHeaders);
    }
}
