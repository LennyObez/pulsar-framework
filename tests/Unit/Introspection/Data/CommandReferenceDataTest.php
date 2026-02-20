<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Introspection\Data;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Introspection\Data\CommandEntry;
use Pulsar\Introspection\Data\CommandReferenceData;

#[CoversClass(CommandReferenceData::class)]
final class CommandReferenceDataTest extends TestCase
{
    #[Test]
    public function constructorDefaultsToEmptyCommands(): void
    {
        $ref = new CommandReferenceData();

        self::assertSame([], $ref->commands);
    }

    #[Test]
    public function toArraySerializesCommands(): void
    {
        $command = new CommandEntry(
            name: 'make:controller',
            description: 'Create a controller',
        );

        $ref = new CommandReferenceData(commands: [$command]);
        $array = $ref->toArray();

        self::assertArrayHasKey('commands', $array);
        self::assertCount(1, $array['commands']);
        self::assertSame('make:controller', $array['commands'][0]['name']);
    }
}
