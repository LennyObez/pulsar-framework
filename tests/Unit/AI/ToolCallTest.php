<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\ToolCall;

#[CoversClass(ToolCall::class)]
final class ToolCallTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $tc = new ToolCall(
            id: 'call_abc123',
            name: 'get_weather',
            arguments: ['city' => 'Paris', 'units' => 'celsius'],
        );

        self::assertSame('call_abc123', $tc->id);
        self::assertSame('get_weather', $tc->name);
        self::assertSame(['city' => 'Paris', 'units' => 'celsius'], $tc->arguments);
    }

    #[Test]
    public function constructorAcceptsEmptyArguments(): void
    {
        $tc = new ToolCall(id: 'id1', name: 'no_args', arguments: []);

        self::assertSame([], $tc->arguments);
    }

    #[Test]
    public function fromArrayParsesCompleteData(): void
    {
        $tc = ToolCall::fromArray([
            'id' => 'call_999',
            'name' => 'search_db',
            'arguments' => ['query' => 'SELECT 1', 'limit' => 10],
        ]);

        self::assertSame('call_999', $tc->id);
        self::assertSame('search_db', $tc->name);
        self::assertSame('SELECT 1', $tc->arguments['query']);
        self::assertSame(10, $tc->arguments['limit']);
    }

    #[Test]
    public function fromArrayDefaultsToEmptyStringsAndArray(): void
    {
        $tc = ToolCall::fromArray([]);

        self::assertSame('', $tc->id);
        self::assertSame('', $tc->name);
        self::assertSame([], $tc->arguments);
    }

    #[Test]
    public function fromArrayHandlesNonStringIdGracefully(): void
    {
        $tc = ToolCall::fromArray([
            'id' => 123,
            'name' => true,
            'arguments' => 'not-an-array',
        ]);

        self::assertSame('', $tc->id);
        self::assertSame('', $tc->name);
        self::assertSame([], $tc->arguments);
    }

    #[Test]
    public function fromArrayHandlesNullValues(): void
    {
        $tc = ToolCall::fromArray([
            'id' => null,
            'name' => null,
            'arguments' => null,
        ]);

        self::assertSame('', $tc->id);
        self::assertSame('', $tc->name);
        self::assertSame([], $tc->arguments);
    }

    #[Test]
    public function fromArrayPreservesNestedArguments(): void
    {
        $nested = [
            'filters' => ['status' => 'active', 'tags' => ['php', 'ai']],
            'sort' => 'created_at',
        ];

        $tc = ToolCall::fromArray([
            'id' => 'tc_nested',
            'name' => 'complex_query',
            'arguments' => $nested,
        ]);

        self::assertSame($nested, $tc->arguments);
    }
}
