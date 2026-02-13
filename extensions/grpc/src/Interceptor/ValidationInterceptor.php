<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Interceptor;

use Closure;
use JsonException;
use Pulsar\Api\Internal;
use Pulsar\Extension\Grpc\Error\GrpcStatus;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\ValidationResult;
use Pulsar\Http\Validation\Violation;

use function count;
use function implode;
use function is_array;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Interceptor that validates gRPC request payloads.
 *
 * Deserializes the protobuf request payload as JSON, resolves validation
 * rules for the target method, and applies them. On failure, rejects the
 * call with INVALID_ARGUMENT containing violation details.
 */
#[Internal(reason: 'Pipeline implementation detail — use InterceptorPipeline')]
final readonly class ValidationInterceptor implements InterceptorInterface
{
    public function __construct(
        private ValidationRuleResolverInterface $ruleResolver,
    ) {}

    public function handle(CallContext $context, Closure $next): InterceptorResult
    {
        $rules = $this->ruleResolver->rulesForMethod($context->method->fullName);

        if ($rules === []) {
            return $next($context);
        }

        $data = $this->deserializePayload($context->payload);

        if ($data === null) {
            return InterceptorResult::error(
                GrpcStatus::InvalidArgument,
                'Request payload could not be deserialized',
            );
        }

        $violations = $this->validate($data, $rules);
        $result = new ValidationResult($violations);

        if ($result->failed()) {
            return InterceptorResult::error(
                GrpcStatus::InvalidArgument,
                $this->formatViolations($result),
            );
        }

        return $next($context);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function deserializePayload(string $payload): ?array
    {
        if ($payload === '') {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

            if (!is_array($decoded)) {
                return null;
            }

            /** @var array<string, mixed> $decoded */
            return $decoded;
        } catch (JsonException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, list<RuleInterface>> $rules
     *
     * @return list<Violation>
     */
    private function validate(array $data, array $rules): array
    {
        $violations = [];

        foreach ($rules as $field => $fieldRules) {
            $value = $data[$field] ?? null;

            foreach ($fieldRules as $rule) {
                $violation = $rule->validate($field, $value, $data);

                if ($violation !== null) {
                    $violations[] = $violation;
                }
            }
        }

        return $violations;
    }

    private function formatViolations(ValidationResult $result): string
    {
        $messages = [];

        foreach ($result->violations as $violation) {
            $messages[] = $violation->field . ': ' . $violation->message;
        }

        $count = count($messages);

        return 'Validation failed with ' . $count . ' violation(s): ' . implode('; ', $messages);
    }
}
