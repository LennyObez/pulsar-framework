<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Interceptor;

use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;

/**
 * Resolves validation rules for a given gRPC method.
 *
 * Implementations map fully qualified method names to arrays of field
 * validation rules, enabling per-method request validation.
 * @api
 */
#[Api(since: '1.0.0')]
interface ValidationRuleResolverInterface
{
    /**
     * Return validation rules for the given method.
     *
     * @param string $fullMethodName Fully qualified method name (e.g. "/helloworld.Greeter/SayHello")
     *
     * @return array<string, list<RuleInterface>> Map of field name to validation rules
     */
    public function rulesForMethod(string $fullMethodName): array;
}
