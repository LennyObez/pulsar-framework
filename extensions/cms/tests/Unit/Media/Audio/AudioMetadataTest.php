<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Media\Audio;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Media\Audio\AudioMetadata;

#[CoversClass(AudioMetadata::class)]
final class AudioMetadataTest extends TestCase
{
    #[Test]
    public function defaultConstructorHasAllNulls(): void
    {
        $meta = new AudioMetadata();

        self::assertNull($meta->title);
        self::assertNull($meta->artist);
        self::assertNull($meta->album);
        self::assertNull($meta->duration);
        self::assertNull($meta->bitrate);
        self::assertNull($meta->sampleRate);
        self::assertNull($meta->channels);
        self::assertNull($meta->codec);
        self::assertNull($meta->format);
        self::assertNull($meta->fileSize);
        self::assertNull($meta->genre);
        self::assertNull($meta->year);
        self::assertNull($meta->trackNumber);
    }

    #[Test]
    public function fromArrayParsesAllFields(): void
    {
        $meta = AudioMetadata::fromArray([
            'title' => 'Test Song',
            'artist' => 'Test Artist',
            'album' => 'Test Album',
            'genre' => 'Rock',
            'year' => 2024,
            'track_number' => 5,
            'duration' => 215.5,
            'bitrate' => 320000,
            'sample_rate' => 44100,
            'channels' => 2,
            'codec' => 'mp3',
            'format' => 'mp3',
            'file_size' => 8600000,
        ]);

        self::assertSame('Test Song', $meta->title);
        self::assertSame('Test Artist', $meta->artist);
        self::assertSame('Test Album', $meta->album);
        self::assertSame('Rock', $meta->genre);
        self::assertSame(2024, $meta->year);
        self::assertSame(5, $meta->trackNumber);
        self::assertSame(215.5, $meta->duration);
        self::assertSame(320000, $meta->bitrate);
        self::assertSame(44100, $meta->sampleRate);
        self::assertSame(2, $meta->channels);
        self::assertSame('mp3', $meta->codec);
        self::assertSame('mp3', $meta->format);
        self::assertSame(8600000, $meta->fileSize);
    }

    #[Test]
    public function toArrayExcludesNulls(): void
    {
        $meta = new AudioMetadata(title: 'Hello', duration: 30.0);

        $array = $meta->toArray();

        self::assertSame(['title' => 'Hello', 'duration' => 30.0], $array);
    }

    #[Test]
    public function toArrayIncludesAllNonNullFields(): void
    {
        $meta = new AudioMetadata(
            title: 'Song',
            artist: 'Artist',
            album: 'Album',
            genre: 'Pop',
            year: 2025,
            trackNumber: 1,
            duration: 180.0,
            bitrate: 128000,
            sampleRate: 44100,
            channels: 2,
            codec: 'aac',
            format: 'm4a',
            fileSize: 5000000,
        );

        $array = $meta->toArray();

        self::assertCount(13, $array);
        self::assertSame('Song', $array['title']);
        self::assertSame('Artist', $array['artist']);
    }

    #[Test]
    #[DataProvider('durationProvider')]
    public function getFormattedDurationFormatsCorrectly(?float $duration, ?string $expected): void
    {
        $meta = new AudioMetadata(duration: $duration);

        self::assertSame($expected, $meta->getFormattedDuration());
    }

    /**
     * @return iterable<string, array{?float, ?string}>
     */
    public static function durationProvider(): iterable
    {
        yield 'null' => [null, null];
        yield 'zero' => [0.0, '0:00'];
        yield 'thirty seconds' => [30.0, '0:30'];
        yield 'one minute' => [60.0, '1:00'];
        yield 'with seconds' => [93.7, '1:33'];
        yield 'ten minutes' => [600.0, '10:00'];
        yield 'over one hour' => [3661.0, '1:01:01'];
    }

    #[Test]
    public function getDisplayTitleReturnsTitle(): void
    {
        $meta = new AudioMetadata(title: 'My Song');

        self::assertSame('My Song', $meta->getDisplayTitle());
    }

    #[Test]
    public function getDisplayTitleReturnsUntitledWhenEmpty(): void
    {
        $meta = new AudioMetadata();

        self::assertSame('Untitled', $meta->getDisplayTitle());
    }

    #[Test]
    public function getDisplayTitleReturnsUntitledForEmptyString(): void
    {
        $meta = new AudioMetadata(title: '');

        self::assertSame('Untitled', $meta->getDisplayTitle());
    }

    #[Test]
    public function fromArrayHandlesInvalidTypes(): void
    {
        $meta = AudioMetadata::fromArray([
            'title' => 123,
            'duration' => 'not_a_number',
            'bitrate' => 'invalid',
            'year' => 'bad',
        ]);

        self::assertNull($meta->title);
        self::assertNull($meta->duration);
        self::assertNull($meta->bitrate);
        self::assertNull($meta->year);
    }

    #[Test]
    public function fromArrayConvertsIntDurationToFloat(): void
    {
        $meta = AudioMetadata::fromArray([
            'duration' => 120,
        ]);

        self::assertSame(120.0, $meta->duration);
    }

    #[Test]
    public function fromArrayConvertsNumericStringDurationToFloat(): void
    {
        $meta = AudioMetadata::fromArray([
            'duration' => '45.5',
        ]);

        self::assertSame(45.5, $meta->duration);
    }
}
