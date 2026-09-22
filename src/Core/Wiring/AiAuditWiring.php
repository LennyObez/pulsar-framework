<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Override;
use Pulsar\AI\AiClientInterface;
use Pulsar\AI\Audit\AuditingAiClient;
use Pulsar\AI\Audit\EgressDecisionSinkInterface;
use Pulsar\AI\Audit\EgressDecisionSourceInterface;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\Exception\ConfigException;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\Security\Crypto\KeyProviderInterface;
use Pulsar\Security\Crypto\SubKeyId;

/**
 * Makes every inference from the core AI layer land in the HMAC-chained audit
 * trail, and refuses to boot a deployment where it cannot.
 *
 * IT FAILS CLOSED, and that is the decision to read first. Every other wiring in
 * `src/` that touches the audit trail asks `has(AuditLoggerInterface::class)` and
 * falls back to {@see \Pulsar\Audit\NullAuditLogger} — correctly, because for a
 * brute-force detector or a mail send the audit entry is a SIDE EFFECT of work
 * that still has value unrecorded. For an inference it is not a side effect. A
 * model call that informs a regulated decision has, as its regulatory artefact,
 * the record that it happened; SOC 2 CC7, ISO/IEC 42001 clause 9.1 and the EU AI
 * Act's logging duty for high-risk systems are all satisfied by the trail and by
 * nothing else. Degrading here would produce exactly the posture this wiring
 * exists to end — a bank calling a language model and keeping no evidence —
 * except now with a framework component named "audit" in the graph to make it
 * look handled. For a bank an unauditable model call is worse than no model call,
 * so a bound AI client with no audit chain aborts boot.
 *
 * There is NO CONFIGURATION KEY that turns this off. A switch named
 * `ai.audit.enabled` would be the optional `ai-governance` extension again in a
 * shorter costume, and the first deployment to flip it would be the one under
 * time pressure.
 *
 * The precedent is {@see ComplianceLoggingWiring}, which makes the same call for
 * the same reason: enabling compliance logging without a master key is a hard
 * boot error there, because an operator who believes log output is masked must
 * never silently emit it unmasked. An operator who believes model calls are
 * recorded must never silently make them unrecorded.
 *
 * WHAT ABORTS AND WHAT DOES NOT. Nothing is bound and nothing is refused when no
 * core `AiClientInterface` is bound — a deployment that does not use the AI layer
 * is not made to hold a key for it. It is the presence of an AI client that makes
 * the audit chain mandatory.
 *
 * ORDER MATTERS TWICE. This wiring must run after {@see SecurityWiring}, which is
 * what binds the audit logger and the key provider, and after whatever binds the
 * AI client and any egress control — because {@see AuditingAiClient} has to be the
 * OUTERMOST wrapper. Wrapped the other way round, the auditor would run before the
 * egress control had decided anything, every record would say the egress decision
 * was not observed, and a refused call would be filed as a plain provider error.
 * Its position in {@see WiringList} is therefore load-bearing, not cosmetic.
 *
 * THE EGRESS SEAM IS CONSUMED, NEVER CREATED HERE. Whoever builds
 * {@see \Pulsar\AI\Egress\GuardedAiClient} hands it an
 * {@see EgressDecisionSinkInterface} and binds the same object as the source
 * half; this wiring picks that binding up if it is there and passes null if it is
 * not. Binding a fresh, unattached channel instead would be worse than binding
 * none: nothing would ever report into it, and the auditor — which reads silence
 * on a composed channel as "the control examined this payload and found nothing"
 * — would write that claim on every record of a deployment that has no control at
 * all. With no binding, every record says `ai_egress: not_observed`, which is the
 * honest reading and is not the same claim as "nothing was redacted".
 */
#[Internal]
final readonly class AiAuditWiring implements ServiceWiringInterface
{
    /**
     * KDF context for the inference content digests (exactly 8 bytes).
     *
     * Paired with {@see SubKeyId::AiInferenceDigest}; the registry test in
     * `tests/Unit/Security/Crypto/SubKeyIdRegistryTest.php` names this file as the
     * pair's owner.
     */
    private const string DIGEST_CONTEXT = 'ai_cdgst';

    #[Override]
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        if (!$container->has(AiClientInterface::class)) {
            // No AI layer in this deployment. Nothing to record, nothing to
            // demand a master key for.
            return;
        }

        if (!$container->has(AuditLoggerInterface::class) || !$container->has(KeyProviderInterface::class)) {
            throw ConfigException::invalidValue(
                'ai',
                'an AI client is wired but the tamper-evident audit chain is not: every inference would '
                    . 'leave no record, which SOC 2, ISO/IEC 42001 and the EU AI Act all require. Set '
                    . 'PULSAR_MASTER_KEY (which wires the audit logger and the key provider), or do not bind '
                    . 'Pulsar\\AI\\AiClientInterface. There is no switch that makes an unaudited inference '
                    . 'acceptable',
            );
        }

        /** @var KeyProviderInterface $keys */
        $keys = $container->get(KeyProviderInterface::class);
        /** @var AuditLoggerInterface $auditLogger */
        $auditLogger = $container->get(AuditLoggerInterface::class);
        /** @var AiClientInterface $inner */
        $inner = $container->get(AiClientInterface::class);

        $source = $this->egressSeam($container);

        $container->instance(
            AiClientInterface::class,
            new AuditingAiClient(
                $inner,
                $auditLogger,
                $keys->deriveSubKey(SubKeyId::AiInferenceDigest->value, self::DIGEST_CONTEXT),
                $source,
            ),
        );
    }

    /**
     * The channel an egress control reports its decisions through, when one
     * exists — and NOTHING when one does not.
     *
     * This wiring deliberately does not create the channel it cannot fill. The
     * object bound here is the half {@see \Pulsar\AI\Egress\GuardedAiClient} was
     * constructed with, so the composition root that builds the guard binds the
     * pair. Binding a fresh, unattached one instead would make every record say
     * `no_finding` — "a control examined this payload and found nothing" — in a
     * deployment that has no control at all, which is precisely the laundering
     * {@see EgressDecisionSinkInterface} warns about. Absence is reported as
     * absence.
     */
    private function egressSeam(ContainerInterface $container): ?EgressDecisionSourceInterface
    {
        if (!$container->has(EgressDecisionSourceInterface::class)) {
            return null;
        }

        /** @var EgressDecisionSourceInterface $existing */
        $existing = $container->get(EgressDecisionSourceInterface::class);

        return $existing;
    }
}
