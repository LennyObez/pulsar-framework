<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Command\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Extension\Studio\Command\Console\ConsoleExportCommand;
use Pulsar\Extension\Studio\Console\Evidence\EvidenceExporter;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Security\Crypto\HmacService;

use function file_get_contents;
use function is_file;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(ConsoleExportCommand::class)]
final class ConsoleExportCommandTest extends TestCase
{
    /** @var EventStoreInterface&Stub */
    private EventStoreInterface $store;
    private BufferedOutput $output;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->store = $this->createStub(EventStoreInterface::class);
        $this->output = new BufferedOutput();
        $this->tempDir = sys_get_temp_dir();
    }

    private function createExporter(bool $throwException = false): EvidenceExporter
    {
        if ($throwException) {
            // Create exporter with encryption enabled but no key
            return new EvidenceExporter(
                store: $this->store,
                isEncrypted: true,
                hasDecryptionKey: false,
            );
        }

        return new EvidenceExporter($this->store);
    }

    #[Test]
    public function configuredCorrectly(): void
    {
        $exporter = $this->createExporter();
        $command = new ConsoleExportCommand($exporter);

        self::assertSame('studio:console:export', $command->name);
        self::assertSame('Export Studio events as evidence archive', $command->description);
        self::assertArrayHasKey('output', $command->options);
        self::assertArrayHasKey('json', $command->options);
    }

    #[Test]
    public function executeExportsToSpecifiedPath(): void
    {
        $events = [['event_id' => 'evt-1', 'event_type' => 'http.request']];
        $this->store->method('query')->willReturn($events);

        $exporter = $this->createExporter();
        $command = new ConsoleExportCommand($exporter);

        // Use temp directory with a specific output path
        $outputPath = $this->tempDir . '/test-export.json';
        $input = new ArrayInput('studio:console:export', [], ['output' => $outputPath]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Archive exported to', $this->output->buffer);
        self::assertStringContainsString('Events:', $this->output->buffer);
        self::assertStringContainsString('Chain links:', $this->output->buffer);
        self::assertStringContainsString('MAC:', $this->output->buffer);

        // Clean up
        if (is_file($outputPath)) {
            unlink($outputPath);
        }
    }

    #[Test]
    public function executeExportsWithJsonOutput(): void
    {
        $events = [['event_id' => 'evt-1']];
        $this->store->method('query')->willReturn($events);

        $exporter = $this->createExporter();
        $command = new ConsoleExportCommand($exporter);

        $outputPath = $this->tempDir . '/test-export-json.json';
        $input = new ArrayInput('studio:console:export', [], ['output' => $outputPath, 'json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array{file: string, event_count: int, chain_link_count: int, size_bytes: int, mac_included: bool} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertSame($outputPath, $json['file']);
        self::assertSame(1, $json['event_count']);
        self::assertSame(0, $json['chain_link_count']); // No SQLite store, so no chain links
        self::assertFalse($json['mac_included']);
        self::assertArrayHasKey('size_bytes', $json);

        // Clean up
        if (is_file($outputPath)) {
            unlink($outputPath);
        }
    }

    #[Test]
    public function executeHandlesExporterException(): void
    {
        $exporter = $this->createExporter(throwException: true);
        $command = new ConsoleExportCommand($exporter);

        $outputPath = $this->tempDir . '/test-export-error.json';
        $input = new ArrayInput('studio:console:export', [], ['output' => $outputPath]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('Cannot export', $this->output->errorBuffer);
    }

    #[Test]
    public function executeHandlesExporterExceptionWithJsonOutput(): void
    {
        $exporter = $this->createExporter(throwException: true);
        $command = new ConsoleExportCommand($exporter);

        $outputPath = $this->tempDir . '/test-export-error-json.json';
        $input = new ArrayInput('studio:console:export', [], ['output' => $outputPath, 'json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);

        /** @var array{error: string, message: string} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertSame('encryption_key_required', $json['error']);
        self::assertStringContainsString('Cannot export', $json['message']);
    }

    #[Test]
    public function executeFailsWhenOutputDirectoryDoesNotExist(): void
    {
        $this->store->method('query')->willReturn([]);

        $exporter = $this->createExporter();
        $command = new ConsoleExportCommand($exporter);

        $outputPath = '/non/existent/directory/export.json';
        $input = new ArrayInput('studio:console:export', [], ['output' => $outputPath]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('Output directory does not exist', $this->output->errorBuffer);
    }

    #[Test]
    public function executeShowsNoMacWhenArchiveHasNoMac(): void
    {
        $this->store->method('query')->willReturn([['event_id' => 'evt-1']]);

        // No archiveMacKey means no MAC
        $exporter = $this->createExporter();
        $command = new ConsoleExportCommand($exporter);

        $outputPath = $this->tempDir . '/test-no-mac.json';
        $input = new ArrayInput('studio:console:export', [], ['output' => $outputPath]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('MAC:         None', $this->output->buffer);

        // Clean up
        if (is_file($outputPath)) {
            unlink($outputPath);
        }
    }

    #[Test]
    public function executeShowsMacIncludedWhenArchiveHasMac(): void
    {
        $this->store->method('query')->willReturn([['event_id' => 'evt-1']]);

        // With archiveMacKey, MAC will be included (key must be at least 16 bytes)
        $exporter = new EvidenceExporter($this->store, new HmacService(), 'test-mac-key-that-is-long-enough');
        $command = new ConsoleExportCommand($exporter);

        $outputPath = $this->tempDir . '/test-with-mac.json';
        $input = new ArrayInput('studio:console:export', [], ['output' => $outputPath]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('MAC:         Included', $this->output->buffer);

        // Clean up
        if (is_file($outputPath)) {
            unlink($outputPath);
        }
    }

    #[Test]
    public function executeWritesValidJsonArchive(): void
    {
        $events = [['event_id' => 'evt-1', 'event_type' => 'http.request']];
        $this->store->method('query')->willReturn($events);

        $exporter = $this->createExporter();
        $command = new ConsoleExportCommand($exporter);

        $outputPath = $this->tempDir . '/test-archive-content.json';
        $input = new ArrayInput('studio:console:export', [], ['output' => $outputPath]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertTrue(is_file($outputPath));

        $contents = file_get_contents($outputPath);
        self::assertIsString($contents);

        /** @var array{events: array<mixed>, chain: array<mixed>, manifest: array<mixed>, mac: ?string} $data */
        $data = json_decode($contents, true);
        self::assertIsArray($data);
        self::assertArrayHasKey('events', $data);
        self::assertArrayHasKey('chain', $data);
        self::assertArrayHasKey('manifest', $data);
        self::assertNull($data['mac']);

        // Clean up
        unlink($outputPath);
    }

    #[Test]
    public function outputOptionHasShortcutO(): void
    {
        $exporter = $this->createExporter();
        $command = new ConsoleExportCommand($exporter);

        self::assertSame('o', $command->options['output']['shortcut']);
    }

    #[Test]
    public function jsonOptionHasShortcutJ(): void
    {
        $exporter = $this->createExporter();
        $command = new ConsoleExportCommand($exporter);

        self::assertSame('j', $command->options['json']['shortcut']);
    }

    #[Test]
    public function executeReportsSizeInBytes(): void
    {
        $this->store->method('query')->willReturn([['event_id' => 'evt-1']]);

        $exporter = $this->createExporter();
        $command = new ConsoleExportCommand($exporter);

        $outputPath = $this->tempDir . '/test-size.json';
        $input = new ArrayInput('studio:console:export', [], ['output' => $outputPath]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertMatchesRegularExpression('/Size:\s+\d+ bytes/', $this->output->buffer);

        // Clean up
        if (is_file($outputPath)) {
            unlink($outputPath);
        }
    }

    #[Test]
    public function executeUsesDefaultPathWhenOutputNotProvided(): void
    {
        $this->store->method('query')->willReturn([]);

        $exporter = $this->createExporter();
        $command = new ConsoleExportCommand($exporter);

        // Change to temp directory for default file creation
        $originalDir = getcwd();
        if ($originalDir !== false) {
            chdir($this->tempDir);
        }

        $input = new ArrayInput('studio:console:export');
        $exit = $command->execute($input, $this->output);

        // Restore original directory
        if ($originalDir !== false) {
            chdir($originalDir);
        }

        self::assertSame(ExitCode::Success->value, $exit);
        // Default filename pattern: studio-export-{timestamp}.json
        self::assertMatchesRegularExpression('/studio-export-\d+\.json/', $this->output->buffer);
    }

    #[Test]
    public function executeWithMultipleEvents(): void
    {
        $events = [
            ['event_id' => 'evt-1', 'event_type' => 'http.request'],
            ['event_id' => 'evt-2', 'event_type' => 'http.response'],
            ['event_id' => 'evt-3', 'event_type' => 'database.query'],
        ];
        $this->store->method('query')->willReturn($events);

        $exporter = $this->createExporter();
        $command = new ConsoleExportCommand($exporter);

        $outputPath = $this->tempDir . '/test-multiple-events.json';
        $input = new ArrayInput('studio:console:export', [], ['output' => $outputPath]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Events:      3', $this->output->buffer);

        // Clean up
        if (is_file($outputPath)) {
            unlink($outputPath);
        }
    }

    #[Test]
    public function executeWithEmptyEventStore(): void
    {
        $this->store->method('query')->willReturn([]);

        $exporter = $this->createExporter();
        $command = new ConsoleExportCommand($exporter);

        $outputPath = $this->tempDir . '/test-empty.json';
        $input = new ArrayInput('studio:console:export', [], ['output' => $outputPath]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Events:      0', $this->output->buffer);

        // Clean up
        if (is_file($outputPath)) {
            unlink($outputPath);
        }
    }
}
