<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Config\ZeroTrustConfig;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Http\Factory\ResponseFactory;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\ZeroTrust\Claim\ClaimSet;
use Pulsar\Security\ZeroTrust\Event\PolicyDecisionEvent;
use Pulsar\Security\ZeroTrust\Policy\PolicyDecision;
use Pulsar\Security\ZeroTrust\Policy\PolicyEngineInterface;
use Pulsar\Security\ZeroTrust\Signal\SignalContext;
use Pulsar\Security\ZeroTrust\Signal\SignalProviderInterface;
use Pulsar\Security\ZeroTrust\StepUp\Internal\StepUpManager;
use Pulsar\Security\ZeroTrust\StepUp\StepUpAction;

/**
 * PSR-15 middleware that enforces zero-trust policy on every request.
 *
 * On each request:
 * 1. Builds a SignalContext from the PSR-7 request
 * 2. Collects claims from all registered signal providers
 * 3. Evaluates claims via the policy engine
 * 4. Grant: passes through to the next handler
 * 5. Deny: returns a 403 Forbidden response
 * 6. StepUp: delegates to StepUpManager for loop protection
 * 7. Logs all decisions via AuditLogger
 */
#[Internal(reason: 'Wired by composition root only')]
final readonly class ZeroTrustMiddleware implements MiddlewareInterface
{
    /**
     * @param list<SignalProviderInterface> $signalProviders
     */
    public function __construct(
        private ZeroTrustConfig $config,
        private array $signalProviders,
        private PolicyEngineInterface $policyEngine,
        private EventDispatcherInterface $eventDispatcher,
        private AuditLoggerInterface $auditLogger,
        private StepUpManager $stepUpManager,
        private ResponseFactory $responseFactory = new ResponseFactory(),
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->config->enabled) {
            return $handler->handle($request);
        }

        $sessionId = $this->extractSessionId($request);
        $identityId = $this->extractIdentityId($request);
        $resource = $request->getUri()->getPath();
        $action = $request->getMethod();

        $context = new SignalContext(
            request: $request,
            sessionId: $sessionId,
            identityId: $identityId,
        );

        $claims = $this->collectClaims($context);
        $result = $this->policyEngine->evaluate($claims, $resource, $action);

        $this->eventDispatcher->dispatch(new PolicyDecisionEvent(
            result: $result,
            identityId: $identityId,
            sessionId: $sessionId,
        ));

        return match ($result->decision) {
            PolicyDecision::Grant => $this->handleGrant($request, $handler, $identityId, $resource),
            PolicyDecision::Deny => $this->handleDeny($identityId, $resource),
            PolicyDecision::StepUp => $this->handleStepUp($identityId, $resource, $result),
        };
    }

    private function collectClaims(SignalContext $context): ClaimSet
    {
        $claims = new ClaimSet();

        foreach ($this->signalProviders as $provider) {
            $claims = $claims->merge($provider->evaluate($context));
        }

        return $claims;
    }

    private function handleGrant(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
        string $identityId,
        string $resource,
    ): ResponseInterface {
        $this->auditLogger->log(
            event: AuditEvent::Authorization,
            outcome: AuditOutcome::Success,
            actor: $identityId !== '' ? $identityId : null,
            action: 'zero_trust.grant',
            resource: $resource,
        );

        return $handler->handle($request);
    }

    private function handleDeny(string $identityId, string $resource): ResponseInterface
    {
        $this->auditLogger->log(
            event: AuditEvent::Authorization,
            outcome: AuditOutcome::Denied,
            actor: $identityId !== '' ? $identityId : null,
            action: 'zero_trust.deny',
            resource: $resource,
        );

        return $this->responseFactory->createResponse(403, 'Forbidden');
    }

    private function handleStepUp(
        string $identityId,
        string $resource,
        \Pulsar\Security\ZeroTrust\Policy\PolicyEvaluationResult $result,
    ): ResponseInterface {
        $ruleName = $result->matchedRules !== [] ? $result->matchedRules[0]->name : 'unknown';
        $stepUpAction = $this->stepUpManager->handleStepUp(
            $identityId,
            $ruleName,
            $this->config->stepUp,
        );

        return match ($stepUpAction) {
            StepUpAction::Redirect => $this->respondStepUpRequired($identityId, $resource),
            StepUpAction::Deny => $this->handleDeny($identityId, $resource),
            StepUpAction::Allow => $this->respondStepUpRequired($identityId, $resource),
        };
    }

    private function respondStepUpRequired(string $identityId, string $resource): ResponseInterface
    {
        $this->auditLogger->log(
            event: AuditEvent::Authorization,
            outcome: AuditOutcome::Failure,
            actor: $identityId !== '' ? $identityId : null,
            action: 'zero_trust.step_up_required',
            resource: $resource,
        );

        return $this->responseFactory->createResponse(403, 'Step-Up Authentication Required');
    }

    private function extractSessionId(ServerRequestInterface $request): string
    {
        /** @var string */
        return $request->getAttribute('session_id', '');
    }

    private function extractIdentityId(ServerRequestInterface $request): string
    {
        /** @var string */
        return $request->getAttribute('identity_id', '');
    }
}
