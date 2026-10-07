<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\ToolDefinition;

#[CoversClass(ToolDefinition::class)]
final class ToolDefinitionTest extends TestCase
{
    #[Test]
    public function constructorStoresAllProperties(): void
    {
        $params = [
            'type' => 'object',
            'properties' => [
                'city' => ['type' => 'string'],
            ],
            'required' => ['city'],
        ];

        $tool = new ToolDefinition(
            name: 'get_weather',
            description: 'Get current weather for a city',
            parameters: $params,
        );

        self::assertSame('get_weather', $tool->name);
        self::assertSame('Get current weather for a city', $tool->description);
        self::assertSame($params, $tool->parameters);
    }

    #[Test]
    public function emptyParametersAreValid(): void
    {
        $tool = new ToolDefinition(
            name: 'noop',
            description: 'Does nothing',
            parameters: [],
        );

        self::assertSame([], $tool->parameters);
    }
}
