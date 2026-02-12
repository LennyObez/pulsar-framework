<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Introspection\Data;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Introspection\Data\CommandEntry;

#[CoversClass(CommandEntry::class)]
final class CommandEntryTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $entry = new CommandEntry(
            name: 'make:model',
            description: 'Create a new model',
            arguments: [['name' => 'name', 'description' => 'The model name', 'required' => true]],
            options: ['migration' => ['description' => 'Also create migration', 'shortcut' => 'm', 'default' => false]],
        );

        self::assertSame('make:model', $entry->name);
        self::assertSame('Create a new model', $entry->description);
        self::assertCount(1, $entry->arguments);
        self::assertArrayHasKey('migration', $entry->options);
    }

    #[Test]
    public function constructorDefaultsToEmptyArgumentsAndOptions(): void
    {
        $entry = new CommandEntry(
            name: 'cache:clear',
            description: 'Clear the cache',
        );

        self::assertSame([], $entry->arguments);
        self::assertSame([], $entry->options);
    }

    #[Test]
    public function toArrayReturnsExpectedStructure(): void
    {
        $entry = new CommandEntry(
            name: 'migrate',
            description: 'Run migrations',
            arguments: [['name' => 'step', 'description' => 'Steps', 'required' => false]],
            options: ['force' => ['description' => 'Force', 'shortcut' => null, 'default' => false]],
        );

        $array = $entry->toArray();

        self::assertSame('migrate', $array['name']);
        self::assertSame('Run migrations', $array['description']);
        self::assertCount(1, $array['arguments']);
        self::assertArrayHasKey('force', $array['options']);
    }
}
