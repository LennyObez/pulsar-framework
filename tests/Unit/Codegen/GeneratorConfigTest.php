<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Codegen;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Codegen\GeneratorConfig;

#[CoversClass(GeneratorConfig::class)]
final class GeneratorConfigTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $config = new GeneratorConfig(
            outputBaseDirectory: '/project/src',
            namespacePrefix: 'App\\Models',
            force: true,
        );

        self::assertSame('/project/src', $config->outputBaseDirectory);
        self::assertSame('App\\Models', $config->namespacePrefix);
        self::assertTrue($config->force);
    }

    #[Test]
    public function defaultValues(): void
    {
        $config = new GeneratorConfig(outputBaseDirectory: '/project/src');

        self::assertSame('App', $config->namespacePrefix);
        self::assertFalse($config->force);
    }
}
