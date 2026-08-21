<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Support;

use Override;
use ReflectionClass;
use ReflectionMethod;

use function sprintf;

/**
 * The rule behind OverrideCorrectnessTest, pointed at a tree rather than at `src`.
 *
 * Writing the negative test for this rule established that its defect cannot be planted
 * at all, which is worth more than a fixture would have been. PHP refuses to compile
 * `#[\Override]` on a method that overrides nothing:
 *
 *     Fatal error: C::m() has #[\Override] attribute, but no matching parent method exists
 *
 * It is a compile-time fatal, raised through `eval()` as well as through `require`, so no
 * file, no fixture and no runtime construction can produce a class in the state this rule
 * looks for. The engine is the gate. `staleOverrides()` over any tree PHP was able to
 * load is the empty list by construction.
 *
 * What remains testable, and is tested, is the predicate: whether a given method actually
 * overrides something. That is the part the rule computes for itself rather than
 * inheriting from the engine, so it is the part that can be wrong — a predicate that
 * answered "yes" to everything would make this rule silent even in a PHP that permitted
 * the annotation.
 */
final readonly class OverrideScanner
{
    public function __construct(private ReflectedClassIndex $index) {}

    /**
     * Methods declaring #[\Override] that override nothing.
     *
     * @return list<string>
     */
    public function staleOverrides(): array
    {
        $failures = [];

        foreach ($this->overrideMethods() as $entry) {
            if (!self::overridesSomething($entry['class'], $entry['method'])) {
                $failures[] = sprintf('%s::%s()', $entry['class'], $entry['method']);
            }
        }

        return $failures;
    }

    /**
     * @return list<array{class: class-string, method: string}>
     */
    public function overrideMethods(): array
    {
        $methods = [];

        foreach ($this->index->classes() as $class) {
            if ($class->isAbstract() || $class->isInterface()) {
                continue;
            }

            foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class->getName()) {
                    continue;
                }

                if ($method->getAttributes(Override::class) !== []) {
                    /** @var class-string $name */
                    $name = $class->getName();
                    $methods[] = ['class' => $name, 'method' => $method->getName()];
                }
            }
        }

        return $methods;
    }

    /**
     * Whether a parent class or an implemented interface declares this method.
     *
     * @param class-string $class
     */
    public static function overridesSomething(string $class, string $method): bool
    {
        $reflection = new ReflectionClass($class);

        $parent = $reflection->getParentClass();

        if ($parent !== false && $parent->hasMethod($method)) {
            return true;
        }

        foreach ($reflection->getInterfaces() as $interface) {
            if ($interface->hasMethod($method)) {
                return true;
            }
        }

        return false;
    }
}
