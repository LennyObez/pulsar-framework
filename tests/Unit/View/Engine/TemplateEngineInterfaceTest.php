<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Api;
use Pulsar\View\Engine\CompiledTemplate;
use Pulsar\View\Engine\TemplateEngineInterface;
use ReflectionClass;
use ReflectionNamedType;

#[CoversClass(TemplateEngineInterface::class)]
final class TemplateEngineInterfaceTest extends TestCase
{
    #[Test]
    public function interfaceHasApiAttribute(): void
    {
        $reflection = new ReflectionClass(TemplateEngineInterface::class);
        $attributes = $reflection->getAttributes(Api::class);

        self::assertCount(1, $attributes);
        self::assertSame('1.0.0', $attributes[0]->newInstance()->since);
    }

    #[Test]
    public function interfaceDeclaresRenderMethod(): void
    {
        $reflection = new ReflectionClass(TemplateEngineInterface::class);

        self::assertTrue($reflection->hasMethod('render'));

        $method = $reflection->getMethod('render');
        $params = $method->getParameters();

        self::assertCount(2, $params);
        self::assertSame('template', $params[0]->getName());
        self::assertSame('data', $params[1]->getName());
        self::assertTrue($params[1]->isDefaultValueAvailable());
        self::assertSame([], $params[1]->getDefaultValue());
        $returnType = $method->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame('string', $returnType->getName());
    }

    #[Test]
    public function interfaceDeclaresCompileMethod(): void
    {
        $reflection = new ReflectionClass(TemplateEngineInterface::class);

        self::assertTrue($reflection->hasMethod('compile'));

        $method = $reflection->getMethod('compile');
        $params = $method->getParameters();

        self::assertCount(1, $params);
        self::assertSame('template', $params[0]->getName());
        $returnType = $method->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame(CompiledTemplate::class, $returnType->getName());
    }

    #[Test]
    public function interfaceDeclaresExistsMethod(): void
    {
        $reflection = new ReflectionClass(TemplateEngineInterface::class);

        self::assertTrue($reflection->hasMethod('exists'));

        $method = $reflection->getMethod('exists');
        $params = $method->getParameters();

        self::assertCount(1, $params);
        self::assertSame('template', $params[0]->getName());
        $returnType = $method->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame('bool', $returnType->getName());
    }
}
