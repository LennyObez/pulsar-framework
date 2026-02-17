<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\TypeBridge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\TypeBridge\ParameterDefinition;

#[CoversClass(ParameterDefinition::class)]
final class ParameterDefinitionTest extends TestCase
{
    #[Test]
    public function defaultTypeIsString(): void
    {
        $param = new ParameterDefinition('userId');

        self::assertSame('userId', $param->name);
        self::assertSame('string', $param->typeScriptType);
        self::assertFalse($param->optional);
    }

    #[Test]
    public function customTypeAndOptional(): void
    {
        $param = new ParameterDefinition('page', 'number', optional: true);

        self::assertSame('page', $param->name);
        self::assertSame('number', $param->typeScriptType);
        self::assertTrue($param->optional);
    }
}
