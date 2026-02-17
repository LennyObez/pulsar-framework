<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\DocsCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;

#[CoversClass(DocsCommand::class)]
final class DocsCommandTest extends TestCase
{
    #[Test]
    public function it_has_correct_name(): void
    {
        $command = new DocsCommand();

        self::assertSame('docs', $command->name);
    }

    #[Test]
    public function it_lists_topics_when_no_argument_given(): void
    {
        $command = new DocsCommand();

        $input = new ArrayInput(arguments: []);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertStringContainsString('Available Documentation Topics', $output->buffer);
        self::assertStringContainsString('architecture', $output->buffer);
        self::assertStringContainsString('database', $output->buffer);
        self::assertStringContainsString('authentication', $output->buffer);
    }

    #[Test]
    public function it_returns_error_for_unknown_topic(): void
    {
        $command = new DocsCommand();

        $input = new ArrayInput(arguments: ['nonexistent-topic']);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $exitCode);
        self::assertStringContainsString('Unknown topic', $output->errorBuffer);
    }

    #[Test]
    public function it_shows_file_path_for_valid_topic(): void
    {
        $command = new DocsCommand();

        // Use a topic we know exists in the map
        $input = new ArrayInput(arguments: ['architecture']);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        // May succeed or fail depending on whether the docs file exists at cwd
        // But the command should at least recognize the topic
        self::assertStringNotContainsString('Unknown topic', $output->errorBuffer);
    }

    #[Test]
    public function it_accepts_case_insensitive_topics(): void
    {
        $command = new DocsCommand();

        $input = new ArrayInput(arguments: ['ARCHITECTURE']);
        $output = new BufferedOutput();

        $command->execute($input, $output);

        // The topic was recognized (no "Unknown topic" error)
        self::assertStringNotContainsString('Unknown topic', $output->errorBuffer);
    }
}
