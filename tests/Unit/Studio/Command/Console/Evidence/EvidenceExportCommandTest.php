<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Command\Console\Evidence;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Extension\Studio\Command\Console\Evidence\EvidenceExportCommand;
use Pulsar\Extension\Studio\Console\Evidence\EvidenceExporter;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Security\Crypto\HmacService;

#[CoversClass(EvidenceExportCommand::class)]
final class EvidenceExportCommandTest extends TestCase
{
    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->output = new BufferedOutput();
    }

    #[Test]
    public function it_is_configured_correctly(): void
    {
        $exporter = $this->createExporter();
        $command = new EvidenceExportCommand($exporter);

        self::assertSame('studio:console:evidence:export', $command->name);
        self::assertSame('Export Studio events as evidence archive', $command->description);
        self::assertArrayHasKey('output', $command->options);
        self::assertSame('o', $command->options['output']['shortcut']);
        self::assertArrayHasKey('json', $command->options);
        self::assertSame('j', $command->options['json']['shortcut']);
    }

    #[Test]
    public function it_exports_archive_as_text(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([
            ['event_id' => 'e1'],
            ['event_id' => 'e2'],
        ]);

        $exporter = new EvidenceExporter($store);

        $tempDir = sys_get_temp_dir();
        $outputPath = $tempDir . DIRECTORY_SEPARATOR . 'test-export-' . bin2hex(random_bytes(4)) . '.json';

        $command = new EvidenceExportCommand($exporter);
        $input = new ArrayInput('studio:console:evidence:export', [], ['output' => $outputPath]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Archive exported to', $this->output->buffer);
        self::assertStringContainsString('Events:      2', $this->output->buffer);
        self::assertStringContainsString('Chain links: 0', $this->output->buffer);
        self::assertStringContainsString('MAC:         None', $this->output->buffer);
        self::assertFileExists($outputPath);

        @unlink($outputPath);
    }

    #[Test]
    public function it_exports_archive_as_json(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([
            ['event_id' => 'e1'],
        ]);

        $exporter = new EvidenceExporter($store);

        $tempDir = sys_get_temp_dir();
        $outputPath = $tempDir . DIRECTORY_SEPARATOR . 'test-export-json-' . bin2hex(random_bytes(4)) . '.json';

        $command = new EvidenceExportCommand($exporter);
        $input = new ArrayInput('studio:console:evidence:export', [], ['output' => $outputPath, 'json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array{file: string, event_count: int, chain_link_count: int, size_bytes: int, mac_included: bool} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertSame($outputPath, $json['file']);
        self::assertSame(1, $json['event_count']);
        self::assertSame(0, $json['chain_link_count']);
        self::assertFalse($json['mac_included']);
        self::assertGreaterThan(0, $json['size_bytes']);

        @unlink($outputPath);
    }

    #[Test]
    public function it_returns_error_when_exporter_throws_studio_exception_text(): void
    {
        $exporter = $this->createExporter(isEncrypted: true, hasDecryptionKey: false);

        $command = new EvidenceExportCommand($exporter);
        $input = new ArrayInput('studio:console:evidence:export');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('PULSAR_MASTER_KEY', $this->output->errorBuffer);
    }

    #[Test]
    public function it_returns_error_when_exporter_throws_studio_exception_json(): void
    {
        $exporter = $this->createExporter(isEncrypted: true, hasDecryptionKey: false);

        $command = new EvidenceExportCommand($exporter);
        $input = new ArrayInput('studio:console:evidence:export', [], ['json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);

        /** @var array{error: string, message: string} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertSame('encryption_key_required', $json['error']);
        self::assertStringContainsString('PULSAR_MASTER_KEY', $json['message']);
    }

    #[Test]
    public function it_returns_error_when_output_directory_does_not_exist(): void
    {
        $exporter = $this->createExporter();

        $command = new EvidenceExportCommand($exporter);
        $input = new ArrayInput('studio:console:evidence:export', [], ['output' => '/nonexistent/dir/file.json']);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('Output directory does not exist', $this->output->errorBuffer);
    }

    #[Test]
    public function it_exports_with_mac_included(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $exporter = new EvidenceExporter($store, hmac: new HmacService(), archiveMacKey: 'test-mac-key-16b!');

        $tempDir = sys_get_temp_dir();
        $outputPath = $tempDir . DIRECTORY_SEPARATOR . 'test-mac-' . bin2hex(random_bytes(4)) . '.json';

        $command = new EvidenceExportCommand($exporter);
        $input = new ArrayInput('studio:console:evidence:export', [], ['output' => $outputPath]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('MAC:         Included', $this->output->buffer);

        @unlink($outputPath);
    }

    #[Test]
    public function it_shows_mac_none_when_archive_has_no_mac(): void
    {
        $exporter = $this->createExporter();

        $tempDir = sys_get_temp_dir();
        $outputPath = $tempDir . DIRECTORY_SEPARATOR . 'test-no-mac-' . bin2hex(random_bytes(4)) . '.json';

        $command = new EvidenceExportCommand($exporter);
        $input = new ArrayInput('studio:console:evidence:export', [], ['output' => $outputPath]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('MAC:         None', $this->output->buffer);

        @unlink($outputPath);
    }

    private function createExporter(bool $isEncrypted = false, bool $hasDecryptionKey = true): EvidenceExporter
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        return new EvidenceExporter($store, isEncrypted: $isEncrypted, hasDecryptionKey: $hasDecryptionKey);
    }
}
