<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media\Video;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;

use function file_exists;
use function sprintf;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Wraps FFmpeg CLI for video transcoding operations.
 *
 * Converts source video files to target presets (resolution, bitrate, codec).
 * If FFmpeg is unavailable, logs a warning and returns null gracefully.
 *
 * @psalm-api Resolved from the DI container by media upload pipelines.
 */
#[Internal(reason: 'Use VideoProcessor via service container')]
final readonly class VideoProcessor
{
    public function __construct(
        private VideoConfig $config,
        private FfmpegProcess $process,
        private LoggerInterface $logger,
    ) {}

    /**
     * Transcode a video file to a specific preset.
     *
     * @return string|null Path to transcoded file, or null on failure
     */
    public function transcode(string $sourcePath, string $presetName): ?string
    {
        if (!file_exists($sourcePath)) {
            $this->logger->warning('Video source file not found', ['path' => $sourcePath]);

            return null;
        }

        $preset = $this->config->transcodePresets[$presetName] ?? null;

        if ($preset === null) {
            $this->logger->warning('Unknown transcode preset', ['preset' => $presetName]);

            return null;
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'pulsar_video_');

        if ($tempFile === false) {
            return null;
        }

        $outputPath = $tempFile . '.' . $preset->format;

        $codecArgs = [
            '-c:v', $preset->codec,
            '-b:v', $preset->videoBitrate,
            '-c:a', 'aac',
            '-b:a', $preset->audioBitrate,
            '-vf', sprintf('scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2', $preset->width, $preset->height, $preset->width, $preset->height),
            '-movflags', '+faststart',
        ];

        $success = $this->process->transcode(
            $this->config->ffmpegPath,
            $sourcePath,
            $outputPath,
            $codecArgs,
        );

        if (!$success) {
            $this->logger->warning('Video transcode failed', [
                'source' => $sourcePath,
                'preset' => $presetName,
            ]);

            if (file_exists($outputPath)) {
                unlink($outputPath);
            }

            if (file_exists($tempFile)) {
                unlink($tempFile);
            }

            return null;
        }

        // Clean up the temp file without extension
        if (file_exists($tempFile) && $tempFile !== $outputPath) {
            @unlink($tempFile);
        }

        $this->logger->info('Video transcoded successfully', [
            'source' => $sourcePath,
            'preset' => $presetName,
            'output' => $outputPath,
        ]);

        return $outputPath;
    }

    /**
     * Transcode to all configured presets.
     *
     * @return array<string, string> Map of preset name => output path (only successful transcodes)
     */
    public function transcodeAll(string $sourcePath): array
    {
        $results = [];

        foreach ($this->config->transcodePresets as $name => $_preset) {
            $outputPath = $this->transcode($sourcePath, $name);

            if ($outputPath !== null) {
                $results[$name] = $outputPath;
            }
        }

        return $results;
    }
}
