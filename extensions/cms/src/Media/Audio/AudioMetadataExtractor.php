<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media\Audio;

use JsonException;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Media\Video\FfmpegProcessInterface;

use function file_exists;
use function is_array;
use function is_numeric;
use function is_string;
use function json_decode;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * Extracts audio metadata (ID3 tags + stream info) using FFprobe.
 *
 * Calls ffprobe as a subprocess with JSON output and maps the result
 * to a structured AudioMetadata DTO. Gracefully returns empty metadata
 * if ffprobe is unavailable.
 *
 * @psalm-api Resolved from the DI container by media services and audio
 *            derivative jobs; not instantiated by name.
 */
#[Internal(reason: 'Use AudioMetadata DTO directly for public API')]
final readonly class AudioMetadataExtractor
{
    public function __construct(
        private AudioConfig $config,
        private FfmpegProcessInterface $process,
        private LoggerInterface $logger,
    ) {}

    public function extract(string $filePath): AudioMetadata
    {
        if (!file_exists($filePath)) {
            return new AudioMetadata();
        }

        $output = $this->process->probe($this->config->ffprobePath, $filePath);

        if ($output === null) {
            return new AudioMetadata();
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($output, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->logger->warning('Failed to parse ffprobe JSON output for audio', [
                'file' => $filePath,
            ]);

            return new AudioMetadata();
        }

        return $this->buildMetadata($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildMetadata(array $data): AudioMetadata
    {
        /** @var mixed $rawFormat */
        $rawFormat = $data['format'] ?? null;
        /** @var array<string, mixed> $format */
        $format = is_array($rawFormat) ? $rawFormat : [];
        /** @var mixed $rawStreams */
        $rawStreams = $data['streams'] ?? null;
        /** @var list<mixed> $streams */
        $streams = is_array($rawStreams) ? array_values($rawStreams) : [];
        /** @var mixed $rawTags */
        $rawTags = $format['tags'] ?? null;
        /** @var array<string, mixed> $tags */
        $tags = is_array($rawTags) ? $rawTags : [];

        $audioStream = $this->findAudioStream($streams);

        $duration = self::toFloat($format['duration'] ?? $audioStream['duration'] ?? null);
        $fileSize = isset($format['size']) && is_numeric($format['size']) ? (int) $format['size'] : null;
        /** @var mixed $rawFormatName */
        $rawFormatName = $format['format_name'] ?? null;
        $formatName = is_string($rawFormatName) ? $rawFormatName : null;
        $bitrate = isset($format['bit_rate']) && is_numeric($format['bit_rate']) ? (int) $format['bit_rate'] : null;

        return new AudioMetadata(
            title: self::findTag($tags, ['title', 'TITLE']),
            artist: self::findTag($tags, ['artist', 'ARTIST', 'album_artist', 'ALBUM_ARTIST']),
            album: self::findTag($tags, ['album', 'ALBUM']),
            genre: self::findTag($tags, ['genre', 'GENRE']),
            year: self::parseYear($tags),
            trackNumber: self::parseTrackNumber($tags),
            duration: $duration,
            bitrate: $bitrate,
            sampleRate: isset($audioStream['sample_rate']) && is_numeric($audioStream['sample_rate'])
                ? (int) $audioStream['sample_rate']
                : null,
            channels: isset($audioStream['channels']) && is_numeric($audioStream['channels'])
                ? (int) $audioStream['channels']
                : null,
            codec: is_string($audioStream['codec_name'] ?? null) ? $audioStream['codec_name'] : null,
            format: $formatName,
            fileSize: $fileSize,
        );
    }

    /**
     * @param list<mixed> $streams
     * @return array<string, mixed>
     */
    private function findAudioStream(array $streams): array
    {
        foreach ($streams as $stream) {
            if (is_array($stream) && ($stream['codec_type'] ?? null) === 'audio') {
                /** @var array<string, mixed> $stream */
                return $stream;
            }
        }

        return [];
    }

    /**
     * Search for a tag value across multiple possible key names (case variations).
     *
     * @param array<string, mixed> $tags
     * @param list<string> $keys
     */
    private static function findTag(array $tags, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $tags[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $tags
     */
    private static function parseYear(array $tags): ?int
    {
        $value = self::findTag($tags, ['date', 'DATE', 'year', 'YEAR']);

        if ($value === null) {
            return null;
        }

        // ID3 date can be "2024" or "2024-01-15"; extract year
        if (is_numeric($value) && (int) $value > 1900 && (int) $value < 2200) {
            return (int) $value;
        }

        $parts = explode('-', $value);

        if (is_numeric($parts[0]) && (int) $parts[0] > 1900 && (int) $parts[0] < 2200) {
            return (int) $parts[0];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $tags
     */
    private static function parseTrackNumber(array $tags): ?int
    {
        $value = self::findTag($tags, ['track', 'TRACK', 'tracknumber', 'TRACKNUMBER']);

        if ($value === null) {
            return null;
        }

        // Track number can be "5" or "5/12"; extract the number
        $parts = explode('/', $value);

        if (is_numeric($parts[0])) {
            return (int) $parts[0];
        }

        return null;
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
