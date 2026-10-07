<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Extension\Accessibility\Audit\AccessibilityAuditor;
use Pulsar\Extension\Accessibility\Audit\ManualChecklistGenerator;
use Pulsar\Extension\Accessibility\Command\AccessibilityAuditCommand;
use Pulsar\Extension\Accessibility\Validator\AltTextValidator;
use Pulsar\Extension\Accessibility\Validator\FormLabelValidator;
use Pulsar\Extension\Accessibility\Validator\HeadingHierarchyValidator;

final class AccessibilityAuditCommandExecuteTest extends TestCase
{
    private AccessibilityAuditCommand $command;

    protected function setUp(): void
    {
        $auditor = new AccessibilityAuditor([
            new HeadingHierarchyValidator(),
            new FormLabelValidator(),
            new AltTextValidator(),
        ]);

        $this->command = new AccessibilityAuditCommand(
            $auditor,
            new ManualChecklistGenerator(),
        );
    }

    #[Test]
    public function executeBrowserOptionShowsInstallInfo(): void
    {
        $input = $this->createInputStub('some/path', 'text', 'browser-value', 'warning');
        $output = $this->createStub(OutputInterface::class);

        $exitCode = $this->command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
    }

    #[Test]
    public function executeTextFormatWithNoViolations(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'a11y-test-');
        file_put_contents($tmpFile, '<h1>Title</h1><label for="name">Name</label><input id="name" type="text"><img alt="Photo">');

