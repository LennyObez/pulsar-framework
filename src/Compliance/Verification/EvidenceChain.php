<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Verification;

use DateTimeImmutable;
use Fiber;
use JsonException;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Compliance\Evidence\EvidenceChainHead;
use Pulsar\Compliance\Evidence\EvidenceChainHeadAware;
use Pulsar\Compliance\Evidence\EvidenceChainStateAware;
use Pulsar\Compliance\Evidence\EvidenceRecord;
use Pulsar\Compliance\Evidence\EvidenceStoreInterface;
use Pulsar\Compliance\Evidence\EvidenceType;
use Pulsar\Security\Audit\AuditChainState;
use Pulsar\Security\Crypto\Hmac;
use Random\Engine\Secure;
use Random\Randomizer;
use SodiumException;

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function bin2hex;
use function count;
use function hash_equals;
use function implode;
use function is_int;
use function is_numeric;
use function is_string;
use function json_encode;
use function round;
use function sprintf;
use function strlen;
use function strval;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Continuous HMAC-chained evidence collection for compliance verification.
 *
 * Periodically runs verification checks and records results as tamper-evident
 * evidence records. Each record's signature chains to the previous record's
 * signature, and the chain's height is separately attested, so an auditor can be
 * told which of "intact", "modified", "truncated", "reordered", "unreadable" and
 * "written under a key nobody here holds" the register is.
 *
 * WHAT A HASH CHAIN PROVES, AND WHAT IT DOES NOT. Chaining each record to its
 * predecessor's signature proves that no record still PRESENT has been altered or
 * moved. It proves nothing about records removed from the END: delete the last
 * two lines of a linked list and what is left is a shorter, perfectly
 * self-consistent linked list, and every remaining link verifies. That is not a
 * bug in a particular verifier, it is the shape of the primitive, and this class
 * used to answer `valid: true` for exactly that file while two docblocks
 * elsewhere told an assessor truncation was detectable. Nor does a chain prove
 * anything about a record the READER never looks at: while the tail was selected
 * with a `forControl()` lookup, editing the one field that lookup matches on made
 * a record invisible to the verifier instead of visible to it.
 *
 * Three properties close those holes, and each one is a shape rather than a patch
 * for a symptom:
 *
 *  - EVERY RECORD CARRIES ITS POSITION, INSIDE THE SIGNATURE. `data.sequence` is
 *    covered by the HMAC, so a record cannot be moved, duplicated or renumbered
 *    without breaking it, and the verifier can say WHICH positions are missing
 *    rather than that something does not add up.
 *  - THE CHAIN'S HEIGHT IS ATTESTED OUT OF BAND. {@see EvidenceChainHead} is
 *    written beside the register after every append and signed under the same
 *    key. A register two records short of its head has been truncated, and no
 *    edit to the register alone can hide that. Its limits are written down on
 *    {@see EvidenceChainHead} rather than left to be discovered.
 *  - MEMBERSHIP IS ESTABLISHED BY THE SIGNATURE, NOT BY A LOOKUP. {@see verify()}
 *    reads the whole store and treats every record carrying this chain's marked
 *    data bag as one of ours. Altering `control_id` — or `id`, or `type`, or the
 *    description an auditor reads a verdict off — now breaks that record's HMAC
 *    and is reported as {@see EvidenceChainVerdict::Modified}, because those
 *    fields are inside the signature and nothing selects on them.
 *
 * VERIFICATION CONSULTS THE STORE, NOT ONLY THE LINKS. {@see verify()} takes no
 * record list. It asks the store whether its medium is fully readable, reads the
 * records itself, and reads the anchor. The method it replaces took a list from
 * its caller, which is why a register the store was reporting as corrupted could
 * be certified from whatever happened to decode.
 *
 * THE CHAIN RESUMES FROM THE STORE, through that same verification, so
 * {@see resume()} and {@see verify()} cannot reach two different opinions about
 * one file. Resumption bounds the recomputation to the tail — establishing that
 * the LAST record is ours is what appending needs, and re-walking the register on
 * every process start is the O(n) work {@see verify()} exists to do deliberately.
 * Everything the anchor and the positions establish is free of HMACs and is
 * therefore checked on every resume.
 *
 * The record format is versioned in `data.signature_version` and bound into the
 * signature. No release has ever written a record in any layout — the chain wrote
 * its first record in this release cycle — so a record without the marker is a
 * record this chain did not write, and {@see verify()} reports it as such rather
 * than guessing at an older format.
 *
 * ONE WRITER AT A TIME, across processes. Resumption reads the tail once per
 * process; two processes that resume from the same tail both append a record
 * claiming the same position, and {@see verify()} reports that as
 * {@see EvidenceChainVerdict::Reordered} — a position that appears twice — rather
 * than absorbing it. Within a process the cooperative mutex in {@see record()}
 * makes fibers serial, which is as far as a chain over a plain append-only store
 * can go: the same limit {@see \Pulsar\Security\Audit\AuditLogger} works within,
 * and a second, different answer to it here would be worse than the limit. The
 * scheduler runs one instance of {@see EvidenceCollectionJob} per tick, which is
 * what supplies the single writer.
 * @api
 */
