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
use Pulsar\Security\Compliance\DataClassification;
use Pulsar\Security\Incident\IncidentReporterInterface;
use Pulsar\Security\Incident\IncidentSeverity;

use function bin2hex;
use function is_string;
use function mb_strlen;
use function random_bytes;
use function trim;

/**
 * Emergency "break the glass" access middleware.
 *
 * Allows authorized personnel to bypass normal access controls in emergency
 * situations (e.g., a doctor accessing a non-assigned patient's records in an
 * emergency). Every break-the-glass event is:
 *
 * 1. Logged at elevated audit level (SecurityEvent)
 * 2. Reported as a security incident for mandatory post-incident review
 * 3. Time-limited (configurable, default 15 minutes)
 * 4. Recorded with mandatory justification
 *
 * Activated via the X-Break-The-Glass: true header.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class BreakTheGlassMiddleware implements MiddlewareInterface
{
    public function __construct(
        private JustifiedAccessConfig $config,
        private JustificationStoreInterface $store,
        private AuditLoggerInterface $auditLogger,
        private IncidentReporterInterface $incidentReporter,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->config->enabled) {
            return $handler->handle($request);
        }

        $breakTheGlassHeader = $request->getHeaderLine('X-Break-The-Glass');

        if ($breakTheGlassHeader !== 'true') {
            return $handler->handle($request);
        }

        $justificationText = $request->getHeaderLine($this->config->justificationHeader);

        if (trim($justificationText) === '' || mb_strlen($justificationText) < $this->config->minJustificationLength) {
            return Response::json(
                ['error' => 'Forbidden', 'message' => 'Emergency access requires justification'],
                ResponseStatus::Forbidden->value,
            );
        }

        $actorId = $this->extractActorId($request);
        $resourcePath = $request->getUri()->getPath();

        $record = new JustificationRecord(
            id: bin2hex(random_bytes(16)),
            actorId: $actorId,
            actorName: $this->extractActorName($request),
            actorRole: $this->extractActorRole($request),
            resourceType: $this->extractResourceType($request),
            resourceId: $this->extractResourceId($request),
            category: JustificationCategory::Emergency,
            justificationText: trim($justificationText),
            dataClassification: DataClassification::Restricted,
            accessTimestamp: new DateTimeImmutable(),
            sessionId: $this->extractSessionId($request),
            ipAddress: $this->extractIpAddress($request),
            supervisorApproval: null,
            reviewStatus: ReviewStatus::Flagged,
            breakTheGlass: true,
            metadata: [
                'duration_seconds' => $this->config->breakTheGlassDuration,
                'request_method' => $request->getMethod(),
                'request_path' => $resourcePath,
            ],
        );

        $this->store->store($record);

        $this->auditLogger->log(
            event: AuditEvent::SecurityEvent,
            outcome: AuditOutcome::Success,
            actor: $actorId,
            action: 'break_the_glass.activated',
            resource: $record->resourceType . ':' . $record->resourceId,
            metadata: [
                'justification_id' => $record->id,
                'justification_text' => $record->justificationText,
                'duration_seconds' => $this->config->breakTheGlassDuration,
            ],
        );

        $this->incidentReporter->report(
            severity: IncidentSeverity::High,
            title: 'Break-the-glass access activated',
            description: 'Emergency access activated by ' . $actorId
                . ' for resource ' . $record->resourceType . ':' . $record->resourceId
                . '. Justification: ' . $record->justificationText,
            source: $actorId,
            metadata: [
                'justification_id' => $record->id,
                'resource_type' => $record->resourceType,
                'resource_id' => $record->resourceId,
                'ip_address' => $record->ipAddress,
                'duration_seconds' => $this->config->breakTheGlassDuration,
            ],
        );

        return $handler->handle(
            $request
                ->withAttribute('justified_access_record', $record)
                ->withAttribute('break_the_glass', true)
                ->withAttribute('break_the_glass_expires', $record->accessTimestamp->modify('+' . $this->config->breakTheGlassDuration . ' seconds')),
        );
    }

    private function extractActorId(ServerRequestInterface $request): string
    {
        /** @var mixed $actor */
        $actor = $request->getAttribute('actor_id');

        return is_string($actor) ? $actor : 'unknown';
    }

    private function extractActorName(ServerRequestInterface $request): string
    {
        /** @var mixed $name */
        $name = $request->getAttribute('actor_name');

        return is_string($name) ? $name : '';
    }

    private function extractActorRole(ServerRequestInterface $request): string
    {
        /** @var mixed $role */
        $role = $request->getAttribute('actor_role');

        return is_string($role) ? $role : '';
    }

    private function extractResourceType(ServerRequestInterface $request): string
    {
        /** @var mixed $type */
        $type = $request->getAttribute('resource_type');

        return is_string($type) ? $type : 'http_endpoint';
    }

    private function extractResourceId(ServerRequestInterface $request): string
    {
        /** @var mixed $id */
        $id = $request->getAttribute('resource_id');

        return is_string($id) ? $id : $request->getUri()->getPath();
    }

    private function extractSessionId(ServerRequestInterface $request): string
    {
        /** @var mixed $sessionId */
        $sessionId = $request->getAttribute('session_id');

        return is_string($sessionId) ? $sessionId : '';
    }

    private function extractIpAddress(ServerRequestInterface $request): string
    {
        $serverParams = $request->getServerParams();
        /** @var mixed $ip */
        $ip = $serverParams['REMOTE_ADDR'] ?? null;

        return is_string($ip) ? $ip : '';
    }
}
