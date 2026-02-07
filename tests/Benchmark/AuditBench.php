<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark;

use PhpBench\Attributes\Assert;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Subject;
use PhpBench\Attributes\Warmup;
use Pulsar\Security\Audit\AuditChainVerifier;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\Crypto\Hmac;
use Pulsar\Security\Crypto\KeyRingInterface;
use Pulsar\Tests\Benchmark\Support\InMemoryAuditSink;

#[BeforeMethods('setUp')]
#[Revs(1000)]
#[Iterations(5)]
#[Warmup(1)]
final class AuditBench
{
    private const string AUDIT_KEY = '0123456789abcdef0123456789abcdef';

    private AuditLogger $logger;

    private InMemoryAuditSink $sink;

    private AuditChainVerifier $verifier;

    /** @var list<AuditEntry> */
    private array $chainEntries;

    private string $seedHmac;

    public function setUp(): void
    {
        $this->sink = new InMemoryAuditSink();
        $this->logger = new AuditLogger(
            sink: $this->sink,
            auditKey: self::AUDIT_KEY,
        );

        $keyRing = new class (self::AUDIT_KEY) implements KeyRingInterface {
            public function __construct(private readonly string $key) {}

            public function keyFor(string $kid): string
            {
                return $this->key;
            }

            public function all(): iterable
            {
                return ['default' => $this->key];
            }
        };

        $this->verifier = new AuditChainVerifier($keyRing);
        $this->seedHmac = Hmac::computeHex('PULSAR_AUDIT_SEED', self::AUDIT_KEY);

        // Build a 128-entry chain for verification benchmark
        $chainSink = new InMemoryAuditSink();
        $chainLogger = new AuditLogger(
            sink: $chainSink,
            auditKey: self::AUDIT_KEY,
        );

        for ($i = 0; $i < 128; $i++) {
            $chainLogger->log(
                event: AuditEvent::DataAccess,
                outcome: AuditOutcome::Success,
                actor: 'bench-user',
                action: 'read',
                resource: '/api/data/' . $i,
                metadata: ['iteration' => $i],
            );
        }

        $this->chainEntries = $chainSink->entries();
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 500 microseconds')]
    public function benchLogWrite(): void
    {
        $this->sink->reset();

        $this->logger->log(
            event: AuditEvent::DataAccess,
            outcome: AuditOutcome::Success,
            actor: 'bench-user',
            action: 'read',
            resource: '/api/data',
            metadata: ['key' => 'value'],
        );
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 1 millisecond')]
    public function benchChainVerify128(): void
    {
        $result = $this->verifier->verifyChain($this->chainEntries, $this->seedHmac);
    }
}
