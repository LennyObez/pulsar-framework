<?php

declare(strict_types=1);

namespace Pulsar\Runtime\Scope;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Runtime\Exception\StatefulSingletonException;
use ReflectionClass;
use ReflectionProperty;

use function sprintf;
use function str_starts_with;

#[Internal]
final readonly class StatefulSingletonAnalyzer
{
    /** @param list<string> $coreNamespaces Namespace prefixes treated as core (hard errors in strict mode) */
    public function __construct(
        private bool $strict = false,
        private array $coreNamespaces = ['Pulsar\\'],
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * Analyze a class for stateful singleton violations.
     *
     * @param class-string $className
     * @return list<StatefulSingletonViolation>
     */
    public function analyze(string $className): array
    {
        $violations = [];
        $reflection = new ReflectionClass($className);

        foreach ($reflection->getProperties() as $property) {
            if ($this->isWritableProperty($property, $reflection)) {
                $violations[] = new StatefulSingletonViolation(
                    className: $className,
                    property: $property->getName(),
                    type: ViolationType::WritableProperty,
                    message: sprintf(
                        'Singleton %s has writable property $%s — may cause cross-request state leakage',
                        $className,
                        $property->getName(),
                    ),
                );
            }
        }

        foreach ($reflection->getProperties(ReflectionProperty::IS_STATIC) as $property) {
            if (!$property->isReadOnly()) {
                $violations[] = new StatefulSingletonViolation(
                    className: $className,
                    property: $property->getName(),
                    type: ViolationType::MutableStatic,
                    message: sprintf(
                        'Singleton %s has mutable static property $%s — will leak state across requests',
                        $className,
                        $property->getName(),
                    ),
                );
            }
        }

        if ($reflection->hasMethod('reset') || $reflection->hasMethod('resetRequestState')) {
            $methodName = $reflection->hasMethod('reset') ? 'reset' : 'resetRequestState';
            $violations[] = new StatefulSingletonViolation(
                className: $className,
                property: $methodName . '()',
                type: ViolationType::ResetMethod,
                message: sprintf(
                    'Singleton %s has %s() method — indicates mutable state that needs manual reset',
                    $className,
                    $methodName,
                ),
            );
        }

        return $violations;
    }

    /**
     * Check if a property is writable (not readonly, not static).
     *
     * @param ReflectionClass<object> $class
     */
    private function isWritableProperty(ReflectionProperty $property, ReflectionClass $class): bool
    {
        return !$property->isStatic() && !$property->isReadOnly() && !$class->isReadOnly();
    }

    /**
     * Check if a class is in a core namespace.
     */
    public function isCoreNamespace(string $className): bool
    {
        return array_any($this->coreNamespaces, static fn(string $prefix): bool => str_starts_with($className, $prefix));
    }

    /**
     * Report violations — throws in strict mode for core namespaces, logs warnings otherwise.
     *
     * @param list<StatefulSingletonViolation> $violations
     * @throws StatefulSingletonException In strict mode for core namespace violations
     */
    public function report(array $violations): void
    {
        if ($violations === []) {
            return;
        }

        $coreViolations = [];

        foreach ($violations as $violation) {
            $this->logger?->warning($violation->message);

            if ($this->strict && $this->isCoreNamespace($violation->className)) {
                $coreViolations[] = $violation;
            }
        }

        if ($coreViolations !== []) {
            throw StatefulSingletonException::detected($coreViolations);
        }
    }
}
