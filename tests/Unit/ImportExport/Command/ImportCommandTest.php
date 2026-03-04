<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\ImportExport\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\ImportExport\Command\ImportCommand;
use Pulsar\ImportExport\ImportExportRegistry;

#[CoversClass(ImportCommand::class)]
final class ImportCommandTest extends TestCase
{
    #[Test]
    public function failsWhenFilePathMissing(): void
    {
        $command = new ImportCommand(new ImportExportRegistry());

        $input = $this->createInputStub(arguments: []);

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::once())->method('errorln')
            ->with('File path is required.');

        $code = $command->execute($input, $output);

        self::assertSame(ExitCode::Invalid->value, $code);
    }

    #[Test]
    public function failsWhenFileNotFound(): void
    {
        $command = new ImportCommand(new ImportExportRegistry());

        $input = $this->createInputStub(arguments: ['/nonexistent/file.json']);

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::once())->method('errorln')
            ->with(self::stringContains('File not found'));

        $code = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $code);
    }

    #[Test]
    public function hasCorrectConfiguration(): void
    {
        $command = new ImportCommand(new ImportExportRegistry());

        self::assertSame('import:run', $command->name);
        self::assertNotEmpty($command->description);
        self::assertCount(1, $command->arguments);
        self::assertArrayHasKey('provider', $command->options);
        self::assertArrayHasKey('dry-run', $command->options);
        self::assertArrayHasKey('duplicates', $command->options);
        self::assertArrayHasKey('format', $command->options);
    }

    #[Test]
    public function readsExistingFileSuccessfully(): void
    {
        // Use __FILE__ as an existing readable file — the command will attempt
        // JSON decode of PHP source and fail to resolve a provider, proving
        // the file-read path works without needing temp file cleanup.
        $command = new ImportCommand(new ImportExportRegistry());
        $input = $this->createInputStub(arguments: [__FILE__]);

        $output = $this->createMock(OutputInterface::class);
        // Expect provider resolution failure (not file-read failure)
        $output->expects(self::once())->method('errorln')
            ->with(self::stringContains('Could not determine target provider'));

        $code = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $code);
    }

    /**
     * @param list<string> $arguments
     * @param array<string, mixed> $options
     */
    private function createInputStub(
        array $arguments = [],
        array $options = [],
    ): InputInterface&Stub {
        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturnCallback(
            fn(int|string $key) => $arguments[$key] ?? null,
        );
        $input->method('getOption')->willReturnCallback(
            fn(string $name) => $options[$name] ?? null,
        );
        $input->method('hasOption')->willReturnCallback(
            fn(string $name) => isset($options[$name]),
        );

        return $input;
    }
}
