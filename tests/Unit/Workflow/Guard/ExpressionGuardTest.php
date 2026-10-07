<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Workflow\Guard;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Workflow\ActorContext;
use Pulsar\Workflow\Definition\TransitionDefinition;
use Pulsar\Workflow\Guard\ExpressionGuard;
use Pulsar\Workflow\Guard\GuardResult;
use Pulsar\Workflow\Storage\ClassificationLevel;
use Pulsar\Workflow\Storage\ClassifiedContext;
use Pulsar\Workflow\Storage\WorkflowInstance;
use Pulsar\Workflow\Storage\WorkflowInstanceStatus;

/**
 * Comprehensive unit tests for ExpressionGuard covering:
 * - No expression / empty expression -> allow
 * - context.has:<field> — field exists and does not exist
 * - context.eq:<field>:<value> — equality match and mismatch
 * - context.neq:<field>:<value> — inequality match and mismatch
 * - Unknown domain and operator -> deny with informative message
 * - Missing field name -> deny
 * - Missing field in context -> deny
 * - Numeric and boolean values cast to string for comparison
 * - Edge cases: empty value, special characters in value
 */
#[CoversClass(ExpressionGuard::class)]
final class ExpressionGuardTest extends TestCase
{
    private ExpressionGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new ExpressionGuard();
    }

    private function actor(): ActorContext
    {
        return new ActorContext(subjectId: 'user-1');
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function transition(array $metadata = []): TransitionDefinition
    {
        return TransitionDefinition::create(
            name: 'test_transition',
            from: 'draft',
            to: 'review',
            metadata: $metadata,
        );
    }

    private function instance(?ClassifiedContext $context = null): WorkflowInstance
    {
        return new WorkflowInstance(
            id: 'inst-1',
            definitionId: 'order',
            definitionVersion: 1,
            currentState: 'draft',
            context: $context ?? new ClassifiedContext(),
            version: 1,
            status: WorkflowInstanceStatus::Active,
            startedAt: new DateTimeImmutable('2026-01-01 00:00:00'),
            completedAt: null,
            startedBy: 'user-1',
        );
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function contextWith(array $fields): ClassifiedContext
    {
        $ctx = new ClassifiedContext();
        foreach ($fields as $key => $value) {
            $ctx = $ctx->set($key, $value, ClassificationLevel::Public);
        }

        return $ctx;
    }

    // =========================================================================
    // No expression / empty expression
    // =========================================================================

    #[Test]
    public function allows_when_no_guard_expression_in_metadata(): void
    {
        $result = $this->guard->evaluate($this->actor(), $this->transition(), $this->instance());

        self::assertTrue($result->isAllowed());
    }

    #[Test]
    public function allows_when_guard_expression_is_null(): void
    {
        $result = $this->guard->evaluate(
            $this->actor(),
            $this->transition(['guard_expression' => null]),
            $this->instance(),
        );

        self::assertTrue($result->isAllowed());
    }

    #[Test]
    public function allows_when_guard_expression_is_empty_string(): void
    {
        $result = $this->guard->evaluate(
            $this->actor(),
            $this->transition(['guard_expression' => '']),
            $this->instance(),
        );

        self::assertTrue($result->isAllowed());
    }

    // =========================================================================
    // context.has:<field>
    // =========================================================================

    #[Test]
    public function context_has_allows_when_field_exists(): void
    {
        $ctx = $this->contextWith(['amount' => 100]);

        $result = $this->guard->evaluate(
            $this->actor(),
            $this->transition(['guard_expression' => 'context:has:amount']),
            $this->instance($ctx),
        );

        self::assertTrue($result->isAllowed());
    }

    #[Test]
    public function context_has_denies_when_field_missing(): void
    {
        $result = $this->guard->evaluate(
            $this->actor(),
            $this->transition(['guard_expression' => 'context:has:amount']),
            $this->instance(),
        );

        self::assertTrue($result->isDenied());
        self::assertStringContainsString('amount', $result->reason);
        self::assertStringContainsString('does not exist', $result->reason);
    }

    #[Test]
    public function context_has_denies_when_field_name_empty(): void
    {
        $result = $this->guard->evaluate(
            $this->actor(),
            $this->transition(['guard_expression' => 'context:has:']),
            $this->instance(),
        );

        self::assertTrue($result->isDenied());
        self::assertStringContainsString('requires a field name', $result->reason);
    }

    #[Test]
    public function context_has_denies_when_field_name_missing(): void
    {
        $result = $this->guard->evaluate(
            $this->actor(),
            $this->transition(['guard_expression' => 'context:has']),
            $this->instance(),
        );

        self::assertTrue($result->isDenied());
        self::assertStringContainsString('requires a field name', $result->reason);
    }

    #[Test]
    public function context_has_allows_when_field_value_is_null(): void
    {
        // A field set to null still "exists" in the context
        $ctx = $this->contextWith(['nullable_field' => null]);

        $result = $this->guard->evaluate(
            $this->actor(),
            $this->transition(['guard_expression' => 'context:has:nullable_field']),
            $this->instance($ctx),
        );

        self::assertTrue($result->isAllowed());
    }

    #[Test]
    public function context_has_allows_when_field_value_is_empty_string(): void
    {
        $ctx = $this->contextWith(['empty_field' => '']);

        $result = $this->guard->evaluate(
            $this->actor(),
            $this->transition(['guard_expression' => 'context:has:empty_field']),
            $this->instance($ctx),
        );

        self::assertTrue($result->isAllowed());
    }

    // =========================================================================
    // context.eq:<field>:<value>
    // =========================================================================

    #[Test]
    public function context_eq_allows_when_values_match(): void
    {
        $ctx = $this->contextWith(['status' => 'approved']);

        $result = $this->guard->evaluate(
            $this->actor(),
            $this->transition(['guard_expression' => 'context:eq:status:approved']),
            $this->instance($ctx),
        );

        self::assertTrue($result->isAllowed());
    }

    #[Test]
    public function context_eq_denies_when_values_do_not_match(): void
    {
        $ctx = $this->contextWith(['status' => 'pending']);

        $result = $this->guard->evaluate(
            $this->actor(),
            $this->transition(['guard_expression' => 'context:eq:status:approved']),
            $this->instance($ctx),
        );

        self::assertTrue($result->isDenied());
        self::assertStringContainsString('status', $result->reason);
        self::assertStringContainsString('equal', $result->reason);
        self::assertStringContainsString('approved', $result->reason);
        self::assertStringContainsString('pending', $result->reason);
    }

    #[Test]
    public function context_eq_denies_when_field_does_not_exist(): void
    {
        $result = $this->guard->evaluate(
            $this->actor(),
            $this->transition(['guard_expression' => 'context:eq:missing_field:value']),
            $this->instance(),
        );

        self::assertTrue($result->isDenied());
        self::assertStringContainsString('does not exist', $result->reason);
    }

    #[Test]
    public function context_eq_denies_when_field_name_empty(): void
    {
        $result = $this->guard->evaluate(
            $this->actor(),
            $this->transition(['guard_expression' => 'context:eq::value']),
            $this->instance(),
        );

        self::assertTrue($result->isDenied());
        self::assertStringContainsString('requires a field name', $result->reason);
    }

    #[Test]
    public function context_eq_casts_integer_to_string_for_comparison(): void
    {
        $ctx = $this->contextWith(['amount' => 100]);

        $result = $this->guard->evaluate(
            $this->actor(),
            $this->transition(['guard_expression' => 'context:eq:amount:100']),
            $this->instance($ctx),
        );

        self::assertTrue($result->isAllowed());
    }

    #[Test]
    public function context_eq_casts_float_to_string_for_comparison(): void
    {
        $ctx = $this->contextWith(['rate' => 1.5]);

        $result = $this->guard->evaluate(
            $this->actor(),
            $this->transition(['guard_expression' => 'context:eq:rate:1.5']),
            $this->instance($ctx),
        );

        self::assertTrue($result->isAllowed());
    }

    #[Test]
    public function context_eq_allows_comparison_with_empty_value(): void
    {
        $ctx = $this->contextWith(['tag' => '']);

        $result = $this->guard->evaluate(
            $this->actor(),
            $this->transition(['guard_expression' => 'context:eq:tag:']),
            $this->instance($ctx),
        );

        self::assertTrue($result->isAllowed());
    }

    #[Test]
    public function context_eq_value_with_colons_is_preserved(): void
    {
        // Expression: context:eq:url:http://example.com
        // The third part after splitting on 3 pieces is "url:http://example.com"
        // then evaluateEq splits on 2 pieces: field="url", expected="http://example.com"
        // Wait -- let's trace the code. evaluateExpression splits on 3: parts = ['context', 'eq', 'url:http://example.com']
        // evaluateEq splits operands on 2: parts = ['url', 'http://example.com']
        $ctx = $this->contextWith(['url' => 'http://example.com']);

        $result = $this->guard->evaluate(
            $this->actor(),
            $this->transition(['guard_expression' => 'context:eq:url:http://example.com']),
            $this->instance($ctx),
        );

        self::assertTrue($result->isAllowed());
    }

    // =========================================================================
    // context.neq:<field>:<value>
    // =========================================================================

    #[Test]
    public function context_neq_allows_when_values_do_not_match(): void
    {
        $ctx = $this->contextWith(['status' => 'pending']);

        $result = $this->guard->evaluate(
            $this->actor(),
            $this->transition(['guard_expression' => 'context:neq:status:approved']),
            $this->instance($ctx),
        );

        self::assertTrue($result->isAllowed());
    }

    #[Test]
    public function context_neq_denies_when_values_match(): void
    {
        $ctx = $this->contextWith(['status' => 'approved']);

        $result = $this->guard->evaluate(
            $this->actor(),
            $this->transition(['guard_expression' => 'context:neq:status:approved']),
            $this->instance($ctx),
        );

        self::assertTrue($result->isDenied());
        self::assertStringContainsString('not equal', $result->reason);
        self::assertStringContainsString('approved', $result->reason);
    }

    #[Test]
    public function context_neq_denies_when_field_does_not_exist(): void
    {
        $result = $this->guard->evaluate(
            $this->actor(),
            $this->transition(['guard_expression' => 'context:neq:missing:value']),
            $this->instance(),
        );

        self::assertTrue($result->isDenied());
        self::assertStringContainsString('does not exist', $result->reason);
    }

    #[Test]
    public function context_neq_denies_when_field_name_empty(): void
    {
        $result = $this->guard->evaluate(
            $this->actor(),
            $this->transition(['guard_expression' => 'context:neq::value']),
            $this->instance(),
        );

        self::assertTrue($result->isDenied());
        self::assertStringContainsString('requires a field name', $result->reason);
    }

    #[Test]
    public function context_neq_with_integer_value(): void
    {
        $ctx = $this->contextWith(['count' => 5]);

        $result = $this->guard->evaluate(
            $this->actor(),
            $this->transition(['guard_expression' => 'context:neq:count:10']),
            $this->instance($ctx),
        );

        self::assertTrue($result->isAllowed());
    }

    #[Test]
    public function context_neq_denies_when_integer_matches(): void
    {
        $ctx = $this->contextWith(['count' => 5]);

        $result = $this->guard->evaluate(
            $this->actor(),
            $this->transition(['guard_expression' => 'context:neq:count:5']),
            $this->instance($ctx),
        );

        self::assertTrue($result->isDenied());
    }

    // =========================================================================
    // Unknown domain / operator
    // =========================================================================

    #[Test]
    public function unknown_domain_denies_with_informative_message(): void
    {
        $result = $this->guard->evaluate(
            $this->actor(),
            $this->transition(['guard_expression' => 'session:has:user_id']),
            $this->instance(),
        );

        self::assertTrue($result->isDenied());
        self::assertStringContainsString('Unknown expression domain', $result->reason);
        self::assertStringContainsString('session', $result->reason);
    }

    #[Test]
    public function unknown_operator_denies_with_informative_message(): void
    {
        $result = $this->guard->evaluate(
            $this->actor(),
            $this->transition(['guard_expression' => 'context:gt:amount:100']),
            $this->instance(),
        );

        self::assertTrue($result->isDenied());
        self::assertStringContainsString('Unknown expression operator', $result->reason);
        self::assertStringContainsString('gt', $result->reason);
    }

    // =========================================================================
    // Data provider tests for expression evaluation
    // =========================================================================

    /**
     * @return iterable<string, array{expression: string, fields: array<string, mixed>, allowed: bool}>
     */
    public static function expressionProvider(): iterable
    {
        yield 'has existing field' => [
            'expression' => 'context:has:name',
            'fields' => ['name' => 'Alice'],
            'allowed' => true,
        ];

        yield 'has missing field' => [
            'expression' => 'context:has:age',
            'fields' => ['name' => 'Alice'],
            'allowed' => false,
        ];

        yield 'eq matching string' => [
            'expression' => 'context:eq:role:admin',
            'fields' => ['role' => 'admin'],
            'allowed' => true,
        ];

        yield 'eq non-matching string' => [
            'expression' => 'context:eq:role:admin',
            'fields' => ['role' => 'user'],
            'allowed' => false,
        ];

        yield 'neq different values' => [
            'expression' => 'context:neq:status:blocked',
            'fields' => ['status' => 'active'],
            'allowed' => true,
        ];

        yield 'neq same values' => [
            'expression' => 'context:neq:status:blocked',
            'fields' => ['status' => 'blocked'],
            'allowed' => false,
        ];

        yield 'eq integer as string' => [
            'expression' => 'context:eq:priority:1',
            'fields' => ['priority' => 1],
            'allowed' => true,
        ];

        yield 'neq integer mismatch' => [
            'expression' => 'context:neq:priority:2',
            'fields' => ['priority' => 1],
            'allowed' => true,
        ];

        yield 'eq with zero value' => [
            'expression' => 'context:eq:count:0',
            'fields' => ['count' => 0],
            'allowed' => true,
        ];

        yield 'has field with false value' => [
            'expression' => 'context:has:flag',
            'fields' => ['flag' => false],
            'allowed' => true,
        ];
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[Test]
    #[DataProvider('expressionProvider')]
    public function expression_evaluates_correctly(string $expression, array $fields, bool $allowed): void
    {
        $ctx = $this->contextWith($fields);

        $result = $this->guard->evaluate(
            $this->actor(),
            $this->transition(['guard_expression' => $expression]),
            $this->instance($ctx),
        );

        if ($allowed) {
            self::assertTrue($result->isAllowed(), "Expression '$expression' should allow, but denied: {$result->reason}");
        } else {
            self::assertTrue($result->isDenied(), "Expression '$expression' should deny, but allowed");
        }
    }

    // =========================================================================
    // Edge cases
    // =========================================================================

    #[Test]
    public function expression_with_only_domain_and_no_operator(): void
    {
        // Expression: "context" — has no operator
        $result = $this->guard->evaluate(
            $this->actor(),
            $this->transition(['guard_expression' => 'context']),
            $this->instance(),
        );

        self::assertTrue($result->isDenied());
        self::assertStringContainsString('Unknown expression operator', $result->reason);
    }

    #[Test]
    public function other_metadata_keys_are_ignored(): void
    {
        $result = $this->guard->evaluate(
            $this->actor(),
            $this->transition(['other_key' => 'some_value', 'guard_expression' => null]),
            $this->instance(),
        );

        self::assertTrue($result->isAllowed());
    }

    #[Test]
    public function guard_is_deterministic_with_same_inputs(): void
    {
        $ctx = $this->contextWith(['level' => 'high']);
        $transition = $this->transition(['guard_expression' => 'context:eq:level:high']);
        $instance = $this->instance($ctx);
        $actor = $this->actor();

        $result1 = $this->guard->evaluate($actor, $transition, $instance);
        $result2 = $this->guard->evaluate($actor, $transition, $instance);

        self::assertSame($result1->isAllowed(), $result2->isAllowed());
        self::assertSame($result1->reason, $result2->reason);
    }

    #[Test]
    public function guard_result_is_always_guardresult_instance(): void
    {
        $result = $this->guard->evaluate(
            $this->actor(),
            $this->transition(['guard_expression' => 'context:has:field']),
            $this->instance(),
        );

        self::assertInstanceOf(GuardResult::class, $result);
    }
}
