<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Command\Console;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Extension\Studio\Command\Console\ConsoleBenchmarkCommand;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;

use function dirname;

#[CoversClass(ConsoleBenchmarkCommand::class)]
final class ConsoleBenchmarkCommandTest extends TestCase
{
    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->output = new BufferedOutput();
    }

    #[Test]
    public function configuredCorrectly(): void
    {
        $command = $this->createCommand();

        self::assertSame('studio:console:bench', $command->name);
        self::assertSame('Run performance benchmarks and emit Studio events', $command->description);
        self::assertArrayHasKey('json', $command->options);
        self::assertSame('j', $command->options['json']['shortcut']);
        self::assertArrayHasKey('profile', $command->options);
        self::assertSame('p', $command->options['profile']['shortcut']);
        self::assertArrayHasKey('output', $command->options);
    }

    #[Test]
    public function executeFailsWhenProfilesJsonNotFound(): void
    {
        $command = $this->createCommand(basePath: '/nonexistent/path');
        $input = new ArrayInput('studio:console:bench');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);
    }

    #[Test]
    public function executeFailsJsonModeWhenProfilesNotFound(): void
    {
        $command = $this->createCommand(basePath: '/nonexistent/path');
        $input = new ArrayInput('studio:console:bench', [], ['json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('profiles_not_found', $this->output->buffer);
    }

    #[Test]
    public function executeFailsWhenSingleProfileNotFound(): void
    {
        $basePath = dirname(__DIR__, 5);
        $command = $this->createCommand(basePath: $basePath);
        $input = new ArrayInput('studio:console:bench', [], ['profile' => 'nonexistent-profile']);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);
    }

    #[Test]
    public function executeFailsJsonModeWhenSingleProfileNotFound(): void
    {
        $basePath = dirname(__DIR__, 5);
        $command = $this->createCommand(basePath: $basePath);
        $input = new ArrayInput('studio:console:bench', [], ['json' => true, 'profile' => 'nonexistent-profile']);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('profile_not_found', $this->output->buffer);
    }

    private function createCommand(?string $basePath = null): ConsoleBenchmarkCommand
    {
        $emit = static function (ConsoleEvent $event, mixed $context): void {};

        return new ConsoleBenchmarkCommand(
            Closure::fromCallable($emit),
            $basePath ?? '/nonexistent',
        );
    }
}
