<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\Make\MethodSignature;

#[CoversClass(MethodSignature::class)]
final class MethodSignatureTest extends TestCase
{
    #[Test]
    public function constructSetsProperties(): void
    {
        $params = [
            ['name' => '$userId', 'type' => 'string', 'default' => null],
            ['name' => '$limit', 'type' => 'int', 'default' => '100'],
        ];

        $sig = new MethodSignature(
            name: 'findByUserId',
            parameters: $params,
            returnType: 'array',
        );

        self::assertSame('findByUserId', $sig->name);
        self::assertCount(2, $sig->parameters);
        self::assertSame('array', $sig->returnType);
        self::assertSame('$userId', $sig->parameters[0]['name']);
        self::assertSame('100', $sig->parameters[1]['default']);
    }

    #[Test]
    public function emptyParametersAndReturnType(): void
    {
        $sig = new MethodSignature(
            name: 'execute',
            parameters: [],
            returnType: '',
        );

        self::assertSame('execute', $sig->name);
        self::assertSame([], $sig->parameters);
        self::assertSame('', $sig->returnType);
    }
}
