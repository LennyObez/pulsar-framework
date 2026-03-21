<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media\Video;

use Pulsar\Api\Api;

use function array_filter;
use function is_float;
use function is_int;
use function is_string;
use function sprintf;

/**
 * Structured metadata extracted from a video file.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class VideoMetadata
{
    public function __construct(
        public ?float $duration = null,
        public ?int $width = null,
        public ?int $height = null,
        public ?string $videoCodec = null,
        public ?string $audioCodec = null,
        public ?float $framerate = null,
        public ?int $videoBitrate = null,
        public ?int $audioBitrate = null,
        public ?int $audioSampleRate = null,
        public ?int $audioChannels = null,
        public ?string $format = null,
        public ?int $fileSize = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            duration: self::toNullableFloat($data['duration'] ?? null),
            width: is_int($data['width'] ?? null) ? $data['width'] : null,
            height: is_int($data['height'] ?? null) ? $data['height'] : null,
            videoCodec: is_string($data['video_codec'] ?? null) ? $data['video_codec'] : null,
            audioCodec: is_string($data['audio_codec'] ?? null) ? $data['audio_codec'] : null,
            framerate: self::toNullableFloat($data['framerate'] ?? null),
            videoBitrate: is_int($data['video_bitrate'] ?? null) ? $data['video_bitrate'] : null,
            audioBitrate: is_int($data['audio_bitrate'] ?? null) ? $data['audio_bitrate'] : null,
            audioSampleRate: is_int($data['audio_sample_rate'] ?? null) ? $data['audio_sample_rate'] : null,
            audioChannels: is_int($data['audio_channels'] ?? null) ? $data['audio_channels'] : null,
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
            'duration' => $this->duration,
            'width' => $this->width,
            'height' => $this->height,
            'video_codec' => $this->videoCodec,
            'audio_codec' => $this->audioCodec,
            'framerate' => $this->framerate,
            'video_bitrate' => $this->videoBitrate,
            'audio_bitrate' => $this->audioBitrate,
            'audio_sample_rate' => $this->audioSampleRate,
            'audio_channels' => $this->audioChannels,
            'format' => $this->format,
            'file_size' => $this->fileSize,
        ], static fn(mixed $v): bool => $v !== null);
    }

    public function getResolution(): ?string
    {
        if ($this->width === null || $this->height === null) {
            return null;
        }

        return $this->width . 'x' . $this->height;
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
