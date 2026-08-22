<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media\Video;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use RuntimeException;

use function fclose;
use function is_resource;
use function is_string;
use function preg_match;
use function proc_close;
use function proc_open;
use function stream_get_contents;
use function substr;

/**
 * Safe subprocess wrapper for FFmpeg and FFprobe.
 *
 * Validates binary paths and input file paths before execution to prevent
 * command injection. Only allows known-safe argument patterns. All inputs
 * are validated against strict allowlists before being passed to proc_open
 * as an argument array (no shell interpolation).
 *
 * Security: proc_open() with array arguments bypasses shell parsing entirely.
 * Each element is passed directly as an argv entry to the OS, preventing
 * shell metacharacter injection. All dynamic values are additionally
 * validated against character allowlists.
 */
#[Internal(reason: 'Internal FFmpeg process helper')]
final readonly class FfmpegProcess implements FfmpegProcessInterface
{
    /** Pattern for allowed binary names (basename only or absolute path). */
    private const string BINARY_PATTERN = '/\A[a-zA-Z0-9_\-\/\\\.:]+\z/';

    /** Pattern for safe file path characters. */
    private const string PATH_PATTERN = '/\A[a-zA-Z0-9_\-\/\\\\.:() \x80-\xFF]+\z/';

    /** Pattern for safe FFmpeg arguments. */
    private const string ARG_PATTERN = '/\A[a-zA-Z0-9_\-:=.\/]+\z/';

    public function __construct(
        private LoggerInterface $logger,
    ) {}

    /**
     * Run ffprobe and return JSON output for a media file.
     *
     * @return string|null JSON output on success, null on failure
     */
    public function probe(string $ffprobePath, string $filePath): ?string
    {
        self::validateBinaryPath($ffprobePath);
        self::validateFilePath($filePath);

        $args = [
            $ffprobePath,
            '-v', 'quiet',
            '-print_format', 'json',
            '-show_format',
            '-show_streams',
            $filePath,
        ];

        return $this->runProcess($args);
    }

    /**
     * Run an ffmpeg transcode operation with pre-validated codec arguments.
     *
     * @param list<string> $codecArgs Codec/quality arguments (each validated individually)
     */
    public function transcode(
        string $ffmpegPath,
        string $inputPath,
        string $outputPath,
        array $codecArgs,
    ): bool {
        self::validateBinaryPath($ffmpegPath);
        self::validateFilePath($inputPath);
        self::validateFilePath($outputPath);

        $args = [$ffmpegPath, '-i', $inputPath];

        foreach ($codecArgs as $arg) {
            self::validateArgument($arg);
            $args[] = $arg;
        }

        $args[] = '-y';
        $args[] = $outputPath;

        return $this->runProcess($args) !== null;
    }

    /**
     * Extract a single frame as a thumbnail image.
     */
    public function extractThumbnail(
        string $ffmpegPath,
        string $inputPath,
        string $outputPath,
        float $timestamp,
    ): bool {
        self::validateBinaryPath($ffmpegPath);
        self::validateFilePath($inputPath);
        self::validateFilePath($outputPath);

        $ts = number_format($timestamp, 3, '.', '');

        $args = [
            $ffmpegPath,
            '-ss', $ts,
            '-i', $inputPath,
            '-vframes', '1',
            '-q:v', '2',
            '-y',
            $outputPath,
        ];

        return $this->runProcess($args) !== null;
    }

    /**
     * Generate HLS segments from a video file.
     *
     * @param list<string> $codecArgs Additional codec arguments (each validated)
     */
    public function segmentHls(
        string $ffmpegPath,
        string $inputPath,
        string $playlistPath,
        string $segmentPattern,
        int $segmentDuration,
        array $codecArgs,
    ): bool {
        self::validateBinaryPath($ffmpegPath);
        self::validateFilePath($inputPath);
        self::validateFilePath($playlistPath);
        self::validateFilePath($segmentPattern);

        $args = [$ffmpegPath, '-i', $inputPath];

        foreach ($codecArgs as $arg) {
            self::validateArgument($arg);
            $args[] = $arg;
        }

        $args[] = '-f';
        $args[] = 'hls';
        $args[] = '-hls_time';
        $args[] = (string) $segmentDuration;
        $args[] = '-hls_list_size';
        $args[] = '0';
        $args[] = '-hls_segment_filename';
        $args[] = $segmentPattern;
        $args[] = '-y';
        $args[] = $playlistPath;

        return $this->runProcess($args) !== null;
    }

    /**
     * Generate waveform amplitude data from an audio file.
     *
     * @return string|null Raw PCM amplitude data, or null on failure
     */
    public function extractWaveform(string $ffmpegPath, string $inputPath): ?string
    {
        self::validateBinaryPath($ffmpegPath);
        self::validateFilePath($inputPath);

        $args = [
            $ffmpegPath,
            '-i', $inputPath,
            '-ac', '1',
            '-filter:a', 'aresample=8000',
            '-map', '0:a',
            '-c:a', 'pcm_u8',
            '-f', 'data',
            'pipe:1',
        ];

        return $this->runProcess($args);
    }

    /**
     * Run a process using proc_open with an explicit argument array (no shell).
     *
     * All callers MUST validate every element of $args before calling this method.
     * Binary paths are validated against BINARY_PATTERN, file paths against
     * PATH_PATTERN, and dynamic arguments against ARG_PATTERN.
     *
     * Using array form for proc_open bypasses shell parsing entirely: each
     * element becomes a separate argv entry with no shell metacharacter
     * interpretation. This is the same pattern used by ProtocRunner and
     * SubprocessRunner elsewhere in the codebase.
     *
     * @param list<string> $args Fully validated command arguments
     *
     * @return string|null stdout on success, null on failure
     */
    private function runProcess(array $args): ?string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // nosemgrep: php.lang.security.injection.proc_open: all args validated
        // against character-class allowlists (BINARY_PATTERN, PATH_PATTERN,
        // ARG_PATTERN) before reaching this point. Array form bypasses shell.
        $process = @proc_open($args, $descriptors, $pipes);

        if (!is_resource($process)) {
            $this->logger->warning('Failed to start process', [
                'binary' => $args[0] ?? 'unknown',
            ]);

            return null;
        }

        /** @var array<int, resource> $pipes */
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1], length: 50 * 1024 * 1024);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2], length: 1024 * 1024);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $this->logger->warning('Process exited with non-zero status', [
                'binary' => $args[0] ?? 'unknown',
                'exit_code' => $exitCode,
                'stderr' => is_string($stderr) ? substr($stderr, 0, 2048) : '',
            ]);

            return null;
        }

        if (!is_string($stdout)) {
            return null;
        }

        return $stdout;
    }

    /**
     * Validate that a binary path contains only safe characters.
     *
     * @throws RuntimeException If the path contains disallowed characters
     */
    private static function validateBinaryPath(string $path): void
    {
        if ($path === '' || preg_match(self::BINARY_PATTERN, $path) !== 1) {
            throw new RuntimeException('Invalid FFmpeg/FFprobe binary path: contains disallowed characters');
        }
    }

    /**
     * Validate that a file path contains only safe characters.
     *
     * @throws RuntimeException If the path contains disallowed characters
     */
    private static function validateFilePath(string $path): void
    {
        if ($path === '' || preg_match(self::PATH_PATTERN, $path) !== 1) {
            throw new RuntimeException('Invalid file path: contains disallowed characters');
        }
    }

    /**
     * Validate that an argument contains only safe characters.
     *
     * @throws RuntimeException If the argument contains disallowed characters
     */
    private static function validateArgument(string $arg): void
    {
        if (preg_match(self::ARG_PATTERN, $arg) !== 1) {
            throw new RuntimeException('Invalid FFmpeg argument: contains disallowed characters');
        }
    }
}
