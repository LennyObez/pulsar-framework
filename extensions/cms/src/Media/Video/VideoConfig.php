<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media\Video;

use Pulsar\Api\Api;

use function is_array;

/**
 * Configuration for video processing and transcoding.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class VideoConfig
{
    /**
     * @param string $ffmpegPath Path to ffmpeg binary
     * @param string $ffprobePath Path to ffprobe binary
     * @param bool $hlsEnabled Whether HLS adaptive bitrate streaming is enabled
     * @param int $hlsSegmentDuration HLS segment duration in seconds
     * @param array<string, TranscodePreset> $transcodePresets Named transcode presets
     * @param int $maxUploadSize Maximum video upload size in bytes (default: 500 MB)
     * @param int $processTimeout Maximum processing time in seconds
     * @param float $thumbnailTimestamp Timestamp in seconds to extract thumbnail (0.0 = first frame)
     */
    public function __construct(
        public string $ffmpegPath = 'ffmpeg',
        public string $ffprobePath = 'ffprobe',
        public bool $hlsEnabled = true,
        public int $hlsSegmentDuration = 6,
        public array $transcodePresets = [],
        public int $maxUploadSize = 524_288_000,
        public int $processTimeout = 600,
        public float $thumbnailTimestamp = 1.0,
    ) {}

    /**
     * @param array{
     *     ffmpeg_path?: string,
     *     ffprobe_path?: string,
     *     hls_enabled?: bool,
     *     hls_segment_duration?: int,
     *     transcode_presets?: array<string, array<string, mixed>>,
     *     max_upload_size?: int,
     *     process_timeout?: int,
     *     thumbnail_timestamp?: float|int,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $presets = [];
        foreach ($data['transcode_presets'] ?? [] as $name => $preset) {
            if (is_array($preset)) {
                $presets[$name] = TranscodePreset::fromArray($preset);
            }
        }

        if ($presets === []) {
            $presets = self::defaultPresets();
        }

        return new self(
            ffmpegPath: $data['ffmpeg_path'] ?? 'ffmpeg',
            ffprobePath: $data['ffprobe_path'] ?? 'ffprobe',
            hlsEnabled: $data['hls_enabled'] ?? true,
            hlsSegmentDuration: $data['hls_segment_duration'] ?? 6,
            transcodePresets: $presets,
            maxUploadSize: $data['max_upload_size'] ?? 524_288_000,
            processTimeout: $data['process_timeout'] ?? 600,
            thumbnailTimestamp: (float) ($data['thumbnail_timestamp'] ?? 1.0),
        );
    }

    /**
     * @return array<string, TranscodePreset>
     */
    private static function defaultPresets(): array
    {
        return [
            '360p' => new TranscodePreset(width: 640, height: 360, videoBitrate: '800k', audioBitrate: '96k', codec: 'libx264'),
            '720p' => new TranscodePreset(width: 1280, height: 720, videoBitrate: '2500k', audioBitrate: '128k', codec: 'libx264'),
            '1080p' => new TranscodePreset(width: 1920, height: 1080, videoBitrate: '5000k', audioBitrate: '192k', codec: 'libx264'),
        ];
    }
}
