<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Extension\Cms\Command\CmsImportCommand;
use Pulsar\Extension\Cms\Tools\ImportExportServiceInterface;
use Pulsar\Extension\Cms\Tools\ImportResult;

use function bin2hex;
use function file_put_contents;
use function is_dir;
use function is_string;
use function json_encode;
use function mkdir;
use function random_bytes;
use function sys_get_temp_dir;
use function tempnam;

use const JSON_THROW_ON_ERROR;

#[CoversClass(CmsImportCommand::class)]
final class CmsImportCommandTest extends TestCase
{
    /** @var list<string> Temp files to clean up after each test */
    private array $tempFiles = [];

    /** @var list<string> Temp dirs to clean up after each test */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            $this->removeTempFile($path);
        }

        foreach ($this->tempDirs as $path) {
            $this->removeTempDir($path);
        }

        $this->tempFiles = [];
        $this->tempDirs = [];
    }

    #[Test]
    public function configuredCorrectly(): void
    {
        $service = $this->createStub(ImportExportServiceInterface::class);
        $command = new CmsImportCommand($service);

        self::assertSame('cms:import', $command->name);
        self::assertNotEmpty($command->description);
    }

    #[Test]
    public function failsWhenFilePathMissing(): void
    {
        $service = $this->createStub(ImportExportServiceInterface::class);
        $command = new CmsImportCommand($service);

        $input = new ArrayInput('cms:import');
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Invalid->value, $exitCode);
        self::assertStringContainsString('Missing required argument', $output->buffer . $output->errorBuffer);
    }

    #[Test]
    public function failsWhenFileDoesNotExist(): void
    {
        $service = $this->createStub(ImportExportServiceInterface::class);
        $command = new CmsImportCommand($service);

        $input = new ArrayInput('cms:import', ['/nonexistent/path/file.json']);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $exitCode);
        self::assertStringContainsString('File not found', $output->buffer . $output->errorBuffer);
    }

    #[Test]
    public function dryRunImportShowsResults(): void
    {
        $importResult = new ImportResult(
            created: ['content' => 5, 'taxonomy' => 2],
            updated: [],
            skipped: ['content' => 1],
            warnings: ['Duplicate slug detected'],
            errors: [],
            dryRun: true,
        );

        $service = $this->createMock(ImportExportServiceInterface::class);
        $service->expects(self::once())
            ->method('importUnifiedFile')
            ->with(self::callback(static fn(mixed $v): bool => is_string($v)), true)
            ->willReturn($importResult);

        $command = new CmsImportCommand($service);
        $filePath = $this->createTempJsonFile(['content' => []]);

        $input = new ArrayInput('cms:import', [$filePath]);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        $text = $output->buffer . $output->errorBuffer;
        self::assertStringContainsString('Dry-run', $text);
        self::assertStringContainsString('Created: 7', $text);
        self::assertStringContainsString('Skipped: 1', $text);
        self::assertStringContainsString('Duplicate slug', $text);
    }

    #[Test]
    public function executeImportPersistsChanges(): void
    {
        $importResult = new ImportResult(
            created: ['content' => 3],
            updated: ['content' => 1],
            skipped: [],
            warnings: [],
            errors: [],
            dryRun: false,
        );

        $service = $this->createMock(ImportExportServiceInterface::class);
        $service->expects(self::once())
            ->method('importUnifiedFile')
            ->with(self::callback(static fn(mixed $v): bool => is_string($v)), false)
            ->willReturn($importResult);

        $command = new CmsImportCommand($service);
        $filePath = $this->createTempJsonFile(['content' => []]);

        $input = new ArrayInput('cms:import', [$filePath], ['execute' => true]);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        $text = $output->buffer . $output->errorBuffer;
        self::assertStringContainsString('Import completed successfully', $text);
    }

    #[Test]
    public function siteDefinitionFlagRoutesSiteDefinitionImport(): void
    {
        $importResult = new ImportResult(
            created: ['content' => 10],
            updated: [],
            skipped: [],
            warnings: [],
            errors: [],
            dryRun: true,
        );

        $service = $this->createMock(ImportExportServiceInterface::class);
        $service->expects(self::once())
            ->method('importSiteDefinition')
            ->with(self::callback(static fn(mixed $v): bool => is_string($v)), true)
            ->willReturn($importResult);

        $command = new CmsImportCommand($service);
        $filePath = $this->createTempJsonFile(['site' => []]);

        $input = new ArrayInput('cms:import', [$filePath], ['site-definition' => true]);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
    }

    #[Test]
    public function errorsInResultCauseFailureExitCode(): void
    {
        $importResult = new ImportResult(
            created: ['content' => 2],
            updated: [],
            skipped: [],
            warnings: [],
            errors: ['Failed to create taxonomy: missing parent'],
            dryRun: false,
        );

        $service = $this->createStub(ImportExportServiceInterface::class);
        $service->method('importUnifiedFile')->willReturn($importResult);

        $command = new CmsImportCommand($service);
        $filePath = $this->createTempJsonFile(['content' => []]);

        $input = new ArrayInput('cms:import', [$filePath], ['execute' => true]);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $exitCode);
        self::assertStringContainsString('Failed to create taxonomy', $output->buffer . $output->errorBuffer);
    }

    //: Directory Import Tests --

    #[Test]
    public function directoryImportProcessesAllJsonFiles(): void
    {
        $importResult = new ImportResult(
            created: ['content' => 2],
            updated: [],
            skipped: [],
            warnings: [],
            errors: [],
            dryRun: true,
        );

        $service = $this->createStub(ImportExportServiceInterface::class);
        $service->method('importUnifiedFile')->willReturn($importResult);

        $command = new CmsImportCommand($service);
        $dirPath = $this->createTempDir();
        $this->writeTempDirFile($dirPath, 'pages.json', ['content' => []]);
        $this->writeTempDirFile($dirPath, 'taxonomies.json', ['taxonomies' => []]);

        $input = new ArrayInput('cms:import', [$dirPath]);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        $text = $output->buffer . $output->errorBuffer;
        self::assertStringContainsString('Found 2 JSON file(s)', $text);
        self::assertStringContainsString('Combined Results', $text);
        self::assertStringContainsString('Created: 4', $text);
    }

    #[Test]
    public function directoryImportFailsWhenNoJsonFilesFound(): void
    {
        $service = $this->createStub(ImportExportServiceInterface::class);
        $command = new CmsImportCommand($service);
        $dirPath = $this->createTempDir();

        $input = new ArrayInput('cms:import', [$dirPath]);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $exitCode);
        self::assertStringContainsString('No .json files found', $output->buffer . $output->errorBuffer);
    }

    #[Test]
    public function directoryImportMergesResultsFromMultipleFiles(): void
    {
        $callCount = 0;
        $service = $this->createStub(ImportExportServiceInterface::class);
        $service->method('importUnifiedFile')->willReturnCallback(
            static function () use (&$callCount): ImportResult {
                $callCount++;

                return match ($callCount) {
                    1 => new ImportResult(
                        created: ['content' => 3],
                        updated: [],
                        skipped: [],
                        warnings: [],
                        errors: [],
                        dryRun: false,
                    ),
                    2 => new ImportResult(
                        created: ['taxonomies' => 2],
                        updated: ['content' => 1],
                        skipped: [],
                        warnings: ['Minor issue'],
                        errors: [],
                        dryRun: false,
                    ),
                    default => new ImportResult(
                        created: [],
                        updated: [],
                        skipped: [],
                        warnings: [],
                        errors: [],
                        dryRun: false,
                    ),
                };
            },
        );

        $command = new CmsImportCommand($service);
        $dirPath = $this->createTempDir();
        $this->writeTempDirFile($dirPath, '01-content.json', ['content' => []]);
        $this->writeTempDirFile($dirPath, '02-taxonomies.json', ['taxonomies' => []]);

        $input = new ArrayInput('cms:import', [$dirPath], ['execute' => true]);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        $text = $output->buffer . $output->errorBuffer;
        // Combined: 3 content + 2 taxonomies = 5 created
        self::assertStringContainsString('Created: 5', $text);
        self::assertStringContainsString('Updated: 1', $text);
        self::assertStringContainsString('Minor issue', $text);
    }

    //: Auto-detection (no --site-definition flag needed) --

    #[Test]
    public function autoDetectsFileTypeWithoutFlag(): void
    {
        $importResult = new ImportResult(
            created: ['content' => 1],
            updated: [],
            skipped: [],
            warnings: [],
            errors: [],
            dryRun: true,
        );

        $service = $this->createMock(ImportExportServiceInterface::class);
        $service->expects(self::once())
            ->method('importUnifiedFile')
            ->willReturn($importResult);

        $command = new CmsImportCommand($service);
        $filePath = $this->createTempJsonFile(['content' => [['slug' => 'test']]]);

        $input = new ArrayInput('cms:import', [$filePath]);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
    }

    //: Helpers --

    /**
     * Create a temp JSON file and register it for cleanup.
     *
     * @param array<string, mixed> $data
     */
    private function createTempJsonFile(array $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pulsar_test_');
        self::assertIsString($path);

        // tempnam creates the file; we overwrite with JSON content
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * Create a temp directory and register it for cleanup.
     */
    private function createTempDir(): string
    {
        $path = sys_get_temp_dir() . '/pulsar_test_dir_' . bin2hex(random_bytes(8));
        mkdir($path, 0o777, true);
        $this->tempDirs[] = $path;

        return $path;
    }

    /**
     * Write a JSON file inside a temp directory and register for cleanup.
     *
     * @param array<string, mixed> $data
     */
    private function writeTempDirFile(string $dir, string $filename, array $data): void
    {
        $path = $dir . '/' . $filename;
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
        $this->tempFiles[] = $path;
    }

    /**
     * Safely remove a temp file created by this test.
     *
     * Only removes files within sys_get_temp_dir() as a safety measure.
     */
    private function removeTempFile(string $path): void
    {
        // Safety: only clean up files in the system temp directory
        $tempDir = sys_get_temp_dir();

        if (str_starts_with($path, $tempDir) && is_file($path)) {
            unlink($path);
        }
    }

    /**
     * Safely remove a temp directory created by this test.
     *
     * Only removes directories within sys_get_temp_dir() as a safety measure.
     */
    private function removeTempDir(string $path): void
    {
        $tempDir = sys_get_temp_dir();

        if (str_starts_with($path, $tempDir) && is_dir($path)) {
            rmdir($path);
        }
    }
}