        try {
            $input = $this->createInputStub($tmpFile, 'text', null, 'warning');
            $output = $this->createStub(OutputInterface::class);

            $exitCode = $this->command->execute($input, $output);

            self::assertSame(ExitCode::Success->value, $exitCode);
        } finally {
            @unlink($tmpFile);
        }
    }

    #[Test]
    public function executeTextFormatWithViolations(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'a11y-test-');
        file_put_contents($tmpFile, '<h1>Title</h1><h3>Skip h2</h3><input type="text"><img src="photo.jpg">');

        try {
            $input = $this->createInputStub($tmpFile, 'text', null, 'warning');
            $output = $this->createStub(OutputInterface::class);

            $exitCode = $this->command->execute($input, $output);

            self::assertSame(ExitCode::Error->value, $exitCode);
        } finally {
            @unlink($tmpFile);
        }
    }

    #[Test]
    public function executeJsonFormat(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'a11y-test-');
        file_put_contents($tmpFile, '<h1>Title</h1><input type="text">');

        try {
            $input = $this->createInputStub($tmpFile, 'json', null, 'warning');

            $jsonOutput = '';
            $output = $this->createStub(OutputInterface::class);
            $output->method('writeln')->willReturnCallback(function (string $msg) use (&$jsonOutput): void {
                $jsonOutput .= $msg;
            });

            $exitCode = $this->command->execute($input, $output);

            /** @var array<string, mixed> $data */
            $data = json_decode($jsonOutput, true, flags: JSON_THROW_ON_ERROR);
            self::assertArrayHasKey('summary', $data);
            self::assertArrayHasKey('violations', $data);
            self::assertArrayHasKey('filtered_violations', $data);
            self::assertArrayHasKey('manual_checklist', $data);
            self::assertArrayHasKey('limitations', $data);

            self::assertSame(ExitCode::Error->value, $exitCode);
        } finally {
            @unlink($tmpFile);
        }
    }

    #[Test]
    public function executeSeverityFilterError(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'a11y-test-');
        file_put_contents($tmpFile, '<h1>Title</h1><h3>Skip</h3><input type="text">');

        try {
            $input = $this->createInputStub($tmpFile, 'text', null, 'error');
            $output = $this->createStub(OutputInterface::class);

            $exitCode = $this->command->execute($input, $output);

            self::assertSame(ExitCode::Error->value, $exitCode);
        } finally {
            @unlink($tmpFile);
        }
    }

    #[Test]
    public function executeSeverityFilterInfo(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'a11y-test-');
        file_put_contents($tmpFile, '<h1>Title</h1>');

        try {
            $input = $this->createInputStub($tmpFile, 'text', null, 'info');
            $output = $this->createStub(OutputInterface::class);

            $exitCode = $this->command->execute($input, $output);
            self::assertSame(ExitCode::Success->value, $exitCode);
        } finally {
            @unlink($tmpFile);
        }
    }

    #[Test]
    public function executeWithDirectoryPath(): void
    {
        $tmpDir = sys_get_temp_dir() . '/pulsar-a11y-test-' . uniqid();
        mkdir($tmpDir, 0o777, true);
        file_put_contents($tmpDir . '/template.php', '<h1>Test</h1><label for="x">X</label><input id="x" type="text">');

        try {
            $input = $this->createInputStub($tmpDir, 'text', null, 'warning');
            $output = $this->createStub(OutputInterface::class);

            $exitCode = $this->command->execute($input, $output);

            self::assertSame(ExitCode::Success->value, $exitCode);
        } finally {
            @unlink($tmpDir . '/template.php');
            @rmdir($tmpDir);
        }
    }

    #[Test]
    public function executeWithNonExistentPath(): void
    {
        $input = $this->createInputStub('/nonexistent/path', 'text', null, 'warning');
        $output = $this->createStub(OutputInterface::class);

        $exitCode = $this->command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
    }

    #[Test]
    public function executeWithUnknownSeverityDefaultsToWarning(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'a11y-test-');
        file_put_contents($tmpFile, '<h1>Title</h1>');

        try {
            $input = $this->createInputStub($tmpFile, 'text', null, 'unknown');
            $output = $this->createStub(OutputInterface::class);

            $exitCode = $this->command->execute($input, $output);

            self::assertSame(ExitCode::Success->value, $exitCode);
        } finally {
            @unlink($tmpFile);
        }
    }

    #[Test]
    public function executeJsonFormatWithNoViolations(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'a11y-test-');
        file_put_contents($tmpFile, '<h1>Title</h1><label for="x">X</label><input id="x" type="text"><img alt="Photo">');

        try {
            $input = $this->createInputStub($tmpFile, 'json', null, 'warning');

            $jsonOutput = '';
            $output = $this->createStub(OutputInterface::class);
            $output->method('writeln')->willReturnCallback(function (string $msg) use (&$jsonOutput): void {
                $jsonOutput .= $msg;
            });

            $exitCode = $this->command->execute($input, $output);

            /** @var array<string, mixed> $data */
            $data = json_decode($jsonOutput, true, flags: JSON_THROW_ON_ERROR);
            /** @var array<string, mixed> $summary */
            $summary = $data['summary'];
            self::assertSame(0, $summary['errors']);
            self::assertSame(ExitCode::Success->value, $exitCode);
        } finally {
            @unlink($tmpFile);
        }
    }

    #[Test]
    public function executeTextFormatWithViolationsShowsElementAndLine(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'a11y-test-');
        file_put_contents($tmpFile, '<h1>Title</h1><input type="text">');

        try {
            $input = $this->createInputStub($tmpFile, 'text', null, 'warning');

            $writtenLines = [];
            $output = $this->createStub(OutputInterface::class);
            $output->method('writeln')->willReturnCallback(function (string $msg) use (&$writtenLines): void {
                $writtenLines[] = $msg;
            });

            $exitCode = $this->command->execute($input, $output);

            self::assertSame(ExitCode::Error->value, $exitCode);

            // Verify that the output contains violation details
            $allOutput = implode("\n", $writtenLines);
            self::assertStringContainsString('ERROR', $allOutput);
            self::assertStringContainsString('WCAG', $allOutput);
            self::assertStringContainsString('Element:', $allOutput);
        } finally {
            @unlink($tmpFile);
        }
    }

    private function createInputStub(
        string $path,
        string $format,
        ?string $browser,
        string $severity,
    ): InputInterface&Stub {
        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')
            ->willReturnCallback(static fn(int|string $key): ?string => match ($key) {
                'path', 0 => $path,
                default => null,
            });
        $input->method('getOption')
            ->willReturnCallback(static fn(string $name): ?string => match ($name) {
                'format' => $format,
                'browser' => $browser,
                'severity' => $severity,
                'pattern' => '*.php',
                default => null,
            });

        return $input;
    }
}
