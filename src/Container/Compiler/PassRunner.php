<?php

declare(strict_types=1);

namespace Pulsar\Container\Compiler;

use Pulsar\Api\Internal;

use function usort;

/**
 * Executes compiler passes in deterministic order.
 *
 * Passes are sorted by (priority DESC, FQCN ASC) to ensure identical
 * execution order regardless of registration order.
 */
#[Internal]
final class PassRunner
{
    /** @var list<array{pass: CompilerPassInterface, priority: int}> */
    private array $passes = [];

    /**
     * Register a compiler pass with an optional priority.
     *
     * @param int $priority Higher = runs earlier (default 0)
     */
    public function addPass(CompilerPassInterface $pass, int $priority = 0): void
    {
        $this->passes[] = ['pass' => $pass, 'priority' => $priority];
    }

    /**
     * Run all registered passes against the builder.
     *
     * Passes execute in deterministic order: (priority DESC, FQCN ASC).
     */
    public function run(ContainerBuilder $builder): void
    {
        $passes = $this->passes;

        usort($passes, static function (array $a, array $b): int {
            $priorityDiff = $b['priority'] <=> $a['priority'];

            return $priorityDiff !== 0 ? $priorityDiff : $a['pass']::class <=> $b['pass']::class;
        });

        foreach ($passes as $entry) {
            $entry['pass']->process($builder);
        }
    }
}
