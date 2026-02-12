<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\DebugContainerCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Container\Container;
use Pulsar\Container\Lifetime;
use Pulsar\Core\Kernel;
use stdClass;

#[CoversClass(DebugContainerCommand::class)]
final class DebugContainerCommandTest extends TestCase
{
    #[Test]
    public function nameIsDebugContainer(): void
    {
        $command = new DebugContainerCommand(new Kernel());

        self::assertSame('debug:container', $command->name);
    }

    #[Test]
    public function descriptionIsSet(): void
    {
        $command = new DebugContainerCommand(new Kernel());

        self::assertNotEmpty($command->description);
    }

    #[Test]
    public function hasCheckLifetimesOption(): void
    {
        $command = new DebugContainerCommand(new Kernel());

        self::assertArrayHasKey('check-lifetimes', $command->options);
    }

    #[Test]
    public function hasFilterOption(): void
    {
        $command = new DebugContainerCommand(new Kernel());

        self::assertArrayHasKey('filter', $command->options);
    }

    #[Test]
    public function hasTagsOption(): void
    {
        $command = new DebugContainerCommand(new Kernel());

        self::assertArrayHasKey('tags', $command->options);
    }

    #[Test]
    public function showBindingsDefaultMode(): void
    {
        $container = new Container();
        $container->bind('test.service', stdClass::class);

        $kernel = new Kernel($container);
        $command = new DebugContainerCommand($kernel);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('debug:container'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Container Bindings', $output->buffer);
    }

    #[Test]
    public function checkLifetimesPassesWithValidScopes(): void
    {
        $container = new Container();
        $container->bindWithLifetime('test.service', stdClass::class, Lifetime::Singleton);

        $kernel = new Kernel($container);
        $command = new DebugContainerCommand($kernel);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('debug:container', [], ['check-lifetimes' => true]),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('No scope widening violations', $output->buffer);
    }

    #[Test]
    public function showTagsWithNoTags(): void
    {
        $container = new Container();
        $kernel = new Kernel($container);
        $command = new DebugContainerCommand($kernel);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('debug:container', [], ['tags' => true]),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('No tagged services', $output->buffer);
    }

    #[Test]
    public function showTagsWithTaggedServices(): void
    {
        $container = new Container();
        $container->bind('test.service', stdClass::class);
        $container->tag('test.service', 'event.listener', 10);

        $kernel = new Kernel($container);
        $command = new DebugContainerCommand($kernel);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('debug:container', [], ['tags' => true]),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('event.listener', $output->buffer);
    }

    #[Test]
    public function filterNarrowsResults(): void
    {
        $container = new Container();
        $container->bind('foo.service', stdClass::class);
        $container->bind('bar.service', stdClass::class);

        $kernel = new Kernel($container);
        $command = new DebugContainerCommand($kernel);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('debug:container', [], ['filter' => 'foo']),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('foo.service', $output->buffer);
        self::assertStringNotContainsString('bar.service', $output->buffer);
    }
}
