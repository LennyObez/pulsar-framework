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
 * @api
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
     * @param array{
     *     title?: string|null,
     *     artist?: string|null,
     *     album?: string|null,
     *     genre?: string|null,
     *     year?: int|null,
     *     track_number?: int|null,
     *     duration?: float|int|null,
     *     bitrate?: int|null,
     *     sample_rate?: int|null,
     *     channels?: int|null,
     *     codec?: string|null,
     *     format?: string|null,
     *     file_size?: int|null,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            title: $data['title'] ?? null,
            artist: $data['artist'] ?? null,
            album: $data['album'] ?? null,
            genre: $data['genre'] ?? null,
            year: $data['year'] ?? null,
            trackNumber: $data['track_number'] ?? null,
            duration: self::toNullableFloat($data['duration'] ?? null),
            bitrate: $data['bitrate'] ?? null,
            sampleRate: $data['sample_rate'] ?? null,
            channels: $data['channels'] ?? null,
            codec: $data['codec'] ?? null,
            format: $data['format'] ?? null,
            fileSize: $data['file_size'] ?? null,
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
