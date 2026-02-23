<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Codegen;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Codegen\GeneratedFileSet;
use Pulsar\Codegen\GeneratorConfig;
use Pulsar\Codegen\GeneratorInterface;
use Pulsar\Codegen\Schema\EntityDefinition;

#[CoversClass(GeneratorInterface::class)]
final class GeneratorInterfaceTest extends TestCase
{
    #[Test]
    public function stubImplementsInterface(): void
    {
        $stub = $this->createStub(GeneratorInterface::class);
        $stub->method('generate')->willReturn(new GeneratedFileSet());

        $entity = new EntityDefinition(
            className: 'Test',
            namespace: 'App',
            tableName: 'tests',
            properties: [],
            relationships: [],
            primaryKey: 'id',
            hasTimestamps: false,
            hasSoftDeletes: false,
            isAuditAware: false,
        );

        $result = $stub->generate($entity, new GeneratorConfig('/project'));

        self::assertInstanceOf(GeneratedFileSet::class, $result);
    }
}
