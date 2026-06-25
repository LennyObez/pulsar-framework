<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Container\Compiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Compiler\CompilerPassInterface;
use Pulsar\Container\Compiler\CompilerPassProcessor;
use Pulsar\Container\Compiler\ContainerBuilder;
use Pulsar\Container\Compiler\PassRunner;
use Pulsar\Container\Lifetime;
use Pulsar\Container\Scope\ScopeWideningException;
use Pulsar\Container\ServiceDefinition;
use stdClass;

/**
 * Direct unit tests for the extracted {@see CompilerPassProcessor}. They verify
 * the definition-map ⇄ builder round-trip (projection in, passes run,
 * projection out) and that scope validation actually drives
 * {@see \Pulsar\Container\Compiler\Pass\ValidateLifetimesPass}.
 */
#[CoversClass(CompilerPassProcessor::class)]
final class CompilerPassProcessorTest extends TestCase
{
    #[Test]
    public function process_projects_definitions_runs_passes_and_returns_transformed_map(): void
    {
        $definitions = [
            'a' => new ServiceDefinition(id: 'a', concrete: stdClass::class),
        ];

        $pass = new class implements CompilerPassInterface {
            public bool $sawProjectedDefinition = false;

            public function process(ContainerBuilder $builder): void
            {
                // The input definition must have been projected onto the builder.
                $this->sawProjectedDefinition = $builder->hasDefinition('a');

                // Mutate the graph: add one, drop the original.
                $builder->setDefinition('b', new ServiceDefinition(id: 'b', concrete: stdClass::class));
                $builder->removeDefinition('a');
            }
        };

        $runner = new PassRunner();
        $runner->addPass($pass);

        $result = CompilerPassProcessor::process($definitions, $runner);

        self::assertTrue($pass->sawProjectedDefinition, 'input definitions must be projected onto the builder');
        self::assertArrayHasKey('b', $result, 'definitions added by a pass must survive the round-trip');
        self::assertArrayNotHasKey('a', $result, 'definitions removed by a pass must not survive');
        self::assertSame('b', $result['b']->id);
    }

    #[Test]
    public function process_with_no_passes_preserves_the_definition_map(): void
    {
        $definition = new ServiceDefinition(id: 'svc', concrete: stdClass::class);

        $result = CompilerPassProcessor::process(['svc' => $definition], new PassRunner());

        self::assertArrayHasKey('svc', $result);
        self::assertSame($definition, $result['svc']);
    }

    #[Test]
    public function validate_scope_graph_accepts_a_consistent_graph(): void
    {
        $definitions = [
            InvokerProcSingletonDep::class => new ServiceDefinition(
                id: InvokerProcSingletonDep::class,
                concrete: InvokerProcSingletonDep::class,
                lifetime: Lifetime::Singleton,
            ),
            InvokerProcSingletonConsumer::class => new ServiceDefinition(
                id: InvokerProcSingletonConsumer::class,
                concrete: InvokerProcSingletonConsumer::class,
                lifetime: Lifetime::Singleton,
            ),
        ];

        CompilerPassProcessor::validateScopeGraph($definitions);

        // No ScopeWideningException: a singleton depending on a singleton is valid.
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function validate_scope_graph_rejects_scope_widening(): void
    {
        $definitions = [
            InvokerProcRequestDep::class => new ServiceDefinition(
                id: InvokerProcRequestDep::class,
                concrete: InvokerProcRequestDep::class,
                lifetime: Lifetime::RequestScope,
            ),
            InvokerProcSingletonWithRequestDep::class => new ServiceDefinition(
                id: InvokerProcSingletonWithRequestDep::class,
                concrete: InvokerProcSingletonWithRequestDep::class,
                lifetime: Lifetime::Singleton,
            ),
        ];

        $this->expectException(ScopeWideningException::class);

        CompilerPassProcessor::validateScopeGraph($definitions);
    }
}

final class InvokerProcSingletonDep {}

final class InvokerProcRequestDep {}

final class InvokerProcSingletonConsumer
{
    public function __construct(public InvokerProcSingletonDep $dep) {}
}

final class InvokerProcSingletonWithRequestDep
{
    public function __construct(public InvokerProcRequestDep $dep) {}
}
