<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Workflow\Definition\Versioning;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Workflow\Definition\DefinitionBuilder;
use Pulsar\Workflow\Definition\Versioning\DefinitionVersion;
use Pulsar\Workflow\Definition\WorkflowDefinition;

#[CoversClass(DefinitionVersion::class)]
final class DefinitionVersionTest extends TestCase
{
    #[Test]
    public function test_constructor_stores_all_fields(): void
    {
        $definition = $this->createDefinition();
        $createdAt = new DateTimeImmutable('2026-01-15 10:00:00');

        $version = new DefinitionVersion(
            definitionId: 'order_process',
            version: 3,
            definition: $definition,
            createdAt: $createdAt,
        );

        self::assertSame('order_process', $version->definitionId);
        self::assertSame(3, $version->version);
        self::assertSame($definition, $version->definition);
        self::assertSame($createdAt, $version->createdAt);
    }

    #[Test]
    public function test_version_number_is_integer(): void
    {
        $version = new DefinitionVersion(
            definitionId: 'test',
            version: 1,
            definition: $this->createDefinition(),
            createdAt: new DateTimeImmutable(),
        );

        self::assertSame(1, $version->version);
    }

    #[Test]
    public function test_definition_is_accessible(): void
    {
        $definition = $this->createDefinition();

        $version = new DefinitionVersion(
            definitionId: 'test',
            version: 1,
            definition: $definition,
            createdAt: new DateTimeImmutable(),
        );

        self::assertSame('simple', $version->definition->name);
    }

    private function createDefinition(): WorkflowDefinition
    {
        return DefinitionBuilder::create('simple')
            ->initialState('start')
            ->finalState('end')
            ->transition('go', 'start', 'end')
            ->build();
    }
}
