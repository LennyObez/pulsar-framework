<?php

declare(strict_types=1);

namespace Pulsar\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function str_contains;

/**
 * PHPStan rule enforcing that saga step handlers do not inject IntegrationEventBusPort.
 *
 * In regulated presets, saga step handlers MUST use OutboxPort for integration
 * events. Direct injection of IntegrationEventBusPort is forbidden to ensure
 * atomic event emission via the transactional outbox pattern.
 *
 * @implements Rule<Class_>
 */
final class ForbidIntegrationEventBusInSagaStepRule implements Rule
{
    private const string FORBIDDEN_TYPE = 'Pulsar\\Saga\\Port\\IntegrationEventBusPort';

    /**
     * Namespace patterns that identify saga step handler classes.
     *
     * @var list<string>
     */
    private const array SAGA_STEP_NAMESPACE_PATTERNS = [
        'Saga\\Step\\',
        'Saga\\Handler\\',
        'Saga\\Action\\',
    ];

    public function getNodeType(): string
    {
        return Class_::class;
    }

    /**
     * @param Class_ $node
     *
     * @return list<\PHPStan\Rules\RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->namespacedName === null) {
            return [];
        }

        $className = $node->namespacedName->toString();

        if (!$this->isSagaStepHandler($className)) {
            return [];
        }

        $errors = [];

        foreach ($node->getMethods() as $method) {
            foreach ($method->getParams() as $param) {
                if ($this->paramHasForbiddenType($param)) {
                    $errors[] = RuleErrorBuilder::message(
                        'Saga step handlers must use OutboxPort for integration events, '
                        . 'not IntegrationEventBusPort directly. '
                        . 'Direct injection of IntegrationEventBusPort is forbidden in saga step handlers '
                        . 'to ensure atomic event emission via the transactional outbox pattern.',
                    )
                        ->identifier('saga.forbiddenInjection')
                        ->line($param->getStartLine())
                        ->build();
                }
            }
        }

        return $errors;
    }

    private function isSagaStepHandler(string $className): bool
    {
        foreach (self::SAGA_STEP_NAMESPACE_PATTERNS as $pattern) {
            if (str_contains($className, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function paramHasForbiddenType(Param $param): bool
    {
        if ($param->type === null) {
            return false;
        }

        return $this->nodeContainsForbiddenType($param->type);
    }

    private function nodeContainsForbiddenType(Node $node): bool
    {
        if ($node instanceof Node\Name) {
            return $node->toString() === self::FORBIDDEN_TYPE;
        }

        if ($node instanceof Node\NullableType) {
            return $this->nodeContainsForbiddenType($node->type);
        }

        if ($node instanceof Node\UnionType || $node instanceof Node\IntersectionType) {
            foreach ($node->types as $type) {
                if ($this->nodeContainsForbiddenType($type)) {
                    return true;
                }
            }
        }

        return false;
    }
}
