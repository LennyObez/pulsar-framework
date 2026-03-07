<?php

declare(strict_types=1);

namespace Pulsar\Idempotency\Exception;

use Pulsar\Api\Api;

/**
 * F22.9: programmatic classification of `IdempotencyException` failures so
 * HTTP / controller layers can map each kind to a consistent response code
 * without parsing exception messages.
 *
 * Recommended HTTP mapping for callers:
 *
 * | Kind                  | HTTP status         | Retry semantics |
 * |-----------------------|---------------------|-----------------|
 * | ConcurrentClaim       | 409 Conflict        | Retryable       |
 * | ParameterMismatch     | 422 Unprocessable   | Fatal           |
 * | InvalidKey            | 400 Bad Request     | Fatal           |
 * | TamperedPayload       | 500 Internal Error  | Fatal           |
 * | SerializationFailed   | 500 Internal Error  | Fatal           |
 * | CommitFailed          | 500 Internal Error  | Operator-driven |
 *
 * `ConcurrentClaim` is the only kind a well-behaved client should retry on
 * its own — it indicates the same idempotency key is being processed by a
 * concurrent in-flight request, and the result of that request will be
 * available shortly. All other kinds are caller errors or operator-side
 * incidents.
 */
#[Api(since: '1.0.0')]
enum IdempotencyErrorKind: string
{
    case ConcurrentClaim = 'concurrent_claim';
    case ParameterMismatch = 'parameter_mismatch';
    case InvalidKey = 'invalid_key';
    case TamperedPayload = 'tampered_payload';
    case SerializationFailed = 'serialization_failed';
    case CommitFailed = 'commit_failed';
}
