<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\Make\InterfaceParser;
use Pulsar\Console\Command\Make\MethodSignature;

#[CoversClass(InterfaceParser::class)]
#[CoversClass(MethodSignature::class)]
final class InterfaceParserTest extends TestCase
{
    private InterfaceParser $parser;

    protected function setUp(): void
    {
        $this->parser = new InterfaceParser();
    }

    #[Test]
    public function it_parses_empty_interface(): void
    {
        $source = <<<'PHP'
            <?php
            interface EmptyInterface
            {
            }
            PHP;

        $methods = $this->parser->parse($source);

        self::assertSame([], $methods);
    }

    #[Test]
    public function it_parses_method_without_parameters(): void
    {
        $source = <<<'PHP'
            <?php
            interface FooInterface
            {
                public function doSomething(): void;
            }
            PHP;

        $methods = $this->parser->parse($source);

        self::assertCount(1, $methods);
        self::assertSame('doSomething', $methods[0]->name);
        self::assertSame([], $methods[0]->parameters);
        self::assertSame('void', $methods[0]->returnType);
    }

    #[Test]
    public function it_parses_typed_parameters(): void
    {
        $source = <<<'PHP'
            <?php
            interface FooInterface
            {
                public function process(string $name, int $age): bool;
            }
            PHP;

        $methods = $this->parser->parse($source);

        self::assertCount(1, $methods);
        self::assertSame('process', $methods[0]->name);
        self::assertCount(2, $methods[0]->parameters);
        self::assertSame('string', $methods[0]->parameters[0]['type']);
        self::assertSame('$name', $methods[0]->parameters[0]['name']);
        self::assertSame('int', $methods[0]->parameters[1]['type']);
        self::assertSame('$age', $methods[0]->parameters[1]['name']);
        self::assertSame('bool', $methods[0]->returnType);
    }

    #[Test]
    public function it_parses_nullable_types(): void
    {
        $source = <<<'PHP'
            <?php
            interface FooInterface
            {
                public function find(?string $id): ?object;
            }
            PHP;

        $methods = $this->parser->parse($source);

        self::assertCount(1, $methods);
        self::assertSame('?string', $methods[0]->parameters[0]['type']);
        self::assertSame('?object', $methods[0]->returnType);
    }

    #[Test]
    public function it_parses_union_types(): void
    {
        $source = <<<'PHP'
            <?php
            interface FooInterface
            {
                public function transform(string|int $value): string|bool;
            }
            PHP;

        $methods = $this->parser->parse($source);

        self::assertCount(1, $methods);
        self::assertSame('string|int', $methods[0]->parameters[0]['type']);
        self::assertSame('string|bool', $methods[0]->returnType);
    }

    #[Test]
    public function it_parses_default_values(): void
    {
        $source = <<<'PHP'
            <?php
            interface FooInterface
            {
                public function configure(string $name, int $timeout = 30): void;
            }
            PHP;

        $methods = $this->parser->parse($source);

        self::assertCount(1, $methods);
        self::assertCount(2, $methods[0]->parameters);
        self::assertNull($methods[0]->parameters[0]['default']);
        self::assertSame('30', $methods[0]->parameters[1]['default']);
    }

    #[Test]
    public function it_parses_multiple_methods(): void
    {
        $source = <<<'PHP'
            <?php
            interface FooInterface
            {
                public function create(string $name): void;
                public function delete(string $id): bool;
                public function getAll(): array;
            }
            PHP;

        $methods = $this->parser->parse($source);

        self::assertCount(3, $methods);
        self::assertSame('create', $methods[0]->name);
        self::assertSame('delete', $methods[1]->name);
        self::assertSame('getAll', $methods[2]->name);
    }

    #[Test]
    public function it_parses_method_with_no_return_type(): void
    {
        $source = <<<'PHP'
            <?php
            interface FooInterface
            {
                public function fire();
            }
            PHP;

        $methods = $this->parser->parse($source);

        self::assertCount(1, $methods);
        self::assertSame('', $methods[0]->returnType);
    }
}
