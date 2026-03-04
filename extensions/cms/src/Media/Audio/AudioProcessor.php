<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media\Audio;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Media\Video\FfmpegProcessInterface;

use function file_exists;
use function realpath;
use function str_starts_with;
use function sys_get_temp_dir;
use function tempnam;

/**
 * Wraps FFmpeg CLI for audio transcoding operations.
 *
 * Converts source audio files to target presets (codec, bitrate, sample rate).
 * If FFmpeg is unavailable, logs a warning and returns null gracefully.
 */
#[Internal(reason: 'Use AudioProcessor via service container')]
final readonly class AudioProcessor
{
    public function __construct(
        private AudioConfig $config,
        private FfmpegProcessInterface $process,
        private LoggerInterface $logger,
    ) {}

    /**
     * Transcode an audio file to a specific preset.
     *
     * @return string|null Path to transcoded file, or null on failure
     */
    public function transcode(string $sourcePath, string $presetName): ?string
    {
        if (!file_exists($sourcePath)) {
            $this->logger->warning('Audio source file not found', ['path' => $sourcePath]);

            return null;
        }

        $preset = $this->config->transcodePresets[$presetName] ?? null;

        if ($preset === null) {
            $this->logger->warning('Unknown audio transcode preset', ['preset' => $presetName]);

            return null;
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'pulsar_audio_');

        if ($tempFile === false) {
            return null;
        }

        $outputPath = $tempFile . '.' . $preset->format;

        $codecArgs = [
            '-c:a', $preset->codec,
            '-b:a', $preset->bitrate,
            '-ar', (string) $preset->sampleRate,
            '-ac', (string) $preset->channels,
            '-vn',
        ];

        $success = $this->process->transcode(
            $this->config->ffmpegPath,
            $sourcePath,
            $outputPath,
            $codecArgs,
        );

        if (!$success) {
            $this->logger->warning('Audio transcode failed', [
                'source' => $sourcePath,
                'preset' => $presetName,
            ]);

            self::cleanupTempFile($outputPath);
            self::cleanupTempFile($tempFile);

            return null;
        }

        if ($tempFile !== $outputPath) {
            self::cleanupTempFile($tempFile);
        }

        $this->logger->info('Audio transcoded successfully', [
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

        foreach ($this->config->transcodePresets as $name => $preset) {
            $outputPath = $this->transcode($sourcePath, $name);

            if ($outputPath !== null) {
                $results[$name] = $outputPath;
            }
        }

        return $results;
    }

    /**
     * Convert an audio file to a normalized WAV format for downstream processing.
     *
     * @return string|null Path to WAV file, or null on failure
     */
    public function toWav(string $sourcePath, int $sampleRate = 44100): ?string
    {
        if (!file_exists($sourcePath)) {
            return null;
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'pulsar_audio_wav_');

        if ($tempFile === false) {
            return null;
        }

        $outputPath = $tempFile . '.wav';

        $codecArgs = [
            '-c:a', 'pcm_s16le',
            '-ar', (string) $sampleRate,
            '-ac', '1',
            '-vn',
        ];

        $success = $this->process->transcode(
            $this->config->ffmpegPath,
            $sourcePath,
            $outputPath,
            $codecArgs,
        );

        if (!$success) {
            self::cleanupTempFile($outputPath);
            self::cleanupTempFile($tempFile);

            return null;
        }

        if ($tempFile !== $outputPath) {
            self::cleanupTempFile($tempFile);
        }

        return $outputPath;
    }

    /**
     * Safely remove a temporary file after verifying it resides inside the
     * system temp directory. This guards against path-traversal: only files
     * whose resolved real path starts with sys_get_temp_dir() are deleted.
     */
    private static function cleanupTempFile(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        $realPath = realpath($path);
        $tempDir = realpath(sys_get_temp_dir());

        if ($realPath === false || $tempDir === false) {
            return;
        }

        if (!str_starts_with($realPath, $tempDir)) {
            return;
        }

        unlink($realPath);
    }
}
