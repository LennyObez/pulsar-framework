<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\ImportExport\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\ImportExport\Command\ExportCommand;
use Pulsar\ImportExport\ExportResult;
use Pulsar\ImportExport\ImportExportProviderInterface;
use Pulsar\ImportExport\ImportExportRegistry;

#[CoversClass(ExportCommand::class)]
final class ExportCommandTest extends TestCase
{
    #[Test]
    public function warnsWhenNoProvidersRegistered(): void
    {
        $registry = new ImportExportRegistry();
        $command = new ExportCommand($registry);

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::once())->method('warning')
            ->with('No import/export providers registered.');

        $code = $command->execute($this->createInput(), $output);

        self::assertSame(ExitCode::Success->value, $code);
    }

    #[Test]
    public function failsForUnknownProvider(): void
    {
        $registry = new ImportExportRegistry();
        $registry->register($this->createProviderStub('cms'));

        $command = new ExportCommand($registry);

        $input = $this->createInput(['providers' => 'nonexistent']);

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::once())->method('errorln')
            ->with(self::stringContains('nonexistent'));

        $code = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $code);
    }

    #[Test]
    public function exportsFromAllProviders(): void
    {
        $registry = new ImportExportRegistry();

        $cms = $this->createProviderStub('cms');
        $cms->method('export')->willReturn(
            new ExportResult('cms', ['content' => []], 'json', 'h1', ['content']),
        );
        $registry->register($cms);

        $command = new ExportCommand($registry);
        $input = $this->createInput();

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('success');

        $code = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $code);
    }

    #[Test]
    public function hasCorrectConfiguration(): void
    {
        $command = new ExportCommand(new ImportExportRegistry());

        self::assertSame('export:run', $command->name);
        self::assertNotEmpty($command->description);
        self::assertArrayHasKey('providers', $command->options);
        self::assertArrayHasKey('format', $command->options);
        self::assertArrayHasKey('output', $command->options);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function createInput(array $options = []): InputInterface
    {
        return new ArrayInput(null, [], $options);
    }

    private function createProviderStub(string $name): ImportExportProviderInterface&Stub
    {
        $stub = $this->createStub(ImportExportProviderInterface::class);
        $stub->method('name')->willReturn($name);
        $stub->method('supportedFormats')->willReturn(['json']);

        return $stub;
    }
}
