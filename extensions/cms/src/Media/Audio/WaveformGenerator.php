<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media\Audio;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Media\Video\FfmpegProcessInterface;

use function array_fill;
use function count;
use function file_exists;
use function json_encode;
use function max;
use function min;
use function ord;
use function strlen;

use const JSON_THROW_ON_ERROR;

/**
 * Generates waveform amplitude data from audio files for visual player rendering.
 *
 * Uses FFmpeg to extract raw PCM samples, then downsamples to a fixed number
 * of amplitude peaks suitable for canvas/SVG waveform visualization.
 */
#[Internal(reason: 'Use WaveformGenerator via service container')]
final readonly class WaveformGenerator
{
    public function __construct(
        private AudioConfig $config,
        private FfmpegProcessInterface $process,
        private LoggerInterface $logger,
    ) {}

    /**
     * Generate waveform amplitude data as a normalized float array.
     *
     * Each value is between 0.0 (silence) and 1.0 (peak amplitude).
     * The array length matches AudioConfig::$waveformSamples.
     *
     * @return list<float> Normalized amplitude values
     */
    public function generate(string $filePath): array
    {
        if (!file_exists($filePath)) {
            $this->logger->warning('Audio file not found for waveform generation', [
                'file' => $filePath,
            ]);

            return array_fill(0, $this->config->waveformSamples, 0.0);
        }

        $rawPcm = $this->process->extractWaveform(
            $this->config->ffmpegPath,
            $filePath,
        );

        if ($rawPcm === null || strlen($rawPcm) === 0) {
            $this->logger->warning('Failed to extract PCM data for waveform', [
                'file' => $filePath,
            ]);

            return array_fill(0, $this->config->waveformSamples, 0.0);
        }

        return $this->downsample($rawPcm, $this->config->waveformSamples);
    }

    /**
     * Generate waveform data as a JSON string for embedding in HTML data attributes.
     */
    public function generateJson(string $filePath): string
    {
        $amplitudes = $this->generate($filePath);

        return json_encode($amplitudes, JSON_THROW_ON_ERROR);
    }

    /**
     * Downsample raw PCM unsigned 8-bit mono data to a fixed number of amplitude peaks.
     *
     * The PCM data uses unsigned 8-bit encoding (0-255, center at 128).
     * Each output sample is the peak absolute deviation from center within its bin,
     * normalized to 0.0-1.0.
     *
     * @return list<float>
     */
    private function downsample(string $pcmData, int $targetSamples): array
    {
        $totalSamples = strlen($pcmData);

        if ($totalSamples === 0) {
            return array_fill(0, $targetSamples, 0.0);
        }

        $samplesPerBin = $totalSamples / $targetSamples;
        /** @var list<float> $peaks */
        $peaks = [];

        for ($bin = 0; $bin < $targetSamples; $bin++) {
            $start = (int) ($bin * $samplesPerBin);
            $end = min((int) (($bin + 1) * $samplesPerBin), $totalSamples);

            $maxAmplitude = 0.0;

            for ($i = $start; $i < $end; $i++) {
                // PCM u8: center is 128, amplitude is deviation from center
                $sample = ord($pcmData[$i]);
                $amplitude = abs($sample - 128) / 128.0;
                $maxAmplitude = max($maxAmplitude, $amplitude);
            }

            $peaks[] = $maxAmplitude;
        }

        // Normalize peaks so the loudest bin is 1.0
        $globalMax = 0.0;

        foreach ($peaks as $peak) {
            $globalMax = max($globalMax, $peak);
        }

        if ($globalMax > 0.0) {
            $count = count($peaks);

            for ($i = 0; $i < $count; $i++) {
                $peaks[$i] = $peaks[$i] / $globalMax;
            }
        }

        return array_values($peaks);
    }
}
