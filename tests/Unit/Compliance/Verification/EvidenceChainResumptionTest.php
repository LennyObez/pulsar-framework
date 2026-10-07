<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Verification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Evidence\FileEvidenceStore;
use Pulsar\Compliance\Verification\CheckResult;
use Pulsar\Compliance\Verification\ComplianceCheckDomain;
use Pulsar\Compliance\Verification\EvidenceChain;
use Pulsar\Compliance\Verification\EvidenceChainVerdict;
use Pulsar\Compliance\Verification\UnverifiableEvidenceChainException;
use Pulsar\Compliance\Verification\VerificationReport;

use function bin2hex;
use function count;
use function dirname;
use function explode;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function filesize;
use function implode;
use function is_dir;
use function is_file;
use function json_decode;
use function json_encode;
use function mkdir;
use function proc_close;
use function proc_open;
use function random_bytes;
use function rmdir;
use function scandir;
use function stream_get_contents;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function trim;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PHP_BINARY;

/**
 * The evidence chain read as an attacker would read it: over a real file, across
 * real process boundaries, with the file edited underneath it.
 *
 * These are the properties {@see EvidenceChainTest} cannot establish, because
 * they are exactly the ones an in-memory store hides. The chain used to seed
 * itself from genesis in every constructor, so from the second scheduled run
 * onward the deployment reported its OWN evidence as tampered — a defect no test
 * over a store that dies with the process could see.
 */
#[CoversClass(EvidenceChain::class)]
final class EvidenceChainResumptionTest extends TestCase
{
    private const string KEY = 'evidence-key-long-enough-for-blake2b-keying';
    private const string OTHER_KEY = 'a-completely-different-evidence-key-of-length';

