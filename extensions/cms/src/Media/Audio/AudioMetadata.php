<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media\Audio;

use Pulsar\Api\Api;

use function array_filter;
use function is_float;
use function is_int;
use function is_string;
use function sprintf;

/**
 * Structured metadata extracted from an audio file (ID3 tags and stream info).
 *
 * @psalm-api Public DTO returned from AudioMetadataExtractor; consumed by
 *            media services and admin views.
 */
#[Api(since: '1.0.0')]
final readonly class AudioMetadata
{
    public function __construct(
        public ?string $title = null,
        public ?string $artist = null,
        public ?string $album = null,
        public ?string $genre = null,
        public ?int $year = null,
        public ?int $trackNumber = null,
        public ?float $duration = null,
        public ?int $bitrate = null,
        public ?int $sampleRate = null,
        public ?int $channels = null,
        public ?string $codec = null,
        public ?string $format = null,
        public ?int $fileSize = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            title: is_string($data['title'] ?? null) ? $data['title'] : null,
            artist: is_string($data['artist'] ?? null) ? $data['artist'] : null,
            album: is_string($data['album'] ?? null) ? $data['album'] : null,
            genre: is_string($data['genre'] ?? null) ? $data['genre'] : null,
            year: is_int($data['year'] ?? null) ? $data['year'] : null,
            trackNumber: is_int($data['track_number'] ?? null) ? $data['track_number'] : null,
            duration: self::toNullableFloat($data['duration'] ?? null),
            bitrate: is_int($data['bitrate'] ?? null) ? $data['bitrate'] : null,
            sampleRate: is_int($data['sample_rate'] ?? null) ? $data['sample_rate'] : null,
            channels: is_int($data['channels'] ?? null) ? $data['channels'] : null,
            codec: is_string($data['codec'] ?? null) ? $data['codec'] : null,
            format: is_string($data['format'] ?? null) ? $data['format'] : null,
            fileSize: is_int($data['file_size'] ?? null) ? $data['file_size'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'title' => $this->title,
            'artist' => $this->artist,
            'album' => $this->album,
            'genre' => $this->genre,
            'year' => $this->year,
            'track_number' => $this->trackNumber,
            'duration' => $this->duration,
            'bitrate' => $this->bitrate,
            'sample_rate' => $this->sampleRate,
            'channels' => $this->channels,
            'codec' => $this->codec,
            'format' => $this->format,
            'file_size' => $this->fileSize,
        ], static fn(mixed $v): bool => $v !== null);
    }

    public function getFormattedDuration(): ?string
    {
        if ($this->duration === null) {
            return null;
        }

        $totalSeconds = (int) $this->duration;
        $hours = (int) ($totalSeconds / 3600);
        $minutes = (int) (($totalSeconds % 3600) / 60);
        $seconds = $totalSeconds % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
        }

        return sprintf('%d:%02d', $minutes, $seconds);
    }

    public function getDisplayTitle(): string
    {
        if ($this->title !== null && $this->title !== '') {
            return $this->title;
        }

        return 'Untitled';
    }

    private static function toNullableFloat(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }
}
