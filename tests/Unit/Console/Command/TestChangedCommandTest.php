<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\TestChangedCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;

#[CoversClass(TestChangedCommand::class)]
final class TestChangedCommandTest extends TestCase
{
    #[Test]
    public function it_has_correct_name(): void
    {
        $command = new TestChangedCommand($this->createShellStub(''));

        self::assertSame('test:changed', $command->name);
    }

    #[Test]
    public function it_reports_no_changes_when_git_returns_empty(): void
    {
        $command = new TestChangedCommand($this->createShellStub(''));

        $input = new ArrayInput(arguments: []);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertStringContainsString('No PHP files changed', $output->buffer);
    }

    #[Test]
    public function it_maps_source_files_to_test_files(): void
    {
        $changedFiles = "src/Config/AppConfig.php\nsrc/Database/PdoConnection.php\n";

        $command = new TestChangedCommand($this->createShellStub($changedFiles));

        $input = new ArrayInput(arguments: [], options: ['dry-run' => true]);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertStringContainsString('AppConfigTest', $output->buffer);
        self::assertStringContainsString('PdoConnectionTest', $output->buffer);
        self::assertStringContainsString('Dry run', $output->buffer);
    }

    #[Test]
    public function it_includes_test_files_directly(): void
    {
        $changedFiles = "tests/Unit/Config/AppConfigTest.php\n";

        $command = new TestChangedCommand($this->createShellStub($changedFiles));

        $input = new ArrayInput(arguments: [], options: ['dry-run' => true]);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertStringContainsString('AppConfigTest', $output->buffer);
    }

    #[Test]
    public function it_maps_extension_files_correctly(): void
    {
        $changedFiles = "extensions/cms/src/Config/CmsConfig.php\n";

        $command = new TestChangedCommand($this->createShellStub($changedFiles));

        $input = new ArrayInput(arguments: [], options: ['dry-run' => true]);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertStringContainsString('CmsConfigTest', $output->buffer);
    }

    #[Test]
    public function it_deduplicates_test_files(): void
    {
        $changedFiles = "src/Config/AppConfig.php\ntests/Unit/Config/AppConfigTest.php\n";

        $command = new TestChangedCommand($this->createShellStub($changedFiles));

        $input = new ArrayInput(arguments: [], options: ['dry-run' => true]);
        $output = new BufferedOutput();

        $command->execute($input, $output);

        // Count occurrences of "AppConfigTest" -- should appear in list once
        $count = substr_count($output->buffer, 'AppConfigTest.php');
        self::assertSame(1, $count);
    }

    #[Test]
    public function it_uses_custom_from_ref(): void
    {
        $calls = [];
        $executor = function (string $cmd) use (&$calls): string {
            $calls[] = $cmd;
            return '';
        };

        $command = new TestChangedCommand($executor);

        $input = new ArrayInput(arguments: [], options: ['from' => 'HEAD~5']);
        $output = new BufferedOutput();

        $command->execute($input, $output);

        self::assertNotEmpty($calls);
        self::assertStringContainsString('HEAD~5', $calls[0]);
    }

    #[Test]
    public function it_reports_no_test_files_when_none_map(): void
    {
        // Non-PHP or non-mappable files
        $changedFiles = "README.md\ncomposer.json\n";

        $command = new TestChangedCommand($this->createShellStub($changedFiles));

        $input = new ArrayInput(arguments: []);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
    }

    /**
     * @return callable(string): string
     */
    private function createShellStub(string $output): callable
    {
        return static fn(string $command): string => $output;
    }
}