    private string $dir = '';
    private string $path = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_chain_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o755, true);
        $this->path = $this->dir . DIRECTORY_SEPARATOR . 'compliance-evidence.jsonl';
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            foreach (scandir($this->dir) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..' && is_file($this->dir . DIRECTORY_SEPARATOR . $entry)) {
                    unlink($this->dir . DIRECTORY_SEPARATOR . $entry);
                }
            }

            rmdir($this->dir);
        }
    }

    #[Test]
    public function aSecondChainOverTheSameStoreContinuesTheFirstInsteadOfRestartingIt(): void
    {
        $first = $this->chain();
        $r1 = $first->record($this->report(passes: 3));

        // A separate instance over the same file is what every scheduler tick is.
        $second = $this->chain();
        $r2 = $second->record($this->report(passes: 4));

        self::assertSame($r1->signature, $r2->data['previous_signature']);

        $stored = new FileEvidenceStore($this->path)->all();

        self::assertCount(2, $stored);

        $result = $this->chain()->verify();

        self::assertSame(EvidenceChainVerdict::Intact, $result->verdict);
        self::assertSame(2, $result->present);
        self::assertSame(2, $result->attested);
        self::assertSame(2, $result->verified);
        self::assertSame([], $result->brokenAt);
    }

    #[Test]
    public function theCollectionJobRunTwiceInSeparateProcessesLeavesAVerifiableChain(): void
    {
        // The defect this test exists for: `scheduler:tick` is a fresh process
        // every time, and a chain that seeds from genesis in its constructor
        // writes a second record claiming the same predecessor as the first. The
        // job is run for real, twice, in two processes that share nothing but the
        // file.
        $this->runCollectionJobInASeparateProcess();
        $this->runCollectionJobInASeparateProcess();

        $stored = new FileEvidenceStore($this->path)->all();

        self::assertCount(2, $stored, 'each run must append exactly one record');
        self::assertNotSame($stored[0]->id, $stored[1]->id);
        self::assertSame($stored[0]->signature, $stored[1]->data['previous_signature']);

        $result = $this->chain()->verify();

        self::assertSame(
            EvidenceChainVerdict::Intact,
            $result->verdict,
            'the chain written across two processes must verify',
        );
        self::assertSame(2, $result->verified);
        self::assertSame(2, $result->attested, 'each process re-anchored after its own append');
    }

    #[Test]
    public function aStoreTruncatedMidRecordIsDetectedAndRefused(): void
    {
        $this->chain()->record($this->report(passes: 2));
        $this->chain()->record($this->report(passes: 5));

        // A crash mid-append, or a record cut out by hand: the last line stops
        // partway through. FileEvidenceStore skips what it cannot decode, so
        // without a state contract the chain would resume from the record BEFORE
        // the truncation and close the gap on the next append.
        $contents = (string) file_get_contents($this->path);
        file_put_contents($this->path, substr($contents, 0, strlen($contents) - 40));

        $chain = $this->chain();
        $refusal = $chain->appendRefusal();

        self::assertNotNull($refusal);
        self::assertStringContainsString('could not read', $refusal);
        self::assertSame(EvidenceChainVerdict::Unreadable, $this->chain()->verify()->verdict);

        $sizeBefore = filesize($this->path);

        try {
            $chain->record($this->report());
            self::fail('the chain must refuse to append to a register it cannot fully read');
        } catch (UnverifiableEvidenceChainException $refused) {
            self::assertStringContainsString('Refusing to append', $refused->getMessage());
        }

        self::assertSame($sizeBefore, filesize($this->path), 'a refused append must write nothing');
    }

    #[Test]
    public function aRewrittenDescriptionIsRefusedAndReportedAsBroken(): void
    {
        $this->chain()->record($this->report(passes: 1, fails: 4));

        // The forgery the old signature allowed outright: `description` is the
        // sentence an auditor reads, and it sat outside the HMAC.
        $this->rewriteLastRecord(static function (array $record): array {
            $record['description'] = 'Compliance verification: 5/5 checks passed (100.0% pass rate)';

            return $record;
        });

        $chain = $this->chain();
        $refusal = $chain->appendRefusal();

        self::assertNotNull($refusal);
        self::assertStringContainsString('did not authenticate', $refusal);

        $stored = new FileEvidenceStore($this->path)->all();
        $result = $chain->verify();

        self::assertSame(EvidenceChainVerdict::Modified, $result->verdict);
        self::assertSame([$stored[0]->id], $result->brokenAt);
    }

    #[Test]
    public function aBackdatedCollectionTimeIsRefusedAndReportedAsBroken(): void
    {
        $this->chain()->record($this->report(passes: 3));

        // `collected_at` decides which reporting period a record falls in, and it
        // was outside the HMAC too.
        $this->rewriteLastRecord(static function (array $record): array {
            $record['collected_at'] = '2020-01-01T00:00:00+00:00';

            return $record;
        });

        $chain = $this->chain();

        self::assertNotNull($chain->appendRefusal());
        self::assertSame(EvidenceChainVerdict::Modified, $chain->verify()->verdict);
    }

    #[Test]
    public function aRegisterSignedUnderAnotherKeyIsRefusedWithoutBeingCalledTampering(): void
    {
        $this->chain()->record($this->report());

        // A key rotation, a restore from another deployment's backup and a forged
        // record are indistinguishable from in here. The refusal says so instead
        // of picking one, because the operator knows which of the three happened
        // and this process never can.
        $reader = $this->chain(self::OTHER_KEY);
        $refusal = $reader->appendRefusal();

        self::assertNotNull($refusal);
        self::assertSame(EvidenceChainVerdict::KeyUnavailable, $reader->verify()->verdict);
        self::assertStringContainsString('key this process does not hold', $refusal);
        self::assertStringContainsString('look identical from here', $refusal);
    }

    #[Test]
    public function archivingTheRegisterIsTheDocumentedWayBackToACollectingChain(): void
    {
        $this->chain()->record($this->report());

        $this->rewriteLastRecord(static function (array $record): array {
            $record['description'] = 'forged';

            return $record;
        });

        self::assertNotNull($this->chain()->appendRefusal());

        // The recovery the refusal names: the register is archived — moved out of
        // the way, preserved as the artifact it is — and a new chain starts from
        // genesis over an empty store. The register is TWO files now, and both go:
        // one archived without the other leaves an anchor attesting records that
        // are not there, which is exactly what a truncation looks like.
        $archive = $this->dir . DIRECTORY_SEPARATOR . 'compliance-evidence.archived.jsonl';
        $archivedHead = $archive . '.head';
        $headPath = new FileEvidenceStore($this->path)->headPath();

        file_put_contents($archive, (string) file_get_contents($this->path));
        file_put_contents($archivedHead, (string) file_get_contents($headPath));
        unlink($this->path);
        unlink($headPath);

        $chain = $this->chain();

        self::assertNull($chain->appendRefusal());

        $chain->record($this->report(passes: 7));

        self::assertSame(EvidenceChainVerdict::Intact, $chain->verify()->verdict);
        self::assertNotSame('', trim((string) file_get_contents($archive)), 'the old register survives');
        self::assertNotSame('', trim((string) file_get_contents($archivedHead)), 'and so does its anchor');
    }

    #[Test]
    public function droppingWholeRecordsOffTheEndOfTheFileIsDetected(): void
    {
        // The defect this whole design exists for. Every surviving line still
        // chains to the line before it, so no amount of re-hashing the file finds
        // anything wrong; only the anchor beside it knows there should be three.
        $this->chain()->record($this->report(passes: 1));
        $this->chain()->record($this->report(passes: 2));
        $this->chain()->record($this->report(passes: 3));

        $lines = explode("\n", trim((string) file_get_contents($this->path)));
        file_put_contents($this->path, $lines[0] . "\n" . $lines[1] . "\n");

        $chain = $this->chain();
        $result = $chain->verify();

        self::assertSame(EvidenceChainVerdict::Truncated, $result->verdict);
        self::assertSame(2, $result->present);
        self::assertSame(3, $result->attested);
        self::assertSame(['2'], $result->brokenAt);
        self::assertNotNull($chain->appendRefusal());
    }

    #[Test]
    public function deletingTheAnchorBesideTheRegisterIsDetected(): void
    {
        $this->chain()->record($this->report(passes: 2));

        unlink(new FileEvidenceStore($this->path)->headPath());

        $chain = $this->chain();

        self::assertSame(EvidenceChainVerdict::Unreadable, $chain->verify()->verdict);
        self::assertNotNull($chain->appendRefusal());
    }

    #[Test]
    public function deletingTheRegisterWhileTheAnchorSurvivesIsDetected(): void
    {
        $this->chain()->record($this->report(passes: 2));
        $this->chain()->record($this->report(passes: 4));

        unlink($this->path);

        $chain = $this->chain();
        $result = $chain->verify();

        self::assertSame(EvidenceChainVerdict::Truncated, $result->verdict);
        self::assertSame(0, $result->present);
        self::assertSame(2, $result->attested);
        self::assertNotNull($chain->appendRefusal());
    }

    #[Test]
    public function anEmptyRegisterIsReportedEmptyRatherThanValid(): void
    {
        $result = $this->chain()->verify();

        self::assertSame(EvidenceChainVerdict::Empty, $result->verdict);
        self::assertFalse($result->admissible());
    }

    #[Test]
    public function anEmptyRegisterStartsFromGenesisRatherThanRefusing(): void
    {
        // A fresh deployment has no file at all, and a chain that treated
        // "nothing here" as "unverifiable" would never collect a first record.
        self::assertNull($this->chain()->appendRefusal());
    }

    private function chain(string $key = self::KEY): EvidenceChain
    {
        return new EvidenceChain(new FileEvidenceStore($this->path), $key);
    }

    private function report(int $passes = 3, int $fails = 0): VerificationReport
    {
        $results = [];

        for ($i = 0; $i < $passes; $i++) {
            $results[] = CheckResult::pass("check.pass.{$i}", 'Passed', ComplianceCheckDomain::Encryption);
        }

        for ($i = 0; $i < $fails; $i++) {
            $results[] = CheckResult::fail("check.fail.{$i}", 'Failed', ComplianceCheckDomain::Authentication);
        }

        return new VerificationReport(frameworks: [ComplianceFramework::Gdpr], results: $results);
    }

    /**
     * Edit the last stored record the way someone with write access to the file
     * would: decode the line, change one field, re-encode it, put it back.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $edit
     */
    private function rewriteLastRecord(callable $edit): void
    {
        $lines = explode("\n", trim((string) file_get_contents($this->path)));
        $last = $lines[count($lines) - 1];

        /** @var mixed $decoded */
        $decoded = json_decode($last, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        $lines[count($lines) - 1] = (string) json_encode(
            $edit($decoded),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        file_put_contents($this->path, implode("\n", $lines) . "\n");
    }

    /**
     * Run {@see \Pulsar\Compliance\Verification\EvidenceCollectionJob} in a real
     * child process against the same file.
     *
     * A child process, not a second object: the whole defect lives in what a
     * constructor does with a store it did not write, and two instances in one
     * PHP process can share state a scheduler tick cannot.
     */
    private function runCollectionJobInASeparateProcess(): void
    {
        $script = $this->dir . DIRECTORY_SEPARATOR . 'collect.php';

        if (!is_file($script)) {
            file_put_contents($script, self::CHILD_SCRIPT);
        }

        $process = proc_open(
            [PHP_BINARY, $script, dirname(__DIR__, 4) . '/vendor/autoload.php', $this->path, self::KEY],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->dir,
        );

        self::assertIsResource($process, 'could not start the evidence collection child process');

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), 'collection child failed: ' . $stdout . $stderr);
        self::assertSame('', trim($stderr), 'collection child wrote to stderr: ' . $stderr);
    }

    /**
     * The child: the real job, the real engine, the real chain, over the file
     * named on the command line. Written out at test time rather than checked in
     * so the whole scenario reads in one place.
     */
    private const string CHILD_SCRIPT = <<<'PHP'
        <?php

        declare(strict_types=1);

        use Pulsar\Compliance\ComplianceFramework;
        use Pulsar\Compliance\ComplianceProfileResolver;
        use Pulsar\Compliance\Evidence\FileEvidenceStore;
        use Pulsar\Compliance\Verification\ComplianceVerificationEngine;
        use Pulsar\Compliance\Verification\ConflictDetector;
        use Pulsar\Compliance\Verification\CustomControlRegistry;
        use Pulsar\Compliance\Verification\EvidenceChain;
        use Pulsar\Compliance\Verification\EvidenceCollectionJob;
        use Pulsar\Compliance\Verification\RuntimeVerifier;
        use Pulsar\Compliance\Verification\VerificationConfig;
        use Pulsar\Scheduler\JobContext;
        use Pulsar\Scheduler\JobStatus;
        use Pulsar\Scheduler\Schedule;

        require $argv[1];

        $store = new FileEvidenceStore($argv[2]);
        $profile = new ComplianceProfileResolver()->resolve([ComplianceFramework::Gdpr]);

        $engine = new ComplianceVerificationEngine(
            profile: $profile,
            runtimeVerifier: new RuntimeVerifier($profile),
            conflictDetector: new ConflictDetector(),
            customControlRegistry: new CustomControlRegistry(),
            config: new VerificationConfig(
                enabled: true,
                bootCheck: false,
                evidenceIntervalSeconds: 0,
                strictMode: false,
            ),
            evidenceChain: new EvidenceChain($store, $argv[3]),
        );

        $now = new \DateTimeImmutable();
        $result = new EvidenceCollectionJob($engine, $store, 0, Schedule::everyMinute())
            ->execute(new JobContext($now, $now));

        fwrite(STDOUT, $result->output);

        exit($result->status === JobStatus::Success ? 0 : 1);
        PHP;
}
