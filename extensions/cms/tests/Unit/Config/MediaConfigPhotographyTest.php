<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Config\MediaConfig;

#[CoversClass(MediaConfig::class)]
final class MediaConfigPhotographyTest extends TestCase
{
    #[Test]
    public function default_max_upload_size_is_50mb(): void
    {
        $config = new MediaConfig();

        self::assertSame(52_428_800, $config->maxUploadSize);
    }

    #[Test]
    public function default_jpeg_quality_is_85(): void
    {
        $config = new MediaConfig();

        self::assertSame(85, $config->jpegQuality);
    }

    #[Test]
    public function default_progressive_jpeg_is_enabled(): void
    {
        $config = new MediaConfig();

        self::assertTrue($config->progressiveJpeg);
    }

    #[Test]
    public function default_preserve_original_is_enabled(): void
    {
        $config = new MediaConfig();

        self::assertTrue($config->preserveOriginal);
    }

    #[Test]
    public function fromArray_parses_jpeg_quality(): void
    {
        $config = MediaConfig::fromArray(['jpeg_quality' => 92]);

        self::assertSame(92, $config->jpegQuality);
    }

    #[Test]
    public function fromArray_uses_default_jpeg_quality_when_missing(): void
    {
        $config = MediaConfig::fromArray([]);

        self::assertSame(85, $config->jpegQuality);
    }

    #[Test]
    public function fromArray_parses_progressive_jpeg(): void
    {
        $config = MediaConfig::fromArray(['progressive_jpeg' => false]);

        self::assertFalse($config->progressiveJpeg);
    }

    #[Test]
    public function fromArray_parses_preserve_original(): void
    {
        $config = MediaConfig::fromArray(['preserve_original' => false]);

        self::assertFalse($config->preserveOriginal);
    }

    #[Test]
    public function fromArray_parses_50mb_upload_default(): void
    {
        $config = MediaConfig::fromArray([]);

        self::assertSame(52_428_800, $config->maxUploadSize);
    }

    #[Test]
    public function fromArray_allows_custom_max_upload_size(): void
    {
        $config = MediaConfig::fromArray(['max_upload_size' => 104_857_600]); // 100MB

        self::assertSame(104_857_600, $config->maxUploadSize);
    }

    #[Test]
    public function fromArray_ignores_invalid_jpeg_quality_type(): void
    {
        $config = MediaConfig::fromArray(['jpeg_quality' => 'high']);

        self::assertSame(85, $config->jpegQuality);
    }

    #[Test]
    public function fromArray_ignores_invalid_progressive_jpeg_type(): void
    {
        $config = MediaConfig::fromArray(['progressive_jpeg' => 'yes']);

        self::assertTrue($config->progressiveJpeg);
    }

    #[Test]
    public function fromArray_ignores_invalid_preserve_original_type(): void
    {
        $config = MediaConfig::fromArray(['preserve_original' => 1]);

        self::assertTrue($config->preserveOriginal);
    }
}
