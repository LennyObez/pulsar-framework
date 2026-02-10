<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\BlockEditor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeRegistry;

#[CoversClass(BlockTypeRegistry::class)]
final class BlockTypeRegistryTest extends TestCase
{
    private BlockTypeRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new BlockTypeRegistry();
    }

    #[Test]
    public function registerAndRetrieveBlockType(): void
    {
        $block = $this->createBlockType('paragraph');
        $this->registry->register($block);

        self::assertSame($block, $this->registry->get('paragraph'));
    }

    #[Test]
    public function getReturnsNullForUnknownType(): void
    {
        self::assertNull($this->registry->get('nonexistent'));
    }

    #[Test]
    public function hasReturnsTrueForRegisteredType(): void
    {
        $block = $this->createBlockType('heading');
        $this->registry->register($block);

        self::assertTrue($this->registry->has('heading'));
    }

    #[Test]
    public function hasReturnsFalseForUnregisteredType(): void
    {
        self::assertFalse($this->registry->has('unknown'));
    }

    #[Test]
    public function allReturnsAllRegisteredTypes(): void
    {
        $paragraph = $this->createBlockType('paragraph');
        $heading = $this->createBlockType('heading');

        $this->registry->register($paragraph);
        $this->registry->register($heading);

        $all = $this->registry->all();

        self::assertCount(2, $all);
        self::assertSame($paragraph, $all['paragraph']);
        self::assertSame($heading, $all['heading']);
    }

    #[Test]
    public function allReturnsEmptyArrayWhenEmpty(): void
    {
        self::assertSame([], $this->registry->all());
    }

    #[Test]
    public function registerOverwritesExistingType(): void
    {
        $first = $this->createBlockType('paragraph');
        $second = $this->createBlockType('paragraph');

        $this->registry->register($first);
        $this->registry->register($second);

        self::assertSame($second, $this->registry->get('paragraph'));
        self::assertCount(1, $this->registry->all());
    }

    private function createBlockType(string $type): BlockTypeInterface
    {
        $stub = $this->createStub(BlockTypeInterface::class);
        $stub->method('type')->willReturn($type);

        return $stub;
    }
}
