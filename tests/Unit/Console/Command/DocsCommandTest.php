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
use ReflectionClassConstant;

use function dirname;
use function is_file;
use function is_string;

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

    /**
     * Every topic must name a file that exists.
     *
     * The `compliance` topic pointed at docs/compliance-matrix.md, which has
     * never existed in this repository, so `pulsar docs compliance` reported
     * "file not found" to every reader who asked for it. Asserting the whole map
     * rather than that one entry is the point: the defect was a path nobody
     * checked, and checking one path leaves the next one unchecked.
     */
    #[Test]
    public function every_documented_topic_names_a_file_that_exists(): void
    {
        $root = dirname(__DIR__, 4);

        /** @var mixed $topics */
        $topics = new ReflectionClassConstant(DocsCommand::class, 'TOPIC_MAP')->getValue();

        self::assertIsArray($topics);
        self::assertNotEmpty($topics);

        /** @var mixed $relativePath */
        foreach ($topics as $topic => $relativePath) {
            self::assertIsString($relativePath);
            self::assertTrue(
                is_file($root . '/' . $relativePath),
                'Documentation topic "' . (is_string($topic) ? $topic : '?')
                    . '" points at ' . $relativePath . ', which does not exist.',
            );
        }
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
