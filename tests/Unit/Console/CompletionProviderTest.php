<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command;
use Pulsar\Console\CompletionProvider;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

use function count;

#[CoversClass(CompletionProvider::class)]
final class CompletionProviderTest extends TestCase
{
    /**
     * @return array<string, StubCommand>
     */
    private function createStubCommands(): array
    {
        return [
            'make:model' => new StubCommand('make:model', 'Create a model', ['name' => ['description' => 'Model name', 'shortcut' => null, 'default' => null]]),
            'make:controller' => new StubCommand('make:controller', 'Create a controller', ['resource' => ['description' => 'Resource controller', 'shortcut' => 'r', 'default' => null]]),
            'serve' => new StubCommand('serve', 'Start dev server'),
            'migrate' => new StubCommand('migrate', 'Run migrations'),
            'migrate:rollback' => new StubCommand('migrate:rollback', 'Rollback migrations'),
        ];
    }

    // --- complete() ---

    #[Test]
    public function completeReturnsMatchingCommands(): void
    {
        $provider = new CompletionProvider($this->createStubCommands());

        $results = $provider->complete('make');

        self::assertContains('make:model', $results);
        self::assertContains('make:controller', $results);
        self::assertNotContains('serve', $results);
    }

    #[Test]
    public function completeReturnsAllCommandsForEmptyInput(): void
    {
        $commands = $this->createStubCommands();
        $provider = new CompletionProvider($commands);

        $results = $provider->complete('');

        self::assertCount(count($commands), $results);
    }

    #[Test]
    public function completeReturnsEmptyForNoMatch(): void
    {
        $provider = new CompletionProvider($this->createStubCommands());

        $results = $provider->complete('zzz');

        self::assertSame([], $results);
    }

    #[Test]
    public function completeReturnsOptionsInCommandContext(): void
    {
        $provider = new CompletionProvider($this->createStubCommands());

        $results = $provider->complete('--', 'make:model');

        self::assertContains('--name', $results);
        self::assertContains('--help', $results);
    }

    #[Test]
    public function completeReturnsShortOptionsInCommandContext(): void
    {
        $provider = new CompletionProvider($this->createStubCommands());

        $results = $provider->complete('-', 'make:model');

        self::assertContains('-h', $results);
        self::assertContains('-q', $results);
    }

    #[Test]
    public function completePrefixFiltersCommands(): void
    {
        $provider = new CompletionProvider($this->createStubCommands());

        $results = $provider->complete('migr');

        self::assertContains('migrate', $results);
        self::assertContains('migrate:rollback', $results);
        self::assertNotContains('serve', $results);
    }

    // --- generate() ---

    #[Test]
    public function generateBashReturnsValidScript(): void
    {
        $provider = new CompletionProvider($this->createStubCommands(), 'pulsar');

        $script = $provider->generate('bash');

        self::assertStringContainsString('_pulsar_completions', $script);
        self::assertStringContainsString('complete -F', $script);
        self::assertStringContainsString('make:model', $script);
        self::assertStringContainsString('COMPREPLY', $script);
        self::assertStringContainsString('compgen', $script);
    }

    #[Test]
    public function generateZshReturnsValidScript(): void
    {
        $provider = new CompletionProvider($this->createStubCommands(), 'pulsar');

        $script = $provider->generate('zsh');

        self::assertStringContainsString('#compdef pulsar', $script);
        self::assertStringContainsString('_pulsar', $script);
        self::assertStringContainsString('_arguments', $script);
        self::assertStringContainsString('make:model', $script);
    }

    #[Test]
    public function generateFishReturnsValidScript(): void
    {
        $provider = new CompletionProvider($this->createStubCommands(), 'pulsar');

        $script = $provider->generate('fish');

        self::assertStringContainsString('complete -c pulsar', $script);
        self::assertStringContainsString('__fish_use_subcommand', $script);
        self::assertStringContainsString('make:model', $script);
        self::assertStringContainsString('__fish_seen_subcommand_from', $script);
    }

    #[Test]
    public function generateUnsupportedShellReturnsComment(): void
    {
        $provider = new CompletionProvider($this->createStubCommands());

        $script = $provider->generate('powershell');

        self::assertStringContainsString('Unsupported shell', $script);
        self::assertStringContainsString('powershell', $script);
    }

    #[Test]
    public function generateUsesCustomBinaryName(): void
    {
        $provider = new CompletionProvider($this->createStubCommands(), 'myapp');

        $bash = $provider->generate('bash');
        self::assertStringContainsString('_myapp_completions', $bash);
        self::assertStringContainsString('complete -F _myapp_completions myapp', $bash);

        $zsh = $provider->generate('zsh');
        self::assertStringContainsString('#compdef myapp', $zsh);

        $fish = $provider->generate('fish');
        self::assertStringContainsString('complete -c myapp', $fish);
    }

    // --- Edge cases ---

    #[Test]
    public function emptyCommandListProducesValidScripts(): void
    {
        $provider = new CompletionProvider([]);

        $bash = $provider->generate('bash');
        self::assertStringContainsString('complete -F', $bash);

        $zsh = $provider->generate('zsh');
        self::assertStringContainsString('#compdef', $zsh);

        $fish = $provider->generate('fish');
        self::assertStringContainsString('Fish completion', $fish);
    }

    #[Test]
    public function completeWithUnknownCommandContextReturnsGlobalOptions(): void
    {
        $provider = new CompletionProvider($this->createStubCommands());

        // Unknown command context falls back to global option defaults
        $results = $provider->complete('--', 'unknown:command');

        self::assertSame([], $results);
    }
}

/**
 * Minimal Command stub for testing CompletionProvider.
 */
final class StubCommand extends Command
{
    /**
     * @param array<string, array{description: string, shortcut: string|null, default: mixed}> $opts
     */
    public function __construct(
        string $name,
        string $description = '',
        array $opts = [],
    ) {
        parent::__construct();
        $this->name = $name;
        $this->description = $description;
        $this->options = $opts;
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        return ExitCode::Success->value;
    }
}
