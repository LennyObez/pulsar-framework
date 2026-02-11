<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Container\Compiled;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Compiled\ContainerCompiler;
use Pulsar\Container\Exception\ContainerException;
use Pulsar\Container\Lifetime;
use Pulsar\Container\ServiceDefinition;
use stdClass;

#[CoversClass(ContainerCompiler::class)]
final class ContainerCompilerTest extends TestCase
{
    #[Test]
    public function compileEmptyDefinitions(): void
    {
        $code = ContainerCompiler::compile([]);

        self::assertStringContainsString('class CompiledContainerGenerated extends CompiledContainer', $code);
        self::assertStringContainsString('declare(strict_types=1)', $code);
    }

    #[Test]
    public function compileClassBasedDefinition(): void
    {
        $definitions = [
            stdClass::class => new ServiceDefinition(
                id: stdClass::class,
                concrete: stdClass::class,
                lifetime: Lifetime::Singleton,
            ),
        ];

        $code = ContainerCompiler::compile($definitions);

        self::assertStringContainsString('stdClass', $code);
        self::assertStringContainsString('Lifetime::Singleton', $code);
    }

    #[Test]
    public function compileCallableDefinitionGeneratesUnsupportedFactory(): void
    {
        $definitions = [
            'my.service' => new ServiceDefinition(
                id: 'my.service',
                concrete: fn() => new stdClass(),
                lifetime: Lifetime::Singleton,
            ),
        ];

        $code = ContainerCompiler::compile($definitions);

        self::assertStringContainsString('callable factory', $code);
        self::assertStringContainsString('ContainerException', $code);
    }

    #[Test]
    public function compileTransientLifetime(): void
    {
        $definitions = [
            stdClass::class => new ServiceDefinition(
                id: stdClass::class,
                concrete: stdClass::class,
                lifetime: Lifetime::Transient,
            ),
        ];

        $code = ContainerCompiler::compile($definitions);

        self::assertStringContainsString('Lifetime::Transient', $code);
    }

    #[Test]
    public function compileRequestScopeLifetime(): void
    {
        $definitions = [
            stdClass::class => new ServiceDefinition(
                id: stdClass::class,
                concrete: stdClass::class,
                lifetime: Lifetime::RequestScope,
            ),
        ];

        $code = ContainerCompiler::compile($definitions);

        self::assertStringContainsString('Lifetime::RequestScope', $code);
    }

    #[Test]
    public function compileTenantScopeLifetime(): void
    {
        $definitions = [
            stdClass::class => new ServiceDefinition(
                id: stdClass::class,
                concrete: stdClass::class,
                lifetime: Lifetime::TenantScope,
            ),
        ];

        $code = ContainerCompiler::compile($definitions);

        self::assertStringContainsString('Lifetime::TenantScope', $code);
    }

    #[Test]
    public function compileWithCustomClassNameAndNamespace(): void
    {
        $code = ContainerCompiler::compile([], 'MyContainer', 'App\\Container');

        self::assertStringContainsString('namespace App\\Container', $code);
        self::assertStringContainsString('class MyContainer extends CompiledContainer', $code);
    }

    #[Test]
    public function compileRejectsInvalidClassName(): void
    {
        /** @var class-string $invalidClass */
        $invalidClass = trim('invalid class name with spaces!');
        $definitions = [
            'bad.service' => new ServiceDefinition(
                id: 'bad.service',
                concrete: $invalidClass,
                lifetime: Lifetime::Singleton,
            ),
        ];

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Invalid class name');

        ContainerCompiler::compile($definitions);
    }

    #[Test]
    public function compileWithDependencies(): void
    {
        $definitions = [
            DependencyA::class => new ServiceDefinition(
                id: DependencyA::class,
                concrete: DependencyA::class,
            ),
            ConsumerA::class => new ServiceDefinition(
                id: ConsumerA::class,
                concrete: ConsumerA::class,
            ),
        ];

        $code = ContainerCompiler::compile($definitions);

        self::assertStringContainsString('DEPS', $code);
    }

    #[Test]
    public function compileSortsByServiceId(): void
    {
        $definitions = [
            'z.service' => new ServiceDefinition(id: 'z.service', concrete: stdClass::class),
            'a.service' => new ServiceDefinition(id: 'a.service', concrete: stdClass::class),
        ];

        $code = ContainerCompiler::compile($definitions);

        $posA = strpos($code, "'a.service'");
        $posZ = strpos($code, "'z.service'");

        self::assertNotFalse($posA);
        self::assertNotFalse($posZ);
        self::assertLessThan($posZ, $posA);
    }
}

class DependencyA {}

class ConsumerA
{
    public function __construct(
        public readonly DependencyA $dep,
    ) {}
}
