<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media\Video;

use Pulsar\Api\Api;

use function is_int;
use function is_string;

/**
 * A named transcoding preset defining output resolution, bitrate, and codec.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TranscodePreset
{
    public function __construct(
        public int $width = 1280,
        public int $height = 720,
        public string $videoBitrate = '2500k',
        public string $audioBitrate = '128k',
        public string $codec = 'libx264',
        public string $format = 'mp4',
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            width: is_int($data['width'] ?? null) ? $data['width'] : 1280,
            height: is_int($data['height'] ?? null) ? $data['height'] : 720,
            videoBitrate: is_string($data['video_bitrate'] ?? null) ? $data['video_bitrate'] : '2500k',
            audioBitrate: is_string($data['audio_bitrate'] ?? null) ? $data['audio_bitrate'] : '128k',
            codec: is_string($data['codec'] ?? null) ? $data['codec'] : 'libx264',
            format: is_string($data['format'] ?? null) ? $data['format'] : 'mp4',
        );
    }
}
