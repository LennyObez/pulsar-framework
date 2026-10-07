<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media\Audio;

use Pulsar\Api\Api;

/**
 * A named audio transcoding preset defining codec, bitrate, and format.
 *
 * @psalm-api Public DTO contained in AudioConfig::presets; consumed by
 *            AudioProcessor.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AudioTranscodePreset
{
    public function __construct(
        public string $codec = 'libmp3lame',
        public string $bitrate = '128k',
        public string $format = 'mp3',
        public int $sampleRate = 44100,
        public int $channels = 2,
    ) {}

    /**
     * @param array{
     *     codec?: string,
     *     bitrate?: string,
     *     format?: string,
     *     sample_rate?: int,
     *     channels?: int,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            codec: $data['codec'] ?? 'libmp3lame',
            bitrate: $data['bitrate'] ?? '128k',
            format: $data['format'] ?? 'mp3',
            sampleRate: $data['sample_rate'] ?? 44100,
            channels: $data['channels'] ?? 2,
        );
    }
}
