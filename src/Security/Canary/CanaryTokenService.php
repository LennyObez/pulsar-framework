<?php

declare(strict_types=1);

namespace Pulsar\Security\Canary;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Audit\AuditActor;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\ThreatDetection\ThreatCategory;
use Pulsar\Security\ThreatDetection\ThreatEvent;
use Pulsar\Security\ThreatDetection\ThreatEventDispatcherInterface;
use Pulsar\Security\ThreatDetection\ThreatResponse;
use Random\Engine\Secure;
use Random\Randomizer;

use function bin2hex;
use function str_contains;

/**
 * Service for creating and detecting canary tokens in data exports.
 *
 * Canary tokens are invisible markers embedded in sensitive data.
 * When detected in an unauthorized location, they trigger alerts
 * to identify the source of a data leak.
 * @api
 */
#[Api(since: '1.0.0')]
final class CanaryTokenService
{
    /** @var array<string, CanaryToken> token ID => CanaryToken */
    private array $registry = [];

    private readonly Randomizer $randomizer;

    public function __construct(
        private readonly ?AuditLoggerInterface $auditLogger = null,
        private readonly ?ThreatEventDispatcherInterface $eventDispatcher = null,
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer(new Secure());
    }

    /**
     * Generate a new canary token.
     *
     * The returned token contains a unique marker string that can be
     * embedded invisibly in data exports (e.g., as a hidden row,
     * zero-width characters, or an invisible CSS class).
     */
    #[NoDiscard]
    public function generate(string $label, string $context, ?string $createdBy = null): CanaryToken
    {
        $id = bin2hex($this->randomizer->getBytes(16));
        $marker = 'CNRY-' . bin2hex($this->randomizer->getBytes(12));

        $token = new CanaryToken(
            id: $id,
            label: $label,
            marker: $marker,
            context: $context,
            createdAt: new DateTimeImmutable(),
            createdBy: $createdBy,
        );

        $this->registry[$id] = $token;

        // A null $createdBy would make AuditLogger throw
        // AuditActorMissingException (absent a request context) and lose
        // the canary-creation record. Attribute system-originated creates
        // to the same system actor used by scan() so the compliance trail
        // always records who planted a canary.
        $this->auditLogger?->log(
            event: AuditEvent::SecurityEvent,
            outcome: AuditOutcome::Success,
            actor: $createdBy ?? AuditActor::system('security.canary'),
            action: 'canary.created',
            resource: $label,
            metadata: ['token_id' => $id, 'context' => $context],
        );

        return $token;
    }

    /**
     * Check if content contains any registered canary token.
     *
     * @return list<CanaryAlertEvent> Alert events for each detected token
     */
    public function scan(string $content, string $location): array
    {
        $alerts = [];

        foreach ($this->registry as $token) {
            if (!str_contains($content, $token->marker)) {
                continue;
            }

            $alert = CanaryAlertEvent::create(
                token: $token,
                detectedLocation: $location,
            );

            $alerts[] = $alert;

            $this->eventDispatcher?->dispatch(ThreatEvent::create(
                category: ThreatCategory::DataLeak,
                recommendedAction: ThreatResponse::Alert,
                sourceIp: 'n/a',
                description: 'Canary token detected in unexpected location: ' . $location,
                confidence: 1.0,
                metadata: [
                    'token_id' => $token->id,
                    'label' => $token->label,
                    'context' => $token->context,
                    'location' => $location,
                ],
            ));

            $this->auditLogger?->log(
                event: AuditEvent::SecurityEvent,
                outcome: AuditOutcome::Failure,
                actor: AuditActor::system('security.canary'),
                action: 'canary.triggered',
                resource: $token->label,
                metadata: [
                    'token_id' => $token->id,
                    'location' => $location,
                    'context' => $token->context,
                ],
            );
        }

        return $alerts;
    }

    /**
     * Register an existing canary token (e.g., loaded from storage).
     */
    public function register(CanaryToken $token): void
    {
        $this->registry[$token->id] = $token;
    }

    /**
     * Get a registered token by ID.
     */
    #[NoDiscard]
    public function get(string $id): ?CanaryToken
    {
        return $this->registry[$id] ?? null;
    }

    /**
     * @return array<string, CanaryToken>
     */
    #[NoDiscard]
    public function all(): array
    {
        return $this->registry;
    }
}
