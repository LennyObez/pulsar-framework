<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Media\Audio;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Media\Audio\AudioConfig;
use Pulsar\Extension\Cms\Media\Audio\AudioTranscodePreset;

#[CoversClass(AudioConfig::class)]
#[CoversClass(AudioTranscodePreset::class)]
final class AudioConfigTest extends TestCase
{
    #[Test]
    public function defaultValuesAreApplied(): void
    {
        $config = new AudioConfig();

        self::assertSame('ffmpeg', $config->ffmpegPath);
        self::assertSame('ffprobe', $config->ffprobePath);
        self::assertSame(104_857_600, $config->maxUploadSize);
        self::assertSame(300, $config->processTimeout);
        self::assertSame([], $config->transcodePresets);
        self::assertTrue($config->waveformEnabled);
        self::assertSame(256, $config->waveformSamples);
    }

    #[Test]
    public function fromArrayParsesAllFields(): void
    {
        $config = AudioConfig::fromArray([
            'ffmpeg_path' => '/usr/bin/ffmpeg',
            'ffprobe_path' => '/usr/bin/ffprobe',
            'max_upload_size' => 52428800,
            'process_timeout' => 120,
            'waveform_enabled' => false,
            'waveform_samples' => 512,
            'transcode_presets' => [
                'test' => [
                    'codec' => 'libopus',
                    'bitrate' => '96k',
                    'format' => 'ogg',
                    'sample_rate' => 48000,
                    'channels' => 1,
                ],
            ],
        ]);

        self::assertSame('/usr/bin/ffmpeg', $config->ffmpegPath);
        self::assertSame('/usr/bin/ffprobe', $config->ffprobePath);
        self::assertSame(52428800, $config->maxUploadSize);
        self::assertSame(120, $config->processTimeout);
        self::assertFalse($config->waveformEnabled);
        self::assertSame(512, $config->waveformSamples);
        self::assertCount(1, $config->transcodePresets);
        self::assertArrayHasKey('test', $config->transcodePresets);
        self::assertSame('libopus', $config->transcodePresets['test']->codec);
        self::assertSame(1, $config->transcodePresets['test']->channels);
    }

    #[Test]
    public function fromArrayUsesDefaultPresetsWhenNoneGiven(): void
    {
        $config = AudioConfig::fromArray([]);

        self::assertCount(4, $config->transcodePresets);
        self::assertArrayHasKey('mp3_128', $config->transcodePresets);
        self::assertArrayHasKey('mp3_320', $config->transcodePresets);
        self::assertArrayHasKey('aac_128', $config->transcodePresets);
        self::assertArrayHasKey('opus_96', $config->transcodePresets);
    }

    #[Test]
    public function fromArrayIgnoresInvalidPresetEntries(): void
    {
        $config = AudioConfig::fromArray([
            'transcode_presets' => [
                'valid' => ['codec' => 'aac'],
                0 => ['codec' => 'should_be_skipped_numeric_key'],
                'bad' => 'not_an_array',
            ],
        ]);

        self::assertCount(1, $config->transcodePresets);
        self::assertArrayHasKey('valid', $config->transcodePresets);
    }

    #[Test]
    public function fromArrayHandlesInvalidTypes(): void
    {
        $config = AudioConfig::fromArray([
            'ffmpeg_path' => 123,
            'max_upload_size' => 'not_int',
            'waveform_enabled' => 'not_bool',
            'waveform_samples' => false,
        ]);

        self::assertSame('ffmpeg', $config->ffmpegPath);
        self::assertSame(104_857_600, $config->maxUploadSize);
        self::assertTrue($config->waveformEnabled);
        self::assertSame(256, $config->waveformSamples);
    }
}
