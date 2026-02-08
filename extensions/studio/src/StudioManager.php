<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio;

use Closure;

use function hash;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

use JsonException;
use Pulsar\Api\Internal;
use Pulsar\Observability\Context\CorrelationContext;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\EventEnvelope;
use Pulsar\Extension\Studio\Console\Event\EventFactory;
use Pulsar\Extension\Studio\Console\Redaction\RedactionPipeline;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Extension\Studio\Console\Storage\SqliteEventStore;
use Pulsar\Tenancy\TenantContext;
use Random\Engine\Secure;
use Random\RandomException;
use Random\Randomizer;
use Throwable;

/**
 * Central orchestrator for Studio event ingestion.
 *
 * Coordinates the pipeline: redact → serialize → hash → store+chain.
 * Resolves tenant hash at ingest time via TenantContext::tryGet().
 * Applies sampling rate to control event volume.
 */
#[Internal]
final readonly class StudioManager
{
    private Randomizer $randomizer;

    public function __construct(
        private EventStoreInterface $store,
        private EventFactory $eventFactory,
        private RedactionPipeline $redactionPipeline,
        private ?TenantContext $tenantContext = null,
        private ?string $chainMacKey = null,
        private float $samplingRate = 1.0,
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer(new Secure());
    }

    /**
     * Ingest a console event through the full pipeline.
     *
     * Pipeline: collect → resolve tenant → redact → serialize → hash → store+chain.
     */
    public function ingest(ConsoleEvent $event, ?CorrelationContext $context = null): void
    {
        try {
            // Sampling: skip event based on configured rate
            if ($this->samplingRate < 1.0 && !$this->shouldSample()) {
                return;
            }

            $this->doIngest($event, $context);
        } catch (Throwable) {
            // Ingest failures are silently swallowed — Studio must never crash the app
        }
    }

    /**
     * Create an emit callback for collectors.
     *
     * @return Closure(ConsoleEvent, ?CorrelationContext): void
     */
    public function emitCallback(): Closure
    {
        return $this->ingest(...);
    }

    /**
     * Get the underlying event store.
     */
    public function store(): EventStoreInterface
    {
        return $this->store;
    }

    /**
     * @throws JsonException
     * @throws RandomException
     */
    private function doIngest(ConsoleEvent $event, ?CorrelationContext $context): void
    {
        // 1. Create envelope (includes raw payload + payload hash)
        $envelope = $this->eventFactory->envelope($event, $context);

        // 2. Redact the payload
        $redactedPayload = $this->redactionPipeline->redact(
            $envelope->payload,
            $envelope->eventType,
        );

        // 3. Serialize redacted payload
        $payloadJson = json_encode(
            $redactedPayload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        // 4. Recompute payload hash from redacted content
        $payloadHash = hash('sha256', $payloadJson);

        // 5. Create a new envelope with the redacted payload and updated hash
        $envelope = new EventEnvelope(
            eventId: $envelope->eventId,
            eventType: $envelope->eventType,
            schemaVersion: $envelope->schemaVersion,
            timestampUs: $envelope->timestampUs,
            requestId: $envelope->requestId,
            traceId: $envelope->traceId,
            spanId: $envelope->spanId,
            jobId: $envelope->jobId,
            appEnv: $envelope->appEnv,
            hostname: $envelope->hostname,
            payload: $redactedPayload,
            payloadHash: $payloadHash,
        );

        // 6. Resolve tenant hash
        $tenantHash = $this->resolveTenantHash();

        // 7. Store with chain
        if ($this->store instanceof SqliteEventStore) {
            $this->store->storeWithChain($envelope, $payloadJson, $tenantHash, $this->chainMacKey);
        } else {
            // EncryptedEventStore or other decorator — use storeWithChain if available
            $store = $this->store;

            if (method_exists($store, 'storeWithChain')) {
                $store->storeWithChain($envelope, $payloadJson, $tenantHash, $this->chainMacKey);
            } else {
                $store->store($envelope, $payloadJson, $tenantHash);
            }
        }
    }

    private function resolveTenantHash(): ?string
    {
        if ($this->tenantContext === null) {
            return null;
        }

        $tenant = $this->tenantContext->tryGet();

        if ($tenant === null) {
            return null;
        }

        return hash('sha256', $tenant->id);
    }

    /**
     * @throws RandomException
     */
    private function shouldSample(): bool
    {
        // samplingRate 1.0 = 100%, 0.1 = 10%
        return (float) $this->randomizer->getInt(1, 10000) <= $this->samplingRate * 10000.0;
    }
}
