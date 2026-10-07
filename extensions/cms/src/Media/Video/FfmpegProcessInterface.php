<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media\Video;

use Pulsar\Api\Internal;

/**
 * Interface for FFmpeg/FFprobe subprocess operations.
 *
 * Abstracts the underlying process execution to allow testing
 * without actual FFmpeg binaries. Implementations must validate
 * all inputs before passing to OS processes.
 */
#[Internal(reason: 'Internal media processing contract')]
interface FfmpegProcessInterface
{
    /**
     * Run ffprobe and return JSON output for a media file.
     *
     * @return string|null JSON output on success, null on failure
     */
    public function probe(string $ffprobePath, string $filePath): ?string;

    /**
     * Run an ffmpeg transcode operation.
     *
     * @param list<string> $codecArgs Codec/quality arguments
     */
    public function transcode(
        string $ffmpegPath,
        string $inputPath,
        string $outputPath,
        array $codecArgs,
    ): bool;

    /**
     * Extract a single frame as a thumbnail image.
     */
    public function extractThumbnail(
        string $ffmpegPath,
        string $inputPath,
        string $outputPath,
        float $timestamp,
    ): bool;

    /**
     * Generate HLS segments from a video file.
     *
     * @param list<string> $codecArgs Additional codec arguments
     */
    public function segmentHls(
        string $ffmpegPath,
        string $inputPath,
        string $playlistPath,
        string $segmentPattern,
        int $segmentDuration,
        array $codecArgs,
    ): bool;

    /**
     * Generate waveform amplitude data from an audio file.
     *
     * @return string|null Raw PCM amplitude data, or null on failure
     */
    public function extractWaveform(string $ffmpegPath, string $inputPath): ?string;
}