#[Api(since: '1.0.0')]
final class EvidenceChain
{
    /**
     * Control id every record in this chain is filed under.
     *
     * A label, not a selector. {@see verify()} and {@see resume()} establish
     * membership from the signature, so an edit here is caught rather than
     * obeyed; what the id is still for is the interval question
     * {@see \Pulsar\Compliance\Verification\EvidenceCollectionJob} asks the store —
     * "when did this chain last write" — and a second literal spelling of it
     * would silently turn that question into "never".
     */
    public const string CONTROL_ID = 'compliance.verification_run';

    /**
     * Deterministic genesis label hashed (under the evidence key) to seed the
     * first record's {@see previousSignature}.
     */
    private const string GENESIS_SEED = 'PULSAR_EVIDENCE_SEED';

    /**
     * Version of the signed-message layout, carried in every record's `data` and
     * therefore inside the signature. A verifier that met a record from a layout
     * it does not implement would have to either guess or accept it; it does
     * neither.
     */
    private const int SIGNATURE_VERSION = 1;

    /** Version of the anchor layout, bound into {@see EvidenceChainHead::message()}. */
    private const int HEAD_VERSION = 1;

    /** Key under which each record carries the layout version. */
    private const string VERSION_KEY = 'signature_version';

    /**
     * Key under which each record carries {@see genesisSignature} — the HMAC of
     * the genesis label under the evidence key.
     *
     * It is a commitment to the key, computed the way the audit chain computes
     * its seed, and it is what lets a verifier tell "this record was signed under
     * the key in service and does not authenticate" from "this record was signed
     * under some other key". Publishing it reveals nothing: it is a keyed BLAKE2b
     * hash of a constant that is in this file.
     */
    private const string GENESIS_KEY = 'chain_genesis';

    /** Key under which each record carries its predecessor's signature. */
    private const string PREVIOUS_KEY = 'previous_signature';

    /**
     * How many ids or positions a summary sentence names before it says "and N
     * more". The finding's own list stays complete; only the prose is bounded.
     */
    private const int SUMMARY_LIST_LIMIT = 10;

    /**
     * Key under which each record carries its zero-based position in the chain.
     *
     * Inside the signature, which is the whole point: a position a tamperer can
     * rewrite is not a position. It is what turns "these records do not add up"
     * into "positions 7 and 8 are not in this register".
     */
    private const string SEQUENCE_KEY = 'sequence';

    private string $previousSignature;

    /**
     * Records this chain has written, as established by {@see resume()}. The
     * position the next record will carry.
     */
    private int $height = 0;

    /** HMAC of {@see GENESIS_SEED} under the evidence key; the chain's anchor. */
    private readonly string $genesisSignature;

    /**
     * Why appending is refused, or null when it is not. Meaningful only once
     * {@see ensureResumed()} has run.
     */
    private ?string $appendRefusal = null;

    private bool $resumed = false;

