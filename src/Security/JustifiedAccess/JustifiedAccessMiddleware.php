<?php

declare(strict_types=1);

namespace Pulsar\Security\JustifiedAccess;

use DateTimeImmutable;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Api;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\ResponseStatus;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function bin2hex;
use function in_array;
use function is_array;
use function is_string;
use function mb_strlen;
use function random_bytes;
use function trim;

/**
 * Enforces mandatory access justification on protected routes.
 *
 * Intercepts requests to routes marked with #[RequiresJustification] and
 * requires the caller to provide a justification via request headers or
 * body fields. Records every justified access in a tamper-evident audit trail.
 *
 * Headers:
 *   X-Access-Justification: free-text justification (required)
 *   X-Access-Justification-Category: justification category (optional, defaults to customer_request)
 *
 * Body fields (fallback):
 *   _access_justification: free-text justification
 *   _access_justification_category: justification category
 */
#[Api(since: '1.0.0')]
final readonly class JustifiedAccessMiddleware implements MiddlewareInterface
{
    public function __construct(
        private JustifiedAccessConfig $config,
        private JustificationStoreInterface $store,
        private AuditLoggerInterface $auditLogger,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->config->enabled) {
            return $handler->handle($request);
        }

        $attribute = $this->resolveAttribute($request);

        if ($attribute === null) {
            return $handler->handle($request);
        }

        $justificationText = $this->extractJustification($request);

        if ($justificationText === null) {
            $this->auditLogger->log(
                event: AuditEvent::Authorization,
                outcome: AuditOutcome::Denied,
                actor: $this->extractActorId($request),
                action: 'justified_access.denied',
                resource: $request->getUri()->getPath(),
                metadata: ['reason' => 'missing_justification'],
            );

            return Response::json(
                ['error' => 'Forbidden', 'message' => 'Access justification required'],
                ResponseStatus::Forbidden->value,
            );
        }

        if (mb_strlen($justificationText) < $this->config->minJustificationLength) {
            $this->auditLogger->log(
                event: AuditEvent::Authorization,
                outcome: AuditOutcome::Denied,
                actor: $this->extractActorId($request),
                action: 'justified_access.denied',
                resource: $request->getUri()->getPath(),
                metadata: [
                    'reason' => 'justification_too_short',
                    'min_length' => $this->config->minJustificationLength,
                ],
            );

            return Response::json(
                [
                    'error' => 'Forbidden',
                    'message' => 'Justification must be at least ' . $this->config->minJustificationLength . ' characters',
                ],
                ResponseStatus::Forbidden->value,
            );
        }

        $category = $this->extractCategory($request);

        if ($attribute->allowedCategories !== null && !in_array($category, $attribute->allowedCategories, true)) {
            return Response::json(
                ['error' => 'Forbidden', 'message' => 'Invalid justification category for this resource'],
                ResponseStatus::Forbidden->value,
            );
        }

        $requiresSupervisor = $attribute->requireSupervisorApproval
            || in_array($attribute->dataClassification, $this->config->requireSupervisorFor, true);

        $supervisorApproval = null;
        if ($requiresSupervisor) {
            $approvalHeader = $request->getHeaderLine('X-Supervisor-Approval');
            $supervisorApproval = $approvalHeader === 'true';

            if (!$supervisorApproval) {
                $this->auditLogger->log(
                    event: AuditEvent::Authorization,
                    outcome: AuditOutcome::Denied,
                    actor: $this->extractActorId($request),
                    action: 'justified_access.denied',
                    resource: $request->getUri()->getPath(),
                    metadata: ['reason' => 'supervisor_approval_required'],
                );

                return Response::json(
                    ['error' => 'Forbidden', 'message' => 'Supervisor approval required for this data classification'],
                    ResponseStatus::Forbidden->value,
                );
            }
        }

        $record = new JustificationRecord(
            id: bin2hex(random_bytes(16)),
            actorId: $this->extractActorId($request),
            actorName: $this->extractActorName($request),
            actorRole: $this->extractActorRole($request),
            resourceType: $this->extractResourceType($request),
            resourceId: $this->extractResourceId($request),
            category: $category,
            justificationText: $justificationText,
            dataClassification: $attribute->dataClassification,
            accessTimestamp: new DateTimeImmutable(),
            sessionId: $this->extractSessionId($request),
            ipAddress: $this->extractIpAddress($request),
            supervisorApproval: $supervisorApproval,
            reviewStatus: ReviewStatus::Pending,
        );

        $this->store->store($record);

        $this->auditLogger->log(
            event: AuditEvent::DataAccess,
            outcome: AuditOutcome::Success,
            actor: $record->actorId,
            action: 'justified_access.granted',
            resource: $record->resourceType . ':' . $record->resourceId,
            metadata: [
                'justification_id' => $record->id,
                'category' => $record->category->value,
                'data_classification' => $record->dataClassification->value,
                'supervisor_approval' => $record->supervisorApproval,
            ],
        );

        return $handler->handle(
            $request->withAttribute('justified_access_record', $record),
        );
    }

    private function resolveAttribute(ServerRequestInterface $request): ?RequiresJustification
    {
        $attribute = $request->getAttribute('requires_justification');

        if ($attribute instanceof RequiresJustification) {
            return $attribute;
        }

        return null;
    }

    private function extractJustification(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine($this->config->justificationHeader);

        if ($header !== '') {
            return trim($header);
        }

        $body = $request->getParsedBody();
        $bodyField = is_array($body) ? ($body['_access_justification'] ?? null) : null;

        if (is_string($bodyField) && trim($bodyField) !== '') {
            return trim($bodyField);
        }

        return null;
    }

    private function extractCategory(ServerRequestInterface $request): JustificationCategory
    {
        $header = $request->getHeaderLine($this->config->categoryHeader);

        if ($header !== '') {
            $category = JustificationCategory::tryFrom($header);
            if ($category !== null) {
                return $category;
            }
        }

        $body = $request->getParsedBody();
        $bodyField = is_array($body) ? ($body['_access_justification_category'] ?? null) : null;

        if (is_string($bodyField)) {
            $category = JustificationCategory::tryFrom($bodyField);
            if ($category !== null) {
                return $category;
            }
        }

        return JustificationCategory::CustomerRequest;
    }

    private function extractActorId(ServerRequestInterface $request): string
    {
        $actor = $request->getAttribute('actor_id');

        return is_string($actor) ? $actor : 'unknown';
    }

    private function extractActorName(ServerRequestInterface $request): string
    {
        $name = $request->getAttribute('actor_name');

        return is_string($name) ? $name : '';
    }

    private function extractActorRole(ServerRequestInterface $request): string
    {
        $role = $request->getAttribute('actor_role');

        return is_string($role) ? $role : '';
    }

    private function extractResourceType(ServerRequestInterface $request): string
    {
        $type = $request->getAttribute('resource_type');

        return is_string($type) ? $type : 'http_endpoint';
    }

    private function extractResourceId(ServerRequestInterface $request): string
    {
        $id = $request->getAttribute('resource_id');

        return is_string($id) ? $id : $request->getUri()->getPath();
    }

    private function extractSessionId(ServerRequestInterface $request): string
    {
        $sessionId = $request->getAttribute('session_id');

        return is_string($sessionId) ? $sessionId : '';
    }

    private function extractIpAddress(ServerRequestInterface $request): string
    {
        $serverParams = $request->getServerParams();
        $ip = $serverParams['REMOTE_ADDR'] ?? null;

        return is_string($ip) ? $ip : '';
    }
}
