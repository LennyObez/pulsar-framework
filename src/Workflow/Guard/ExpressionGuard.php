<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Guard;

use Pulsar\Api\Api;
use Pulsar\Workflow\ActorContext;
use Pulsar\Workflow\Definition\TransitionDefinition;
use Pulsar\Workflow\Storage\WorkflowInstance;

use function sprintf;

/**
 * Guard that evaluates a simple condition expression against the workflow context.
 *
 * The expression is read from the transition metadata under the 'guard_expression'
 * key. Supported expressions check for the existence and value of context fields:
 *
 * - `context.has:<field>` — checks that the field exists in the instance context
 * - `context.eq:<field>:<value>` — checks that the field equals a string value
 * - `context.neq:<field>:<value>` — checks that the field does not equal a string value
 *
 * This guard is intentionally limited to deterministic, side-effect-free evaluations.
 * For complex logic, implement a custom {@see TransitionGuardInterface}.
 */
#[Api(since: '1.0.0')]
final readonly class ExpressionGuard implements TransitionGuardInterface
{
    private const string EXPRESSION_KEY = 'guard_expression';

    public function evaluate(
        ActorContext $actor,
        TransitionDefinition $transition,
        WorkflowInstance $instance,
    ): GuardResult {
        /** @var string|null $expression */
        $expression = $transition->metadata[self::EXPRESSION_KEY] ?? null;

        if ($expression === null || $expression === '') {
            return GuardResult::allow();
        }

        return $this->evaluateExpression($expression, $instance);
    }

    private function evaluateExpression(string $expression, WorkflowInstance $instance): GuardResult
    {
        $parts = explode(':', $expression, 3);
        $operation = $parts[0] ?? '';
        $operator = $parts[1] ?? '';

        if ($operation !== 'context') {
            return GuardResult::deny(sprintf('Unknown expression domain: "%s"', $operation));
        }

        return match ($operator) {
            'has' => $this->evaluateHas($parts[2] ?? '', $instance),
            'eq' => $this->evaluateEq($parts[2] ?? '', $instance, true),
            'neq' => $this->evaluateEq($parts[2] ?? '', $instance, false),
            default => GuardResult::deny(sprintf('Unknown expression operator: "%s"', $operator)),
        };
    }

    private function evaluateHas(string $field, WorkflowInstance $instance): GuardResult
    {
        if ($field === '') {
            return GuardResult::deny('Expression "context.has" requires a field name');
        }

        if ($instance->context->has($field)) {
            return GuardResult::allow();
        }

        return GuardResult::deny(sprintf('Context field "%s" does not exist', $field));
    }

    private function evaluateEq(string $operands, WorkflowInstance $instance, bool $equality): GuardResult
    {
        $parts = explode(':', $operands, 2);
        $field = $parts[0] ?? '';
        $expected = $parts[1] ?? '';

        if ($field === '') {
            $op = $equality ? 'eq' : 'neq';

            return GuardResult::deny(sprintf('Expression "context.%s" requires a field name and value', $op));
        }

        if (!$instance->context->has($field)) {
            return GuardResult::deny(sprintf('Context field "%s" does not exist', $field));
        }

        /** @var scalar $rawValue */
        $rawValue = $instance->context->get($field);
        $actual = (string) $rawValue;
        $matches = $actual === $expected;

        if ($equality && $matches) {
            return GuardResult::allow();
        }

        if (!$equality && !$matches) {
            return GuardResult::allow();
        }

        $op = $equality ? 'equal' : 'not equal';

        return GuardResult::deny(sprintf(
            'Context field "%s" expected to be %s to "%s", got "%s"',
            $field,
            $op,
            $expected,
            $actual,
        ));
    }
}