    /** Cooperative fiber mutex, for the reason {@see AuditLogger} holds one. */
    private bool $chainLocked = false;

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
        $this->genesisSignature = Hmac::computeHex(self::GENESIS_SEED, $this->evidenceKey);
        $this->previousSignature = $this->genesisSignature;
        $this->randomizer = $randomizer ?? new Randomizer(new Secure());
    }

    /**
     * Record a verification run result as a chained evidence entry.
     *
     * The record is written first and the anchor second. A crash between the two
     * leaves a register one record taller than its anchor, which {@see verify()}
     * reports as intact with a lagging anchor and {@see resume()} heals on the
     * next run — only the key holder can have produced that record. The other
     * order would leave an anchor one record taller than its register, which is
     * indistinguishable from a truncation, and would turn every crash into a
     * tamper alert.
     *
     * @throws SodiumException
     * @throws JsonException when the report's own counters cannot be encoded
     * @throws UnverifiableEvidenceChainException when the stored chain cannot be
     *         verified under the key in service — see {@see appendRefusal()}
     * @throws \Pulsar\Compliance\Evidence\EvidenceWriteFailedException when the
     *         record or the anchor cannot be persisted
     */
    public function record(VerificationReport $report): EvidenceRecord
    {
        // Cooperative mutex, exactly as AuditLogger holds one over its chain
        // advance: two fibers that read the same previousSignature write two
        // records claiming the same predecessor and the same position, and one of
        // them is broken forever after. Only suspend inside a Fiber; main-thread
        // calls are inherently serial. Held across the resume as well as the
        // append, because the resume is what sets the position they would share.
        while ($this->chainLocked && Fiber::getCurrent() !== null) {
            Fiber::suspend();
        }
        $this->chainLocked = true;

        try {
            $refusal = $this->appendRefusal();

            if ($refusal !== null) {
                throw UnverifiableEvidenceChainException::refusingToAppend($refusal);
            }

            $record = $this->sign($report, new DateTimeImmutable(), $this->height);

            $this->store->store($record);
            $this->previousSignature = (string) $record->signature;
            $this->height++;

            $this->anchor();

            return $record;
        } finally {
            $this->chainLocked = false;
        }
    }

    /**
     * Why this chain refuses to append, or null when it does not.
     *
     * Resolving this reads the store, so it is deliberately not done during
     * construction: the composition root builds the chain on every boot, which
     * under PHP-FPM is every request, and an evidence register grows without
     * bound. The read happens on the first append or the first question — both of
     * which are the scheduled collection run, once per process.
     *
     * @throws SodiumException
     * @throws \Pulsar\Compliance\Evidence\EvidenceWriteFailedException when the
     *         register is one record ahead of its anchor — the shape a crash between
     *         the two writes leaves — and the anchor cannot be brought up to date.
     *         Surfaced here rather than swallowed: the very next append would fail on
     *         the same write, and a register whose anchor has silently stopped being
     *         maintained keeps looking verifiable while losing the property the
     *         anchor provides.
     */
    #[NoDiscard]
    public function appendRefusal(): ?string
    {
        $this->ensureResumed();

        return $this->appendRefusal;
    }

    /**
     * Read the stored register and report what it is.
     *
     * Takes no records. The verifier reads the store, so a caller cannot hand it a
     * filtered list and be told the filtered list is fine — which is what happened
     * while the register's own store was reporting a line it could not read and
     * the report certified the records that did decode.
     *
     * @param int $limit How many of the most recent records to recompute the HMAC of.
     *        Zero or less means all of them. The bound is on CRYPTOGRAPHY only: the
     *        anchor, the positions and the linkage of every record are checked
     *        whatever the limit, because those cost no hashes. Both counts travel in
     *        the result, so a report can say "recomputed 500 of 40,000" rather than
     *        "verified".
     *
     * @throws SodiumException
     */
    #[NoDiscard]
    public function verify(int $limit = 0): EvidenceChainVerification
    {
        return $this->assess($limit);
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
            static fn(EvidenceRecord $r): bool => $r->type === EvidenceType::VerificationRun->value,
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
     * Get the current chain signature: the signature of the last record written,
     * or resumed from the store, or the genesis anchor when there is none.
     *
     * @throws SodiumException
     * @throws \Pulsar\Compliance\Evidence\EvidenceWriteFailedException when the
     *         register is one record ahead of its anchor — the shape a crash between
     *         the two writes leaves — and the anchor cannot be brought up to date.
     *         Surfaced here rather than swallowed: the very next append would fail on
     *         the same write, and a register whose anchor has silently stopped being
     *         maintained keeps looking verifiable while losing the property the
     *         anchor provides.
     */
    #[NoDiscard]
    public function currentSignature(): string
    {
        $this->ensureResumed();

        return $this->previousSignature;
    }

    /**
     * How many records this chain has written, as established from the store.
     *
     * The position the next record will carry, and the number the anchor attests.
     *
     * @throws SodiumException
     * @throws \Pulsar\Compliance\Evidence\EvidenceWriteFailedException when the
     *         register is one record ahead of its anchor — the shape a crash between
     *         the two writes leaves — and the anchor cannot be brought up to date.
     *         Surfaced here rather than swallowed: the very next append would fail on
     *         the same write, and a register whose anchor has silently stopped being
     *         maintained keeps looking verifiable while losing the property the
     *         anchor provides.
     */
    #[NoDiscard]
    public function height(): int
    {
        $this->ensureResumed();

        return $this->height;
    }

    /**
     * Read the store and decide what the register is.
     *
     * The order of the checks is the order in which findings stop being
     * meaningful. A medium with an unreadable line says nothing about the records
     * that did decode. A register whose height is not attested cannot be shown to
     * be complete however well its links hold. A register signed under another key
     * has no records this process can speak about at all. Only then is it worth
     * asking which positions are present, in what order, and whether each record
     * still authenticates.
     *
     * @throws SodiumException
     */
    private function assess(int $limit): EvidenceChainVerification
    {
        if (
            $this->store instanceof EvidenceChainStateAware
            && $this->store->chainState() === AuditChainState::Corrupted
        ) {
            return $this->finding(
                EvidenceChainVerdict::Unreadable,
                0,
                null,
                0,
                0,
                [],
                'the store holds a line it could not read. That is the shape a crash mid-append '
                    . 'leaves and the shape a record cut out with an editor leaves, and nothing '
                    . 'can be concluded about the records that did decode until an operator has '
                    . 'looked at the file.',
            );
        }

        $candidates = $this->candidates();
        $present = count($candidates);
        $head = null;

        if ($this->store instanceof EvidenceChainHeadAware) {
            $anchorFinding = $this->anchorFinding($candidates, $present);

            if ($anchorFinding !== null) {
                return $anchorFinding;
            }

            $head = $this->store->head();
        } elseif ($this->foreignKey($candidates, $present)) {
            return $this->keyUnavailable($present, null);
        }

        // Null only when the store keeps no anchor: anchorFinding() returns a
        // finding for every shape in which a head-keeping store's anchor is
        // absent or untrustworthy.
        $attested = $head?->height;

        if ($present === 0 && ($attested === null || $attested === 0)) {
            return $this->finding(
                EvidenceChainVerdict::Empty,
                0,
                $attested,
                0,
                0,
                [],
                'the register holds no records. An empty chain is not a verified one — it '
                    . 'proves nothing about the period it covers, because it covers none.',
            );
        }

        $structural = $this->structuralFinding($candidates, $present, $attested ?? 0);

        if ($structural !== null) {
            return $structural;
        }

        return $this->cryptographicFinding($candidates, $present, $head, $limit);
    }

    /**
     * Whether every record present was signed under some key other than the one
     * in service.
     *
     * All of them, not some. A register in which one record fails its key
     * commitment and the rest hold is a register with a foreign record in it,
     * which is a modification; a register in which none of them holds is a
     * register this process was never able to speak about.
     *
     * @param list<EvidenceRecord> $candidates
     */
    private function foreignKey(array $candidates, int $present): bool
    {
        if ($present === 0) {
            return false;
        }

        foreach ($candidates as $record) {
            /** @var mixed $genesis */
            $genesis = $record->data[self::GENESIS_KEY] ?? null;

            if (is_string($genesis) && hash_equals($this->genesisSignature, $genesis)) {
                return false;
            }
        }

        return true;
    }

    private function keyUnavailable(int $present, ?int $attested): EvidenceChainVerification
    {
        return $this->finding(
            EvidenceChainVerdict::KeyUnavailable,
            $present,
            $attested,
            0,
            0,
            [],
            sprintf(
                'all %d record(s) in the register were signed under a key this process does not '
                    . 'hold. A key rotation, a register restored from another deployment and a '
                    . 'forged register look identical from here, and no field in the file can '
                    . 'separate them, because whoever wrote the file wrote every field in it. Put '
                    . 'the key that signed them back in service, or account for where the register '
                    . 'came from; this is not a question about who edited the file.',
                $present,
            ),
        );
    }

    /**
     * The finding about the anchor, or null when the anchor can be trusted.
     *
     * @param list<EvidenceRecord> $candidates
     *
     * @throws SodiumException
     */
    private function anchorFinding(array $candidates, int $present): ?EvidenceChainVerification
    {
        /** @var EvidenceChainHeadAware $store */
        $store = $this->store;

        if (!$store->hasHead()) {
            if ($present === 0) {
                return $this->finding(
                    EvidenceChainVerdict::Empty,
                    0,
                    0,
                    0,
                    0,
                    [],
                    'the register holds no records and has no anchor, which is what a chain that '
                        . 'has never run looks like. An empty chain is not a verified one.',
                );
            }

            return $this->finding(
                EvidenceChainVerdict::Unreadable,
                $present,
                null,
                0,
                0,
                [],
                sprintf(
                    'the register holds %d record(s) and no anchor. Every append writes one, and '
                        . 'no release has ever written a register without one, so an anchor that '
                        . 'is not there was removed — which is exactly what someone truncating '
                        . 'the register would have to do next.',
                    $present,
                ),
            );
        }

        $head = $store->head();

        if ($head === null) {
            return $this->finding(
                EvidenceChainVerdict::Unreadable,
                $present,
                null,
                0,
                0,
                [],
                'the anchor is present and could not be decoded. A register whose height cannot '
                    . 'be read cannot be shown to be complete.',
            );
        }

        if ($head->version !== self::HEAD_VERSION) {
            return $this->finding(
                EvidenceChainVerdict::Unreadable,
                $present,
                null,
                0,
                0,
                [],
                sprintf(
                    'the anchor declares layout version %d and this verifier implements version '
                        . '%d. Guessing at a layout is how a verifier ends up certifying a shape '
                        . 'it does not understand.',
                    $head->version,
                    self::HEAD_VERSION,
                ),
            );
        }

        if (!hash_equals($this->genesisSignature, $head->genesis)) {
            return $this->finding(
                EvidenceChainVerdict::KeyUnavailable,
                $present,
                null,
                0,
                0,
                [],
                'the anchor was written under a key this process does not hold. A key rotation, '
                    . 'a register restored from another deployment and a forged register look '
                    . 'identical from here, and no field in the file can separate them, because '
                    . 'whoever wrote the file wrote every field in it.',
            );
        }

        if (!hash_equals(Hmac::computeHex($head->message(), $this->evidenceKey), $head->mac)) {
            return $this->finding(
                EvidenceChainVerdict::Unreadable,
                $present,
                null,
                0,
                0,
                [],
                'the anchor commits to the key in service and does not authenticate under it, so '
                    . 'it was altered after it was written. The height it states cannot be used.',
            );
        }

        if ($this->foreignKey($candidates, $present)) {
            return $this->keyUnavailable($present, $head->height);
        }

        return null;
    }

    /**
     * The finding about which positions are present and in what order, or null
     * when the register's shape is the one the anchor attests.
     *
     * Both checks are free of hashing, so they run whatever bound the caller put
     * on the cryptography. Truncation and reordering are established here, and
     * they are established for the WHOLE register rather than for a window.
     *
     * @param list<EvidenceRecord> $candidates
     */
    private function structuralFinding(
        array $candidates,
        int $present,
        int $attested,
    ): ?EvidenceChainVerification {
        $positions = [];
        $seen = [];

        foreach ($candidates as $record) {
            /** @var int $sequence a candidate carries an int position by construction */
            $sequence = $record->data[self::SEQUENCE_KEY];
            $positions[] = $sequence;
            $seen[$sequence] = true;
        }

        $missing = [];

        for ($position = 0; $position < $attested; $position++) {
            if (!isset($seen[$position])) {
                $missing[] = (string) $position;
            }
        }

        if ($missing !== []) {
            return $this->finding(
                EvidenceChainVerdict::Truncated,
                $present,
                $attested,
                0,
                0,
                $missing,
                sprintf(
                    'the anchor attests %d record(s) and %d of them are not in the register '
                        . '(position%s %s). The records that remain still chain to each other, '
                        . 'which is why nothing but the anchor could have told you this.',
                    $attested,
                    count($missing),
                    count($missing) === 1 ? '' : 's',
                    self::render($missing),
                ),
            );
        }

        $previous = -1;

        foreach ($positions as $position) {
            if ($position <= $previous) {
                return $this->finding(
                    EvidenceChainVerdict::Reordered,
                    $present,
                    $attested,
                    0,
                    0,
                    [(string) $position],
                    sprintf(
                        'the register holds the positions %s; this chain wrote them in ascending '
                            . 'order and signed each position into its record, so the order in the '
                            . 'file is not the order the chain wrote.',
                        self::render(array_map(strval(...), $positions)),
                    ),
                );
            }

            $previous = $position;
        }

        return null;
    }

    /**
     * Recompute signatures and linkage, and compare the tail against the anchor.
     *
     * @param list<EvidenceRecord> $candidates
     *
     * @throws SodiumException
     */
    private function cryptographicFinding(
        array $candidates,
        int $present,
        ?EvidenceChainHead $head,
        int $limit,
    ): EvidenceChainVerification {
        $examineFrom = $limit > 0 && $present > $limit ? $present - $limit : 0;

        $verified = 0;
        $examined = 0;
        $broken = [];
        $expectedPrevious = $this->genesisSignature;

        foreach ($candidates as $index => $record) {
            if ($index >= $examineFrom) {
                $examined++;

                if ($this->authentic($record, $expectedPrevious)) {
                    $verified++;
                } else {
                    $broken[] = $record->id;
                }
            }

            // Advance to this record's actual signature whether or not it was
            // examined, so a bounded run anchors its first record to the real
            // predecessor. This is what the caller-supplied window anchor used to
            // be for, and a caller that got it wrong was told its own evidence was
            // broken.
            $expectedPrevious = $record->signature ?? '';
        }

        if ($broken !== []) {
            return $this->finding(
                EvidenceChainVerdict::Modified,
                $present,
                $head?->height,
                $verified,
                $examined,
                $broken,
                sprintf(
                    '%d of the %d record(s) examined did not authenticate under the key in '
                        . 'service or did not chain to the record before it: %s. Every field an '
                        . 'auditor reads a verdict off is inside the signature, so this is a '
                        . 'record that was rewritten where it lies.',
                    count($broken),
                    $examined,
                    self::render($broken),
                ),
            );
        }

        if ($head === null) {
            // Every check this store CAN answer has been answered and nothing is
            // wrong with the records present. What is missing is the one property
            // a hash chain cannot supply for itself, so the verdict says so rather
            // than calling the register intact on the strength of a check that
            // could not run.
            return $this->finding(
                EvidenceChainVerdict::Unanchored,
                $present,
                null,
                $verified,
                $examined,
                [],
                sprintf(
                    'no fault was found in the %d record(s) present — %d signature(s) were '
                        . 'recomputed and held — and the store in service (%s) cannot state how '
                        . 'many records this chain has written, so nothing establishes that they '
                        . 'are all of them. Records removed from the END of such a register leave '
                        . 'a shorter register in which every remaining link still verifies.',
                    $present,
                    $verified,
                    $this->store::class,
                ),
            );
        }

        $attested = $head->height;

        $tailFinding = $this->tailAgreesWithAnchor($candidates, $present, $head, $verified, $examined);

        if ($tailFinding !== null) {
            return $tailFinding;
        }

        if ($present > $attested) {
            return $this->finding(
                EvidenceChainVerdict::Intact,
                $present,
                $attested,
                $verified,
                $examined,
                [],
                sprintf(
                    'all %d record(s) hold, and the register is %d record(s) ahead of its anchor '
                        . '— the shape a crash between the record write and the anchor write '
                        . 'leaves. Only the key holder could have produced those records, so they '
                        . 'are this chain\'s; the next append re-anchors.',
                    $present,
                    $present - $attested,
                ),
            );
        }

        return $this->finding(
            EvidenceChainVerdict::Intact,
            $present,
            $attested,
            $verified,
            $examined,
            [],
            sprintf(
                'the anchor attests %d record(s), %d are present in the order the chain wrote '
                    . 'them, and the signature and linkage of %d of them were recomputed and held.',
                $attested,
                $present,
                $verified,
            ),
        );
    }

    /**
     * Whether the record the anchor names as the tail is the record sitting at
     * that position.
     *
     * Both would authenticate — only the key holder can produce either — so this
     * is not caught by recomputing signatures. What it catches is a genuine record
     * swapped for another genuine record at the same position, and an anchor that
     * names a tail the register does not have.
     *
     * @param list<EvidenceRecord> $candidates
     */
    private function tailAgreesWithAnchor(
        array $candidates,
        int $present,
        EvidenceChainHead $head,
        int $verified,
        int $examined,
    ): ?EvidenceChainVerification {
        if ($head->height === 0) {
            return null;
        }

        $attestedPosition = $head->height - 1;

        foreach ($candidates as $record) {
            if (($record->data[self::SEQUENCE_KEY] ?? null) !== $attestedPosition) {
                continue;
            }

            if (hash_equals($head->signature, (string) $record->signature)) {
                return null;
            }

            return $this->finding(
                EvidenceChainVerdict::Modified,
                $present,
                $head->height,
                $verified,
                $examined,
                [$record->id],
                sprintf(
                    'the anchor names a different record at position %d than the one the '
                        . 'register holds there. Both are signed under the key in service, so a '
                        . 'record was replaced by another record rather than edited.',
                    $attestedPosition,
                ),
            );
        }

        // structuralFinding() has already established that every attested position
        // is present, so this is unreachable through assess(); returning the honest
        // finding rather than asserting keeps the method total.
        return $this->finding(
            EvidenceChainVerdict::Truncated,
            $present,
            $head->height,
            $verified,
            $examined,
            [(string) $attestedPosition],
            sprintf('the register holds no record at the position the anchor names (%d).', $attestedPosition),
        );
    }

    /**
     * As much of a list as belongs in a sentence.
     *
     * An evidence register grows without bound, so "positions 0, 1, 2, ..." over a
     * deleted forty-thousand-record register would build a megabyte-long string
     * for a human to read. The full list still travels in
     * {@see EvidenceChainVerification::$brokenAt}, which is data rather than
     * prose; only the rendering is bounded.
     *
     * @param list<string> $values
     */
    private static function render(array $values): string
    {
        if (count($values) <= self::SUMMARY_LIST_LIMIT) {
            return implode(', ', $values);
        }

        return implode(', ', array_slice($values, 0, self::SUMMARY_LIST_LIMIT))
            . sprintf(' and %d more', count($values) - self::SUMMARY_LIST_LIMIT);
    }

    /**
     * @param list<string> $brokenAt
     */
    private function finding(
        EvidenceChainVerdict $verdict,
        int $present,
        ?int $attested,
        int $verified,
        int $examined,
        array $brokenAt,
        string $summary,
    ): EvidenceChainVerification {
        return new EvidenceChainVerification(
            verdict: $verdict,
            present: $present,
            attested: $attested,
            verified: $verified,
            examined: $examined,
            brokenAt: $brokenAt,
            summary: $summary,
        );
    }

    /**
     * The records in the store that carry this chain's data bag, in the order the
     * store holds them.
     *
     * Selected by SHAPE rather than by control id. An application writing its own
     * evidence through {@see EvidenceStoreInterface} does not carry these keys and
     * is not part of this chain; a record whose `control_id` was edited still
     * does, so it is verified and reported instead of quietly dropping out of the
     * lookup — which is what happened while the tail was fetched with
     * `forControl()` and is the reason the reader no longer selects on any field
     * a tamperer can rewrite.
     *
     * The genesis commitment is NOT required here. A record signed under another
     * key is still a candidate, so a register written under a key nobody holds is
     * reported as exactly that rather than as an empty register.
     *
     * @return list<EvidenceRecord>
     */
    private function candidates(): array
    {
        return array_values(array_filter(
            $this->store->all(),
            static function (EvidenceRecord $record): bool {
                /** @var mixed $sequence */
                $sequence = $record->data[self::SEQUENCE_KEY] ?? null;

                return is_int($record->data[self::VERSION_KEY] ?? null)
                    && is_string($record->data[self::GENESIS_KEY] ?? null)
                    && is_string($record->data[self::PREVIOUS_KEY] ?? null)
                    && is_int($sequence)
                    && $sequence >= 0;
            },
        ));
    }

    /**
     * Build and sign the record for a report. Separated from {@see record()} so
     * the message the signature covers is written down once and read by
     * {@see authentic()} through {@see messageFor()}.
     *
     * @throws SodiumException
     * @throws JsonException
     */
    private function sign(VerificationReport $report, DateTimeImmutable $collectedAt, int $sequence): EvidenceRecord
    {
        $data = [
            self::VERSION_KEY => self::SIGNATURE_VERSION,
            self::GENESIS_KEY => $this->genesisSignature,
            self::SEQUENCE_KEY => $sequence,
            self::PREVIOUS_KEY => $this->previousSignature,
            'pass_count' => $report->passCount(),
            'fail_count' => $report->failCount(),
            'skip_count' => $report->skipCount(),
            'pass_rate' => $report->passRate(),
            'conflict_count' => count($report->conflicts),
            'regression_count' => count($report->regressions),
        ];

        $unsigned = new EvidenceRecord(
            id: bin2hex($this->randomizer->getBytes(16)),
            controlId: self::CONTROL_ID,
            type: EvidenceType::VerificationRun->value,
            description: sprintf(
                'Compliance verification: %d/%d checks passed (%.1f%% pass rate)',
                $report->passCount(),
                $report->passCount() + $report->failCount(),
                $report->passRate(),
            ),
            data: $data,
            collectedAt: $collectedAt,
            signature: null,
        );

        // Signed from the record itself, through the same messageFor() the
        // verifier calls. A second, hand-rolled field list on the writing side is
        // how a signature ends up covering less than the reader believes.
        //
        // Rebuilt rather than clone-with: EvidenceRecord's properties are
        // readonly and a clone-with from outside the declaring scope is a fatal
        // error, so the fields are carried across explicitly.
        return new EvidenceRecord(
            id: $unsigned->id,
            controlId: $unsigned->controlId,
            type: $unsigned->type,
            description: $unsigned->description,
            data: $unsigned->data,
            collectedAt: $unsigned->collectedAt,
            signature: Hmac::computeHex($this->messageFor($unsigned), $this->evidenceKey),
        );
    }

    /**
     * Write the anchor for the chain's current height and tail signature.
     *
     * @throws SodiumException
     * @throws \Pulsar\Compliance\Evidence\EvidenceWriteFailedException when the
     *         anchor cannot be persisted. Not swallowed: a register whose anchor
     *         silently stopped being maintained keeps looking verifiable while
     *         losing the only property that makes truncation visible.
     */
    private function anchor(): void
    {
        if (!$this->store instanceof EvidenceChainHeadAware) {
            return;
        }

        $unsigned = new EvidenceChainHead(
            version: self::HEAD_VERSION,
            genesis: $this->genesisSignature,
            height: $this->height,
            signature: $this->previousSignature,
            updatedAt: new DateTimeImmutable(),
            mac: '',
        );

        $this->store->writeHead(new EvidenceChainHead(
            version: $unsigned->version,
            genesis: $unsigned->genesis,
            height: $unsigned->height,
            signature: $unsigned->signature,
            updatedAt: $unsigned->updatedAt,
            mac: Hmac::computeHex($unsigned->message(), $this->evidenceKey),
        ));
    }

    /**
     * The message a record's signature covers: every field an auditor reads a
     * verdict off, plus the data bag that carries the version, the key
     * commitment, the position and the linkage.
     *
     * Length-prefixed per field and newline-joined, the encoding
     * {@see \Pulsar\Security\Audit\AuditEntry} uses for the audit chain and for
     * the same reason: without it, a description ending in a colon and a type
     * beginning with one produce the same concatenation as their neighbours
     * shifted by a character, and two different records can share a signature.
     *
     * The timestamp is formatted as ATOM because that is the form
     * {@see \Pulsar\Compliance\Evidence\FileEvidenceStore} writes and reads back;
     * signing a microsecond-precision rendering would produce a signature that
     * stops verifying the moment the record is reloaded, which is the same
     * false-tamper report this class was fixed to stop making.
     *
     * @throws JsonException when the data bag is not encodable — a tampered file
     *         can contain byte sequences that are not valid UTF-8, and the caller
     *         treats that as a failed verification rather than letting it escape
     */
    private function messageFor(EvidenceRecord $record): string
    {
        $data = json_encode(
            $record->data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        $parts = [];

        foreach ([
            $record->id,
            $record->controlId,
            $record->type,
            $record->description,
            $record->collectedAt->format(DateTimeImmutable::ATOM),
            $data,
        ] as $field) {
            $parts[] = strlen($field) . ':' . $field;
        }

        return implode("\n", $parts);
    }

    /**
     * Whether a record is this chain's, intact, and chained to $expectedPrevious.
     *
     * @throws SodiumException
     */
    private function authentic(EvidenceRecord $record, string $expectedPrevious): bool
    {
        /** @var mixed $recordedPrevious */
        $recordedPrevious = $record->data[self::PREVIOUS_KEY] ?? null;

        if (!is_string($recordedPrevious) || !hash_equals($expectedPrevious, $recordedPrevious)) {
            return false;
        }

        return $this->intact($record);
    }

    /**
     * Whether a record was written by this chain under this key and has not been
     * altered since — its version marker, its key commitment and its signature.
     *
     * Says nothing about WHERE in the chain it belongs; that is the linkage check
     * in {@see authentic()} and the position check in {@see structuralFinding()}.
     * {@see resume()} needs exactly this half for the tail.
     *
     * @throws SodiumException
     */
    private function intact(EvidenceRecord $record): bool
    {
        if ($record->signature === null) {
            return false;
        }

        if (($record->data[self::VERSION_KEY] ?? null) !== self::SIGNATURE_VERSION) {
            return false;
        }

        /** @var mixed $genesis */
        $genesis = $record->data[self::GENESIS_KEY] ?? null;

        if (!is_string($genesis) || !hash_equals($this->genesisSignature, $genesis)) {
            return false;
        }

        try {
            $message = $this->messageFor($record);
        } catch (JsonException) {
            return false;
        }

        return hash_equals(Hmac::computeHex($message, $this->evidenceKey), $record->signature);
    }

    /**
     * Resume once per instance, on first use rather than on construction.
     *
     * The flag is set AFTER the resume, not before: a store that suspends the
     * fiber mid-read would otherwise let a second fiber past a flag whose
     * `previousSignature` had not been assigned yet, and it would chain to
     * genesis over a live chain. Re-running the resume is idempotent; observing
     * a half-finished one is not.
     *
     * @throws SodiumException
     * @throws \Pulsar\Compliance\Evidence\EvidenceWriteFailedException when a
     *         register found one record ahead of its anchor cannot have the anchor
     *         brought up to date
     */
    private function ensureResumed(): void
    {
        if ($this->resumed) {
            return;
        }

        $this->appendRefusal = $this->resume();
        $this->resumed = true;
    }

    /**
     * Continue the stored chain, or say why this chain will not append to it.
     *
     * The decision is {@see assess()}'s, bounded to the tail. That is deliberate
     * and it is the point: a codebase in which the resumer and the verifier reach
     * their own opinions about one file has a defect in whichever opinion is the
     * weaker, and the defect this class was carrying was exactly that —
     * {@see resume()} consulted the store's state and the verifier did not, so the
     * chain refused to append to a register the report was certifying.
     *
     * The bound is on hashing only. Whether the register is fully readable,
     * whether its height is attested, whether it is signed under the key in
     * service, which positions are present and in what order — all of that is
     * established for the WHOLE register on every resume, because none of it costs
     * a hash. Only the recomputation of individual signatures is limited to the
     * tail, and establishing that the LAST record is ours is what appending needs:
     * a record altered deep in the register stays broken forever after, and
     * appending on top of it does not bury it.
     *
     * WHAT HAPPENS WHEN THE REGISTER DOES NOT VERIFY, and why:
     *
     * A key rotation, a restore from someone else's backup and a genuine tamper
     * are indistinguishable from inside this process for the records themselves,
     * and no field in the file can distinguish them, because whoever wrote the
     * file wrote every field in it. {@see GENESIS_KEY} narrows the hypothesis and
     * {@see EvidenceChainVerdict::KeyUnavailable} names the narrowed one — but it
     * narrows it only for an honest file; an attacker writes whatever commitment
     * makes their story fit. So this class does not adjudicate. It refuses to
     * append, states what it found in the verifier's own words, and leaves the
     * judgement to the operator, who knows things the process does not: whether
     * the key was rotated, whether the register was restored, whether the host was
     * breached.
     *
     * The refusal is deliberate and it is the whole point. Appending would chain a
     * genuine record onto an unauthenticated one and every subsequent record would
     * verify, burying the discontinuity mid-file. Re-seeding from genesis would
     * produce a short, valid chain that says nothing about the records it
     * replaced. Both hand an auditor a chain that verifies over a period in which
     * the evidence did not. Recovery is an operator act with a paper trail:
     * archive the register AND its anchor together (the chain becomes empty, and a
     * new one starts from genesis, with the old pair preserved as the artifact it
     * is), or put the key that signed it back in service. That is the same
     * posture, and the same wording, as
     * {@see \Pulsar\Security\Exception\SecurityException::auditChainCorrupted()}.
     *
     * The cost of refusing is that a tamper stops evidence collection until an
     * operator looks. A crash does not: a register one record ahead of its anchor
     * is reported intact and re-anchored by the next append, because only the key
     * holder could have written that record.
     *
     * @return string|null The reason to refuse, or null when the chain resumed
     *
     * @throws SodiumException
     * @throws \Pulsar\Compliance\Evidence\EvidenceWriteFailedException when a
     *         register found one record ahead of its anchor cannot have the anchor
     *         brought up to date
     */
    private function resume(): ?string
    {
        $verification = $this->assess(limit: 1);

        if ($verification->verdict === EvidenceChainVerdict::Empty) {
            $this->previousSignature = $this->genesisSignature;
            $this->height = 0;

            return null;
        }

        // Unanchored is not admissible EVIDENCE and is not a reason to stop
        // collecting it. Such a store never had the completeness guarantee, so
        // refusing to append would take a working deployment offline over a
        // property it was never offered; what it gets instead is the verdict, on
        // every report, saying that removal from the end was not checked. A
        // register with an actual fault in it — modified, truncated, reordered,
        // unreadable, foreign — is refused, because appending onto one buries the
        // discontinuity mid-file.
        if (
            !$verification->admissible()
            && $verification->verdict !== EvidenceChainVerdict::Unanchored
        ) {
            return $verification->summary;
        }

        $tail = $this->tail();

        if ($tail === null || $tail->signature === null) {
            // assess() reported Intact, which requires a candidate at every
            // attested position and a non-null signature on each examined record.
            // Refusing rather than seeding keeps the impossible case fail-closed.
            return 'the register verified and then produced no tail, which means it changed '
                . 'underneath this process while it was being read.';
        }

        $this->previousSignature = $tail->signature;
        $this->height = $verification->present;

        if ($verification->attested !== null && $verification->attested < $verification->present) {
            // The anchor lags a crash-interrupted append. Healing it here rather
            // than waiting for the next record keeps a register that is read more
            // often than it is written from reporting a lagging anchor forever.
            $this->anchor();
        }

        return null;
    }

    /**
     * The record at the highest signed position, or null when there is none.
     *
     * By position rather than by file order or by control id: the position is
     * inside the signature and the other two are not.
     */
    private function tail(): ?EvidenceRecord
    {
        $tail = null;
        $highest = -1;

        foreach ($this->candidates() as $record) {
            /** @var int $sequence a candidate carries an int position by construction */
            $sequence = $record->data[self::SEQUENCE_KEY];

            if ($sequence > $highest) {
                $highest = $sequence;
                $tail = $record;
            }
        }

        return $tail;
    }
}
