<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Verification;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Compliance\Evidence\EvidenceRecord;
use Pulsar\Compliance\Evidence\EvidenceStoreInterface;
use Pulsar\Security\Crypto\Hmac;
use Random\Engine\Secure;
use Random\Randomizer;
use SodiumException;

use function array_filter;
use function array_values;
use function bin2hex;
use function count;
use function json_encode;
use function round;
use function sprintf;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Continuous HMAC-chained evidence collection for compliance verification.
 *
 * Periodically runs verification checks and records results as tamper-evident
 * evidence records. Each record's signature chains to the previous record's
 * signature, creating a provable sequence of compliance state.
 * @api
 */
#[Api(since: '1.0.0')]
final class EvidenceChain
{
    private string $previousSignature;

    private readonly Randomizer $randomizer;

    /**
     * @param string $evidenceKey Key for HMAC computation (from master key derivation)
     *
     * @throws SodiumException
     */
    public function __construct(
        private readonly EvidenceStoreInterface $store,
        private readonly string $evidenceKey,
        ?Randomizer $randomizer = null,
    ) {
        $this->previousSignature = Hmac::computeHex('PULSAR_EVIDENCE_SEED', $this->evidenceKey);
        $this->randomizer = $randomizer ?? new Randomizer(new Secure());
    }

    /**
     * Record a verification run result as a chained evidence entry.
     *
     * @throws SodiumException
     */
    public function record(VerificationReport $report): EvidenceRecord
    {
        $data = [
            'pass_count' => $report->passCount(),
            'fail_count' => $report->failCount(),
            'skip_count' => $report->skipCount(),
            'pass_rate' => $report->passRate(),
            'conflict_count' => count($report->conflicts),
            'regression_count' => count($report->regressions),
            'previous_signature' => $this->previousSignature,
        ];

        $message = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $signature = Hmac::computeHex($message, $this->evidenceKey);

        $record = new EvidenceRecord(
            id: bin2hex($this->randomizer->getBytes(16)),
            controlId: 'compliance.verification_run',
            type: 'verification_evidence',
            description: sprintf(
                'Compliance verification: %d/%d checks passed (%.1f%% pass rate)',
                $report->passCount(),
                $report->passCount() + $report->failCount(),
                $report->passRate(),
            ),
            data: $data,
            collectedAt: new DateTimeImmutable(),
            signature: $signature,
        );

        $this->store->store($record);
        $this->previousSignature = $signature;

        return $record;
    }

    /**
     * Verify the integrity of an evidence chain.
     *
     * @param list<EvidenceRecord> $records Ordered records (oldest first)
     *
     * @return array{valid: bool, verified: int, broken_at: list<string>}
     *
     * @throws SodiumException
     */
    #[NoDiscard]
    public function verifyChain(array $records): array
    {
        $verified = 0;
        $brokenAt = [];

        foreach ($records as $record) {
            if ($record->signature === null) {
                $brokenAt[] = $record->id;

                continue;
            }

            $data = $record->data;
            $recordMessage = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $expectedSignature = Hmac::computeHex($recordMessage, $this->evidenceKey);

            if (hash_equals($expectedSignature, $record->signature)) {
                $verified++;
            } else {
                $brokenAt[] = $record->id;
            }
        }

        return [
            'valid' => $brokenAt === [],
            'verified' => $verified,
            'broken_at' => $brokenAt,
        ];
    }

    /**
     * Generate a summary for a time period.
     *
     * @param list<EvidenceRecord> $records Records within the time period
     *
     * @return array{from: string, to: string, run_count: int, average_pass_rate: float}
     */
    #[NoDiscard]
    public function periodSummary(array $records): array
    {
        $verificationRecords = array_values(array_filter(
            $records,
            static fn(EvidenceRecord $r): bool => $r->type === 'verification_evidence',
        ));

        if ($verificationRecords === []) {
            return [
                'from' => '',
                'to' => '',
                'run_count' => 0,
                'average_pass_rate' => 0.0,
            ];
        }

        $totalPassRate = 0.0;

        foreach ($verificationRecords as $record) {
            /** @var mixed $rawRate */
            $rawRate = $record->data['pass_rate'] ?? 0.0;
            $totalPassRate += is_numeric($rawRate) ? (float) $rawRate : 0.0;
        }

        $first = $verificationRecords[0];
        $last = $verificationRecords[count($verificationRecords) - 1];

        return [
            'from' => $first->collectedAt->format('Y-m-d\TH:i:sP'),
            'to' => $last->collectedAt->format('Y-m-d\TH:i:sP'),
            'run_count' => count($verificationRecords),
            'average_pass_rate' => round($totalPassRate / (float) count($verificationRecords), 2),
        ];
    }

    /**
     * Get the current chain signature.
     */
    #[NoDiscard]
    public function currentSignature(): string
    {
        return $this->previousSignature;
    }
}
