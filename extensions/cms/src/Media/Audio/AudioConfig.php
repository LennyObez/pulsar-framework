<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media\Audio;

use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

use function is_array;
use function is_string;

/**
 * Configuration for audio processing and transcoding.
 *
 * @psalm-api Public configuration DTO loaded from config/cms.php (media.audio).
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AudioConfig
{
    /**
     * @param string $ffmpegPath Path to ffmpeg binary
     * @param string $ffprobePath Path to ffprobe binary
     * @param int $maxUploadSize Maximum audio upload size in bytes (default: 100 MB)
     * @param int $processTimeout Maximum processing time in seconds
     * @param array<string, AudioTranscodePreset> $transcodePresets Named audio transcode presets
     * @param bool $waveformEnabled Whether waveform generation is enabled
     * @param int $waveformSamples Number of amplitude samples for waveform visualization
     */
    public function __construct(
        public string $ffmpegPath = 'ffmpeg',
        public string $ffprobePath = 'ffprobe',
        public int $maxUploadSize = 104_857_600,
        public int $processTimeout = 300,
        public array $transcodePresets = [],
        public bool $waveformEnabled = true,
        public int $waveformSamples = 256,
    ) {}

    /**
     * @param array{
     *     ffmpeg_path?: string,
     *     ffprobe_path?: string,
     *     max_upload_size?: int,
     *     process_timeout?: int,
     *     transcode_presets?: array<string, array<string, mixed>>,
     *     waveform_enabled?: bool,
     *     waveform_samples?: int,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $presets = [];
        $rawPresets = $data['transcode_presets'] ?? null;
        if (is_array($rawPresets)) {
            foreach ($rawPresets as $name => $preset) {
                if (is_string($name) && is_array($preset)) {
                    $presets[$name] = AudioTranscodePreset::fromArray($preset);
                }
            }
        }

        if ($presets === []) {
            $presets = self::defaultPresets();
        }

        return new self(
            ffmpegPath: Coerce::string($data['ffmpeg_path'] ?? null, 'ffmpeg'),
            ffprobePath: Coerce::string($data['ffprobe_path'] ?? null, 'ffprobe'),
            maxUploadSize: Coerce::int($data['max_upload_size'] ?? null, 104_857_600),
            processTimeout: Coerce::int($data['process_timeout'] ?? null, 300),
            transcodePresets: $presets,
            waveformEnabled: Coerce::strictBool($data['waveform_enabled'] ?? null, true),
            waveformSamples: Coerce::int($data['waveform_samples'] ?? null, 256),
        );
    }

    /**
     * @return array<string, AudioTranscodePreset>
     */
    private static function defaultPresets(): array
    {
        return [
            'mp3_128' => new AudioTranscodePreset(codec: 'libmp3lame', bitrate: '128k', format: 'mp3', sampleRate: 44100),
            'mp3_320' => new AudioTranscodePreset(codec: 'libmp3lame', bitrate: '320k', format: 'mp3', sampleRate: 44100),
            'aac_128' => new AudioTranscodePreset(codec: 'aac', bitrate: '128k', format: 'm4a', sampleRate: 44100),
            'opus_96' => new AudioTranscodePreset(codec: 'libopus', bitrate: '96k', format: 'ogg', sampleRate: 48000),
        ];
    }
}
