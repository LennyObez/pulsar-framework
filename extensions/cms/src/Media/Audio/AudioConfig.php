<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media\Audio;

use Pulsar\Api\Api;

use function is_array;
use function is_bool;
use function is_int;
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
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $rawPresets = is_array($data['transcode_presets'] ?? null) ? $data['transcode_presets'] : [];
        /** @var array<string, AudioTranscodePreset> $presets */
        $presets = [];

        foreach ($rawPresets as $name => $preset) {
            if (is_array($preset) && is_string($name)) {
                /** @var array<string, mixed> $preset */
                $presets[$name] = AudioTranscodePreset::fromArray($preset);
            }
        }

        if ($presets === []) {
            $presets = self::defaultPresets();
        }

        return new self(
            ffmpegPath: is_string($data['ffmpeg_path'] ?? null) ? $data['ffmpeg_path'] : 'ffmpeg',
            ffprobePath: is_string($data['ffprobe_path'] ?? null) ? $data['ffprobe_path'] : 'ffprobe',
            maxUploadSize: is_int($data['max_upload_size'] ?? null) ? $data['max_upload_size'] : 104_857_600,
            processTimeout: is_int($data['process_timeout'] ?? null) ? $data['process_timeout'] : 300,
            transcodePresets: $presets,
            waveformEnabled: is_bool($data['waveform_enabled'] ?? null) ? $data['waveform_enabled'] : true,
            waveformSamples: is_int($data['waveform_samples'] ?? null) ? $data['waveform_samples'] : 256,
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
