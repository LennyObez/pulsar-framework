<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Command\PlaygroundServeCommand;

#[CoversClass(PlaygroundServeCommand::class)]
final class PlaygroundServeCommandTest extends TestCase
{
    #[Test]
    public function configuredCorrectly(): void
    {
        $command = new PlaygroundServeCommand('/project');

        self::assertSame('ui:playground', $command->name);
        self::assertNotEmpty($command->description);
    }

    #[Test]
    public function hasPortOption(): void
    {
        $command = new PlaygroundServeCommand('/project');

        self::assertArrayHasKey('port', $command->options);
    }

    #[Test]
    public function hasHostOption(): void
    {
        $command = new PlaygroundServeCommand('/project');

        self::assertArrayHasKey('host', $command->options);
    }
}
