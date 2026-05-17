<?php

declare(strict_types=1);

namespace Pulsar\Security\Incident;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\ThreatDetection\ThreatEvent;
use Throwable;

/**
 * Executes incident response playbooks when threat events are detected.
 *
 * Each playbook maps a ThreatCategory to a chain of PlaybookSteps.
 * Steps execute sequentially; if any step returns false, the chain halts.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PlaybookEngine
{
    /** @var array<string, Playbook> Keyed by ThreatCategory::value */
    private array $playbooks;

    /**
     * @param list<Playbook> $playbooks
     */
    public function __construct(
        array $playbooks,
        private LoggerInterface $logger,
        private ?AuditLogger $auditLogger = null,
    ) {
        $indexed = [];
        foreach ($playbooks as $playbook) {
            $indexed[$playbook->trigger->value] = $playbook;
        }
        $this->playbooks = $indexed;
    }

    /**
     * Handle a threat event by executing the matching playbook chain.
     *
     * @return PlaybookResult The result of executing the playbook
     */
    public function handle(ThreatEvent $event): PlaybookResult
    {
        $playbook = $this->playbooks[$event->category->value] ?? null;

        if ($playbook === null) {
            $this->logger->debug('No playbook registered for threat category', [
                'category' => $event->category->value,
                'source_ip' => $event->sourceIp,
            ]);

            return PlaybookResult::noPlaybook($event->category);
        }

        $executed = [];
        $halted = false;

        foreach ($playbook->steps as $step) {
            $stepName = $step->name();

            try {
                $continue = $step->execute($event);
                $executed[] = $stepName;

                $this->logger->info('Playbook step executed', [
                    'playbook' => $playbook->effectiveName(),
                    'step' => $stepName,
                    'continue' => $continue,
                    'category' => $event->category->value,
                    'source_ip' => $event->sourceIp,
                ]);

                $this->auditLogger?->log(
                    AuditEvent::SecurityEvent,
                    AuditOutcome::Success,
                    null,
                    'playbook.step.executed',
                    $playbook->effectiveName(),
                    [
                        'step' => $stepName,
                        'category' => $event->category->value,
                        'source_ip' => $event->sourceIp,
                    ],
                );

                if (!$continue) {
                    $halted = true;
                    break;
                }
            } catch (Throwable $e) {
                $executed[] = $stepName;
                $this->logger->error('Playbook step failed', [
                    'playbook' => $playbook->effectiveName(),
                    'step' => $stepName,
                    'error' => $e->getMessage(),
                    'category' => $event->category->value,
                ]);

                return PlaybookResult::error($event->category, $executed, $stepName, $e->getMessage());
            }
        }

        return PlaybookResult::completed($event->category, $executed, $halted);
    }

    /**
     * Check if a playbook is registered for the given category.
     */
    public function hasPlaybook(ThreatEvent $event): bool
    {
        return isset($this->playbooks[$event->category->value]);
    }
}
