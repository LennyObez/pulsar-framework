<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media\Audio;

use Pulsar\Api\Api;

use function is_int;
use function is_string;

/**
 * A named audio transcoding preset defining codec, bitrate, and format.
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
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            codec: is_string($data['codec'] ?? null) ? $data['codec'] : 'libmp3lame',
            bitrate: is_string($data['bitrate'] ?? null) ? $data['bitrate'] : '128k',
            format: is_string($data['format'] ?? null) ? $data['format'] : 'mp3',
            sampleRate: is_int($data['sample_rate'] ?? null) ? $data['sample_rate'] : 44100,
            channels: is_int($data['channels'] ?? null) ? $data['channels'] : 2,
        );
    }
}
