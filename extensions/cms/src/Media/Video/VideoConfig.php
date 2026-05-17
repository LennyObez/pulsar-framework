<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media\Video;

use Pulsar\Api\Api;

use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;

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
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $rawPresets = is_array($data['transcode_presets'] ?? null) ? $data['transcode_presets'] : [];
        /** @var array<string, TranscodePreset> $presets */
        $presets = [];

        foreach ($rawPresets as $name => $preset) {
            if (is_array($preset) && is_string($name)) {
                /** @var array<string, mixed> $preset */
                $presets[$name] = TranscodePreset::fromArray($preset);
            }
        }

        if ($presets === []) {
            $presets = self::defaultPresets();
        }

        return new self(
            ffmpegPath: is_string($data['ffmpeg_path'] ?? null) ? $data['ffmpeg_path'] : 'ffmpeg',
            ffprobePath: is_string($data['ffprobe_path'] ?? null) ? $data['ffprobe_path'] : 'ffprobe',
            hlsEnabled: is_bool($data['hls_enabled'] ?? null) ? $data['hls_enabled'] : true,
            hlsSegmentDuration: is_int($data['hls_segment_duration'] ?? null) ? $data['hls_segment_duration'] : 6,
            transcodePresets: $presets,
            maxUploadSize: is_int($data['max_upload_size'] ?? null) ? $data['max_upload_size'] : 524_288_000,
            processTimeout: is_int($data['process_timeout'] ?? null) ? $data['process_timeout'] : 600,
            thumbnailTimestamp: isset($data['thumbnail_timestamp']) && (is_int($data['thumbnail_timestamp']) || is_float($data['thumbnail_timestamp']))
                ? (float) $data['thumbnail_timestamp']
                : 1.0,
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
