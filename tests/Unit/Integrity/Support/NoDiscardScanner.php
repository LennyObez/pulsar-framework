<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Support;

use NoDiscard;
use Pulsar\Support\ReflectionTypeName;
use ReflectionMethod;
use ReflectionNamedType;

use function sprintf;
use function str_starts_with;

/**
 * The rules behind NoDiscardCorrectnessTest, pointed at a tree rather than at `src`.
 *
 * One of the two rules here can no longer be violated, and saying so is more useful than
 * pretending otherwise. PHP 8.5 refuses to compile `#[\NoDiscard]` on a void method —
 * "A void method does not return a value, but #[\NoDiscard] requires a return value", a
 * fatal error, uncatchable, raised before any test can run. The engine is the gate;
 * `voidMethodsCarryingNoDiscard()` can only ever return the empty list, and
 * NoDiscardRefusesTest records that with the probe rather than faking a fixture.
 *
 * The other rule is real and violable: a `with*` method on a readonly class that returns
 * a new instance and does not say the result must be used. Discarding it is a silent
 * no-op — the caller believes it mutated something and nothing changed — which is the
 * single most common way an immutable API is misused.
 */
final readonly class NoDiscardScanner
{
    public function __construct(private ReflectedClassIndex $index) {}

    /**
     * Methods declaring #[NoDiscard] over a void return.
     *
     * @return list<string>
     */
    public function voidMethodsCarryingNoDiscard(): array
    {
        $failures = [];

        foreach ($this->noDiscardMethods() as $entry) {
            if ($entry['returnType'] === 'void') {
                $failures[] = sprintf('%s::%s()', $entry['class'], $entry['method']);
            }
        }

        return $failures;
    }

    /**
     * Immutable modifiers whose result can be dropped without a word from the engine.
     *
     * @return list<string>
     */
    public function withMethodsMissingNoDiscard(): array
    {
        $missing = [];

        foreach ($this->index->classes() as $class) {
            if ($class->isInterface() || $class->isAbstract() || !$class->isReadOnly()) {
                continue;
            }

            foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class->getName()) {
                    continue;
                }

                if (!str_starts_with($method->getName(), 'with')) {
                    continue;
                }

                $returnType = $method->getReturnType();

                // Only immutable modifiers: something that hands back an instance of the
                // same shape. Anything else is a `with*` in name only.
                if (!$returnType instanceof ReflectionNamedType) {
                    continue;
                }

                $typeName = $returnType->getName();

                if ($typeName !== 'self' && $typeName !== 'static' && $typeName !== $class->getName()) {
                    continue;
                }

                if ($method->getAttributes(NoDiscard::class) === []) {
                    $missing[] = sprintf('%s::%s()', $class->getName(), $method->getName());
                }
            }
        }

        return $missing;
    }

    /**
     * @return list<array{class: string, method: string, returnType: string}>
     */
    public function noDiscardMethods(): array
    {
        $methods = [];

        foreach ($this->index->classes() as $class) {
            if ($class->isInterface()) {
                continue;
            }

            foreach ($class->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $class->getName()) {
                    continue;
                }

                if ($method->getAttributes(NoDiscard::class) === []) {
                    continue;
                }

                $returnType = $method->getReturnType();
                $typeName = $returnType instanceof ReflectionNamedType
                    ? $returnType->getName()
                    : ($returnType !== null ? ReflectionTypeName::of($returnType) : 'mixed');

                $methods[] = [
                    'class' => $class->getName(),
                    'method' => $method->getName(),
                    'returnType' => $typeName,
                ];
            }
        }

        return $methods;
    }
}
