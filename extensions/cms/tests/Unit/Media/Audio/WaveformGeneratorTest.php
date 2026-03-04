<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Media\Audio;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Extension\Cms\Media\Audio\AudioConfig;
use Pulsar\Extension\Cms\Media\Audio\WaveformGenerator;
use Pulsar\Extension\Cms\Media\Video\FfmpegProcessInterface;

use function chr;

#[CoversClass(WaveformGenerator::class)]
final class WaveformGeneratorTest extends TestCase
{
    private AudioConfig $config;
    private FfmpegProcessInterface&Stub $process;
    private WaveformGenerator $generator;

    protected function setUp(): void
    {
        $this->config = new AudioConfig(waveformSamples: 16);
        $this->process = $this->createStub(FfmpegProcessInterface::class);
        $this->generator = new WaveformGenerator(
            $this->config,
            $this->process,
            new NullLogger(),
        );
    }

    #[Test]
    public function generateReturnsZerosForMissingFile(): void
    {
        $result = $this->generator->generate('/nonexistent/file.mp3');

        self::assertCount(16, $result);

        foreach ($result as $value) {
            self::assertSame(0.0, $value);
        }
    }

    #[Test]
    public function generateReturnsZerosWhenFfmpegReturnsNull(): void
    {
        $tempFile = $this->createTempFile();

        $this->process->method('extractWaveform')->willReturn(null);

        $result = $this->generator->generate($tempFile);

        self::assertCount(16, $result);

        foreach ($result as $value) {
            self::assertSame(0.0, $value);
        }
    }

    #[Test]
    public function generateReturnsZerosForEmptyPcmData(): void
    {
        $tempFile = $this->createTempFile();

        $this->process->method('extractWaveform')->willReturn('');

        $result = $this->generator->generate($tempFile);

        self::assertCount(16, $result);
    }

    #[Test]
    public function generateReturnsNormalizedAmplitudes(): void
    {
        $tempFile = $this->createTempFile();

        // Generate PCM u8 data: 128 = silence, 0 and 255 = peak
        $pcmData = '';

        for ($i = 0; $i < 160; $i++) {
            $sample = 128 + (int) (127 * sin($i * 0.2));
            $pcmData .= chr(max(0, min(255, $sample)));
        }

        $this->process->method('extractWaveform')->willReturn($pcmData);

        $result = $this->generator->generate($tempFile);

        self::assertCount(16, $result);

        // At least one value should be normalized to 1.0 (the peak)
        self::assertContains(1.0, $result);

        // All values should be between 0.0 and 1.0
        foreach ($result as $value) {
            self::assertGreaterThanOrEqual(0.0, $value);
            self::assertLessThanOrEqual(1.0, $value);
        }
    }

    #[Test]
    public function generateReturnsSilenceForAllCenterValues(): void
    {
        $tempFile = $this->createTempFile();

        $pcmData = str_repeat(chr(128), 160);

        $this->process->method('extractWaveform')->willReturn($pcmData);

        $result = $this->generator->generate($tempFile);

        self::assertCount(16, $result);

        foreach ($result as $value) {
            self::assertSame(0.0, $value);
        }
    }

    #[Test]
    public function generateJsonReturnsValidJson(): void
    {
        $tempFile = $this->createTempFile();

        $this->process->method('extractWaveform')->willReturn(str_repeat(chr(128), 160));

        $json = $this->generator->generateJson($tempFile);

        $decoded = json_decode($json, true);

        self::assertIsArray($decoded);
        self::assertCount(16, $decoded);
    }

    #[Test]
    public function generateUsesConfiguredSampleCount(): void
    {
        $config = new AudioConfig(waveformSamples: 64);
        $process = $this->createStub(FfmpegProcessInterface::class);
        $generator = new WaveformGenerator($config, $process, new NullLogger());

        $tempFile = $this->createTempFile();
        $process->method('extractWaveform')->willReturn(str_repeat(chr(200), 640));

        $result = $generator->generate($tempFile);

        self::assertCount(64, $result);
    }

    private function createTempFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pulsar_test_waveform_');
        self::assertNotFalse($path);
        file_put_contents($path, 'test');

        return $path;
    }
}
