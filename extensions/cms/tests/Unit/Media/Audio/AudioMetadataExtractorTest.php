<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Media\Audio;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Extension\Cms\Media\Audio\AudioConfig;
use Pulsar\Extension\Cms\Media\Audio\AudioMetadataExtractor;
use Pulsar\Extension\Cms\Media\Video\FfmpegProcessInterface;

use function json_encode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(AudioMetadataExtractor::class)]
final class AudioMetadataExtractorTest extends TestCase
{
    private AudioConfig $config;
    private FfmpegProcessInterface&Stub $process;
    private AudioMetadataExtractor $extractor;

    protected function setUp(): void
    {
        $this->config = new AudioConfig();
        $this->process = $this->createStub(FfmpegProcessInterface::class);
        $this->extractor = new AudioMetadataExtractor(
            $this->config,
            $this->process,
            new NullLogger(),
        );
    }

    #[Test]
    public function extractReturnsEmptyMetadataForMissingFile(): void
    {
        $result = $this->extractor->extract('/nonexistent/file.mp3');

        self::assertNull($result->title);
        self::assertNull($result->duration);
    }

    #[Test]
    public function extractParsesFullFfprobeOutput(): void
    {
        $tempFile = $this->createTempFile();

        $this->process->method('probe')->willReturn(json_encode([
            'format' => [
                'duration' => '215.5',
                'size' => '8600000',
                'format_name' => 'mp3',
                'bit_rate' => '320000',
                'tags' => [
                    'title' => 'Test Song',
                    'artist' => 'Test Artist',
                    'album' => 'Test Album',
                    'genre' => 'Rock',
                    'date' => '2024',
                    'track' => '5/12',
                ],
            ],
            'streams' => [
                [
                    'codec_type' => 'audio',
                    'codec_name' => 'mp3',
                    'sample_rate' => '44100',
                    'channels' => 2,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $result = $this->extractor->extract($tempFile);

        self::assertSame('Test Song', $result->title);
        self::assertSame('Test Artist', $result->artist);
        self::assertSame('Test Album', $result->album);
        self::assertSame('Rock', $result->genre);
        self::assertSame(2024, $result->year);
        self::assertSame(5, $result->trackNumber);
        self::assertSame(215.5, $result->duration);
        self::assertSame(320000, $result->bitrate);
        self::assertSame(44100, $result->sampleRate);
        self::assertSame(2, $result->channels);
        self::assertSame('mp3', $result->codec);
        self::assertSame('mp3', $result->format);
        self::assertSame(8600000, $result->fileSize);
    }

    #[Test]
    public function extractReturnsEmptyMetadataOnNullProbeOutput(): void
    {
        $tempFile = $this->createTempFile();

        $this->process->method('probe')->willReturn(null);

        $result = $this->extractor->extract($tempFile);

        self::assertNull($result->title);
        self::assertNull($result->duration);
    }

    #[Test]
    public function extractReturnsEmptyMetadataOnInvalidJson(): void
    {
        $tempFile = $this->createTempFile();

        $this->process->method('probe')->willReturn('not valid json{{{');

        $result = $this->extractor->extract($tempFile);

        self::assertNull($result->title);
    }

    #[Test]
    public function extractHandlesMissingTags(): void
    {
        $tempFile = $this->createTempFile();

        $this->process->method('probe')->willReturn(json_encode([
            'format' => [
                'duration' => '120.0',
                'format_name' => 'ogg',
            ],
            'streams' => [
                [
                    'codec_type' => 'audio',
                    'codec_name' => 'vorbis',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $result = $this->extractor->extract($tempFile);

        self::assertNull($result->title);
        self::assertNull($result->artist);
        self::assertSame(120.0, $result->duration);
        self::assertSame('vorbis', $result->codec);
    }

    #[Test]
    public function extractParsesDateWithFullIsoFormat(): void
    {
        $tempFile = $this->createTempFile();

        $this->process->method('probe')->willReturn(json_encode([
            'format' => [
                'tags' => [
                    'date' => '2023-07-15',
                ],
            ],
            'streams' => [],
        ], JSON_THROW_ON_ERROR));

        $result = $this->extractor->extract($tempFile);

        self::assertSame(2023, $result->year);
    }

    #[Test]
    public function extractHandlesUpperCaseTagKeys(): void
    {
        $tempFile = $this->createTempFile();

        $this->process->method('probe')->willReturn(json_encode([
            'format' => [
                'tags' => [
                    'TITLE' => 'Uppercase Title',
                    'ARTIST' => 'Uppercase Artist',
                    'ALBUM' => 'Uppercase Album',
                    'TRACKNUMBER' => '3',
                ],
            ],
            'streams' => [],
        ], JSON_THROW_ON_ERROR));

        $result = $this->extractor->extract($tempFile);

        self::assertSame('Uppercase Title', $result->title);
        self::assertSame('Uppercase Artist', $result->artist);
        self::assertSame('Uppercase Album', $result->album);
        self::assertSame(3, $result->trackNumber);
    }

    private function createTempFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pulsar_test_audio_');
        self::assertNotFalse($path);
        file_put_contents($path, 'test content');

        return $path;
    }
}
