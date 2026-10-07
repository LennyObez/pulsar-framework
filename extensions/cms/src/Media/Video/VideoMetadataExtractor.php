<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media\Video;

use JsonException;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;

use function count;
use function file_exists;
use function is_array;
use function is_numeric;
use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Extracts video metadata using FFprobe.
 *
 * Calls ffprobe as a subprocess with JSON output and maps the result
 * to a structured VideoMetadata DTO. Gracefully returns empty metadata
 * if ffprobe is unavailable.
 */
#[Internal(reason: 'Use VideoMetadata DTO directly for public API')]
final readonly class VideoMetadataExtractor
{
    public function __construct(
        private VideoConfig $config,
        private FfmpegProcess $process,
        private LoggerInterface $logger,
    ) {}

    public function extract(string $filePath): VideoMetadata
    {
        if (!file_exists($filePath)) {
            return new VideoMetadata();
        }

        $output = $this->process->probe($this->config->ffprobePath, $filePath);

        if ($output === null) {
            return new VideoMetadata();
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($output, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->logger->warning('Failed to parse ffprobe JSON output', [
                'file' => $filePath,
            ]);

            return new VideoMetadata();
        }

        return $this->buildMetadata($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildMetadata(array $data): VideoMetadata
    {
        /** @var mixed $rawFormat */
        $rawFormat = $data['format'] ?? null;
        /** @var array<string, mixed> $format */
        $format = is_array($rawFormat) ? $rawFormat : [];
        /** @var mixed $rawStreams */
        $rawStreams = $data['streams'] ?? null;
        /** @var list<mixed> $streams */
        $streams = is_array($rawStreams) ? array_values($rawStreams) : [];

        $videoStream = $this->findStream($streams, 'video');
        $audioStream = $this->findStream($streams, 'audio');

        $duration = self::toFloat($format['duration'] ?? $videoStream['duration'] ?? null);
        $fileSize = isset($format['size']) && is_numeric($format['size']) ? (int) $format['size'] : null;
        /** @var mixed $rawFormatName */
        $rawFormatName = $format['format_name'] ?? null;
        $formatName = is_string($rawFormatName) ? $rawFormatName : null;
        /** @var mixed $rawVideoCodec */
        $rawVideoCodec = $videoStream['codec_name'] ?? null;
        /** @var mixed $rawAudioCodec */
        $rawAudioCodec = $audioStream['codec_name'] ?? null;

        return new VideoMetadata(
            duration: $duration,
            width: isset($videoStream['width']) && is_numeric($videoStream['width']) ? (int) $videoStream['width'] : null,
            height: isset($videoStream['height']) && is_numeric($videoStream['height']) ? (int) $videoStream['height'] : null,
            videoCodec: is_string($rawVideoCodec) ? $rawVideoCodec : null,
            audioCodec: is_string($rawAudioCodec) ? $rawAudioCodec : null,
            framerate: $this->parseFramerate($videoStream['r_frame_rate'] ?? $videoStream['avg_frame_rate'] ?? null),
            videoBitrate: isset($videoStream['bit_rate']) && is_numeric($videoStream['bit_rate']) ? (int) $videoStream['bit_rate'] : null,
            audioBitrate: isset($audioStream['bit_rate']) && is_numeric($audioStream['bit_rate']) ? (int) $audioStream['bit_rate'] : null,
            audioSampleRate: isset($audioStream['sample_rate']) && is_numeric($audioStream['sample_rate']) ? (int) $audioStream['sample_rate'] : null,
            audioChannels: isset($audioStream['channels']) && is_numeric($audioStream['channels']) ? (int) $audioStream['channels'] : null,
            format: $formatName,
            fileSize: $fileSize,
        );
    }

    /**
     * @param list<mixed> $streams
     * @return array<string, mixed>
     */
    private function findStream(array $streams, string $codecType): array
    {
        /** @var mixed $stream */
        foreach ($streams as $stream) {
            if (is_array($stream) && ($stream['codec_type'] ?? null) === $codecType) {
                /** @var array<string, mixed> $stream */
                return $stream;
            }
        }

        return [];
    }

    private function parseFramerate(mixed $value): ?float
    {
        if ($value === null || !is_string($value)) {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        $parts = explode('/', $value);

        if (count($parts) !== 2) {
            return null;
        }

        $numerator = (float) $parts[0];
        $denominator = (float) $parts[1];

        if ($denominator === 0.0) {
            return null;
        }

        return round($numerator / $denominator, 3);
    }

    private static function toFloat(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }
}
