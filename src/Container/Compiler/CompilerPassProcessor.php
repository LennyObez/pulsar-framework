<?php

declare(strict_types=1);

namespace Pulsar\Container\Compiler;

use Pulsar\Api\Internal;
use Pulsar\Container\Compiler\Pass\ValidateLifetimesPass;
use Pulsar\Container\ServiceDefinition;

/**
 * Runs compiler passes over a container's live definition map.
 *
 * Bridges the runtime definition map and the build-time pass machinery:
 * projects definitions onto a {@see ContainerBuilder}, drives the passes via
 * {@see PassRunner} (or a single validation pass), and returns the result.
 * Extracted from the container so its per-resolution hot path is not
 * interleaved with this boot-time graph machinery.
 *
 * Distinct from {@see \Pulsar\Container\Compiled\ContainerCompiler}, which
 * generates ahead-of-time PHP code from definitions.
 */
#[Internal]
final class CompilerPassProcessor
{
    /**
     * Run the given pass pipeline over the definitions and return the
     * processed definition map.
     *
     * @param array<string, ServiceDefinition> $definitions
     *
     * @return array<string, ServiceDefinition>
     */
    public static function process(array $definitions, PassRunner $runner): array
    {
        $builder = self::project($definitions);

        $runner->run($builder);

        $processed = [];
        foreach ($builder->allDefinitions() as $id => $definition) {
            $processed[$id] = $definition;
        }

        return $processed;
    }

    /**
     * Validate the scope/lifetime graph, throwing if a longer-lived service
     * depends on a shorter-lived one.
     *
     * @param array<string, ServiceDefinition> $definitions
     */
    public static function validateScopeGraph(array $definitions): void
    {
        $pass = new ValidateLifetimesPass();
        $pass->process(self::project($definitions));
    }

    /**
     * Project a definition map onto a fresh {@see ContainerBuilder}.
     *
     * @param array<string, ServiceDefinition> $definitions
     */
    private static function project(array $definitions): ContainerBuilder
    {
        $builder = new ContainerBuilder();

        foreach ($definitions as $id => $definition) {
            $builder->setDefinition($id, $definition);
        }

        return $builder;
    }
}
