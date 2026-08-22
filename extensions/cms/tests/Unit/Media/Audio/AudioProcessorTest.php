<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Media\Audio;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Extension\Cms\Media\Audio\AudioConfig;
use Pulsar\Extension\Cms\Media\Audio\AudioProcessor;
use Pulsar\Extension\Cms\Media\Audio\AudioTranscodePreset;
use Pulsar\Extension\Cms\Media\Video\FfmpegProcessInterface;

#[CoversClass(AudioProcessor::class)]
final class AudioProcessorTest extends TestCase
{
    private AudioConfig $config;
    private string $tempFile;

    protected function setUp(): void
    {
        $this->config = new AudioConfig(
            transcodePresets: [
                'mp3_128' => new AudioTranscodePreset(
                    codec: 'libmp3lame',
                    bitrate: '128k',
                    format: 'mp3',
                    sampleRate: 44100,
                    channels: 2,
                ),
            ],
        );

        $path = tempnam(sys_get_temp_dir(), 'pulsar_test_audio_');
        self::assertNotFalse($path);
        file_put_contents($path, 'test audio data');
        $this->tempFile = $path;
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            @unlink($this->tempFile);
        }
    }

    private function makeProcessor(FfmpegProcessInterface $process): AudioProcessor
    {
        return new AudioProcessor($this->config, $process, new NullLogger());
    }

    #[Test]
    public function transcodeReturnsNullForMissingFile(): void
    {
        $process = $this->createStub(FfmpegProcessInterface::class);
        $processor = $this->makeProcessor($process);

        $result = $processor->transcode('/nonexistent/file.mp3', 'mp3_128');

        self::assertNull($result);
    }

    #[Test]
    public function transcodeReturnsNullForUnknownPreset(): void
    {
        $process = $this->createStub(FfmpegProcessInterface::class);
        $processor = $this->makeProcessor($process);

        $result = $processor->transcode($this->tempFile, 'nonexistent_preset');

        self::assertNull($result);
    }

    #[Test]
    public function transcodeReturnsOutputPathOnSuccess(): void
    {
        $process = $this->createMock(FfmpegProcessInterface::class);
        $process->expects(self::once())
            ->method('transcode')
            ->willReturn(true);

        $processor = $this->makeProcessor($process);
        $result = $processor->transcode($this->tempFile, 'mp3_128');

        self::assertNotNull($result);
        self::assertStringEndsWith('.mp3', $result);
    }

    #[Test]
    public function transcodeReturnsNullOnProcessFailure(): void
    {
        $process = $this->createMock(FfmpegProcessInterface::class);
        $process->expects(self::once())
            ->method('transcode')
            ->willReturn(false);

        $processor = $this->makeProcessor($process);
        $result = $processor->transcode($this->tempFile, 'mp3_128');

        self::assertNull($result);
    }

    #[Test]
    public function transcodePassesCorrectCodecArgs(): void
    {
        $process = $this->createMock(FfmpegProcessInterface::class);
        $process->expects(self::once())
            ->method('transcode')
            ->with(
                'ffmpeg',
                $this->tempFile,
                self::anything(),
                ['-c:a', 'libmp3lame', '-b:a', '128k', '-ar', '44100', '-ac', '2', '-vn'],
            )
            ->willReturn(true);

        $processor = $this->makeProcessor($process);
        $processor->transcode($this->tempFile, 'mp3_128');
    }

    #[Test]
    public function transcodeAllProcessesAllPresets(): void
    {
        $process = $this->createStub(FfmpegProcessInterface::class);
        $process->method('transcode')->willReturn(true);

        $processor = $this->makeProcessor($process);
        $results = $processor->transcodeAll($this->tempFile);

        self::assertCount(1, $results);
        self::assertArrayHasKey('mp3_128', $results);
    }

    #[Test]
    public function transcodeAllSkipsFailedPresets(): void
    {
        $process = $this->createStub(FfmpegProcessInterface::class);
        $process->method('transcode')->willReturn(false);

        $processor = $this->makeProcessor($process);
        $results = $processor->transcodeAll($this->tempFile);

        self::assertSame([], $results);
    }

    #[Test]
    public function toWavReturnsNullForMissingFile(): void
    {
        $process = $this->createStub(FfmpegProcessInterface::class);
        $processor = $this->makeProcessor($process);

        $result = $processor->toWav('/nonexistent/file.mp3');

        self::assertNull($result);
    }

    #[Test]
    public function toWavReturnsOutputPathOnSuccess(): void
    {
        $process = $this->createMock(FfmpegProcessInterface::class);
        $process->expects(self::once())
            ->method('transcode')
            ->willReturn(true);

        $processor = $this->makeProcessor($process);
        $result = $processor->toWav($this->tempFile);

        self::assertNotNull($result);
        self::assertStringEndsWith('.wav', $result);
    }

    #[Test]
    public function toWavPassesCorrectArgs(): void
    {
        $process = $this->createMock(FfmpegProcessInterface::class);
        $process->expects(self::once())
            ->method('transcode')
            ->with(
                'ffmpeg',
                $this->tempFile,
                self::anything(),
                ['-c:a', 'pcm_s16le', '-ar', '44100', '-ac', '1', '-vn'],
            )
            ->willReturn(true);

        $processor = $this->makeProcessor($process);
        $processor->toWav($this->tempFile, 44100);
    }

    #[Test]
    public function toWavReturnsNullOnProcessFailure(): void
    {
        $process = $this->createStub(FfmpegProcessInterface::class);
        $process->method('transcode')->willReturn(false);

        $processor = $this->makeProcessor($process);
        $result = $processor->toWav($this->tempFile);

        self::assertNull($result);
    }
}
