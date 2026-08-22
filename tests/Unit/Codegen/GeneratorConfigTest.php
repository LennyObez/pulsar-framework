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
    public function defaultNamespacePrefixIsApp(): void
    {
        $config = new GeneratorConfig(outputBaseDirectory: '/project/src');

        self::assertSame('App', $config->namespacePrefix);
    }

    #[Test]
    public function defaultForceIsFalse(): void
    {
        $config = new GeneratorConfig(outputBaseDirectory: '/project/src');

        self::assertFalse($config->force);
    }

    #[Test]
    public function forceOverridesDefault(): void
    {
        $config = new GeneratorConfig(
            outputBaseDirectory: '/project/src',
            namespacePrefix: 'App\\Models',
            force: true,
        );

        self::assertTrue($config->force);
        self::assertSame('App\\Models', $config->namespacePrefix);
    }
}
