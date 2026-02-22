<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Interceptor;

use Closure;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Grpc\Error\GrpcStatus;
use Pulsar\Extension\Grpc\Security\GrpcSecurityEvent;
use Pulsar\Extension\Grpc\Security\MtlsIdentityMapper;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function str_starts_with;
use function strlen;
use function substr;
use function trim;

/**
 * Interceptor that authenticates and authorizes gRPC calls.
 *
 * Checks for a bearer token in the "authorization" metadata header (JWT)
 * or falls back to mTLS peer identity from the call context. If neither
 * yields a valid identity, the call is rejected with UNAUTHENTICATED.
 *
 * When an MtlsIdentityMapper is provided, mTLS-authenticated identities
 * are additionally checked for method-level authorization. Unauthorized
 * calls are rejected with PERMISSION_DENIED.
 */
#[Internal(reason: 'Pipeline implementation detail — use InterceptorPipeline')]
final readonly class AuthInterceptor implements InterceptorInterface
{
    private const string BEARER_PREFIX = 'Bearer ';

    public function __construct(
        private AuthValidatorInterface $authValidator,
        private ?MtlsIdentityMapper $identityMapper = null,
        private ?AuditLoggerInterface $auditLogger = null,
    ) {}

    public function handle(CallContext $context, Closure $next): InterceptorResult
    {
        $identity = $this->authenticateFromToken($context);

        if ($identity === null) {
            $identity = $this->authenticateFromMtls($context);
        }

        if ($identity === null) {
            $this->emitAuthenticationFailed($context);

            return InterceptorResult::error(
                GrpcStatus::Unauthenticated,
                'No valid authentication credentials provided',
            );
        }

        // Authorize mTLS identity against the method permission model
        if ($this->identityMapper !== null && $context->peerIdentity !== null) {
            $serviceIdentity = $this->identityMapper->resolve($context->peerIdentity);

            if ($serviceIdentity !== null && !$serviceIdentity->isMethodAllowed($context->method->fullName)) {
                $this->emitAuthorizationDenied($context, $identity);

                return InterceptorResult::error(
                    GrpcStatus::PermissionDenied,
                    'Access denied',
                );
            }
        }

        return $next($context->withAttribute('auth.identity', $identity));
    }

    private function authenticateFromToken(CallContext $context): ?string
    {
        $authorization = $context->getMetadataValue('authorization');

        if ($authorization === null) {
            return null;
        }

        $authorization = trim($authorization);

        if (!str_starts_with($authorization, self::BEARER_PREFIX)) {
            return null;
        }

        $token = substr($authorization, strlen(self::BEARER_PREFIX));

        if ($token === '') {
            return null;
        }

        return $this->authValidator->validateToken($token);
    }

    private function authenticateFromMtls(CallContext $context): ?string
    {
        return $context->peerIdentity;
    }

    private function emitAuthenticationFailed(CallContext $context): void
    {
        $this->auditLogger?->log(
            event: AuditEvent::SecurityEvent,
            outcome: AuditOutcome::Failure,
            actor: $context->peerIdentity,
            action: GrpcSecurityEvent::MtlsAuthenticationFailed->value,
            resource: $context->method->fullName,
            metadata: [
                'peer_identity' => $context->peerIdentity,
            ],
        );
    }

    private function emitAuthorizationDenied(CallContext $context, string $identity): void
    {
        $this->auditLogger?->log(
            event: AuditEvent::SecurityEvent,
            outcome: AuditOutcome::Failure,
            actor: $identity,
            action: GrpcSecurityEvent::GrpcAuthorizationDenied->value,
            resource: $context->method->fullName,
            metadata: [
                'identity' => $identity,
                'method' => $context->method->fullName,
            ],
        );
    }
}
