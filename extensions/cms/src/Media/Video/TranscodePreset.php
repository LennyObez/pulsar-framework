<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media\Video;

use Pulsar\Api\Api;

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
     * @param array{
     *     width?: int,
     *     height?: int,
     *     video_bitrate?: string,
     *     audio_bitrate?: string,
     *     codec?: string,
     *     format?: string,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            width: $data['width'] ?? 1280,
            height: $data['height'] ?? 720,
            videoBitrate: $data['video_bitrate'] ?? '2500k',
            audioBitrate: $data['audio_bitrate'] ?? '128k',
            codec: $data['codec'] ?? 'libx264',
            format: $data['format'] ?? 'mp4',
        );
    }
}
