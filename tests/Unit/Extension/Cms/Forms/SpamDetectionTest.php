<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Forms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Pulsar\Extension\Cms\Forms\SpamDetection\SpamDetectorInterface;
use Pulsar\Extension\Cms\Forms\SpamDetection\SpamResult;
use Pulsar\Extension\Cms\Forms\SpamDetection\SpamScorer;
use Pulsar\Extension\Cms\Internal\Forms\ContentHeuristicScorer;
use Pulsar\Extension\Cms\Internal\Forms\HoneypotDetector;
use Pulsar\Extension\Cms\Internal\Forms\ManagedChallengeDetector;
use Pulsar\Extension\Cms\Internal\Forms\RateLimitDetector;
use Pulsar\Extension\Cms\Internal\Forms\TimingDetector;
use Pulsar\Security\AntiSpam\CaptchaVerifierInterface;
use Pulsar\Security\AntiSpam\ManagedChallenge\ManagedChallengeService;
use Pulsar\Security\AntiSpam\ManagedChallenge\ManagedChallengeVerifier;
use Pulsar\Tests\Benchmark\Cms\Support\InMemoryTaggedCache;

use function hash;
use function ord;
use function strlen;

#[CoversClass(SpamResult::class)]
#[CoversClass(SpamScorer::class)]
#[CoversClass(ContentHeuristicScorer::class)]
#[CoversClass(HoneypotDetector::class)]
#[CoversClass(ManagedChallengeDetector::class)]
#[CoversClass(RateLimitDetector::class)]
#[CoversClass(TimingDetector::class)]
final class SpamDetectionTest extends TestCase
{
    // --- SpamResult ---

    #[Test]
    public function spamResultConstruction(): void
    {
        $result = new SpamResult(true, 8.5, 'Too many URLs');

        self::assertTrue($result->isSpam);
        self::assertSame(8.5, $result->score);
        self::assertSame('Too many URLs', $result->reason);
    }

    #[Test]
    public function spamResultNotSpam(): void
    {
        $result = new SpamResult(false, 0.0, null);

        self::assertFalse($result->isSpam);
        self::assertSame(0.0, $result->score);
        self::assertNull($result->reason);
    }

    // --- SpamScorer ---

    #[Test]
    public function spamScorerWithNoDetectorsReturnsClean(): void
    {
        $scorer = new SpamScorer(threshold: 5.0);

        $result = $scorer->score(['name' => 'Alice'], []);

        self::assertFalse($result->isSpam);
        self::assertSame(0.0, $result->score);
        self::assertNull($result->reason);
    }

    #[Test]
    public function spamScorerAggregatesDetectorScores(): void
    {
        $scorer = new SpamScorer(threshold: 5.0);

        $detector1 = $this->createStub(SpamDetectorInterface::class);
        $detector1->method('detect')->willReturn(new SpamResult(false, 2.0, 'mild'));

        $detector2 = $this->createStub(SpamDetectorInterface::class);
        $detector2->method('detect')->willReturn(new SpamResult(false, 1.5, 'also mild'));

        $scorer->addDetector($detector1);
        $scorer->addDetector($detector2);

        $result = $scorer->score([], []);

        self::assertFalse($result->isSpam);
        self::assertSame(3.5, $result->score);
        self::assertSame('mild; also mild', $result->reason);
    }

    #[Test]
    public function spamScorerExceedsThreshold(): void
    {
        $scorer = new SpamScorer(threshold: 3.0);

        $detector = $this->createStub(SpamDetectorInterface::class);
        $detector->method('detect')->willReturn(new SpamResult(true, 5.0, 'bad'));

        $scorer->addDetector($detector);

        $result = $scorer->score([], []);

        self::assertTrue($result->isSpam);
        self::assertSame(5.0, $result->score);
    }

    #[Test]
    public function spamScorerOmitsNullReasons(): void
    {
        $scorer = new SpamScorer(threshold: 10.0);

        $detector = $this->createStub(SpamDetectorInterface::class);
        $detector->method('detect')->willReturn(new SpamResult(false, 1.0, null));

        $scorer->addDetector($detector);

        $result = $scorer->score([], []);

        self::assertNull($result->reason);
    }

    // --- HoneypotDetector ---

    #[Test]
    public function honeypotDetectsFilledField(): void
    {
        $detector = new HoneypotDetector('_hp_field');

        $result = $detector->detect(['_hp_field' => 'bot filled this'], []);

        self::assertTrue($result->isSpam);
        self::assertSame(10.0, $result->score);
        self::assertSame('Honeypot field filled', $result->reason);
    }

    #[Test]
    public function honeypotPassesWhenFieldEmpty(): void
    {
        $detector = new HoneypotDetector('_hp_field');

        $result = $detector->detect(['_hp_field' => ''], []);

        self::assertFalse($result->isSpam);
        self::assertSame(0.0, $result->score);
    }

    #[Test]
    public function honeypotPassesWhenFieldMissing(): void
    {
        $detector = new HoneypotDetector('_hp_field');

        $result = $detector->detect(['name' => 'Alice'], []);

        self::assertFalse($result->isSpam);
    }

    #[Test]
    public function honeypotUsesCustomFieldName(): void
    {
        $detector = new HoneypotDetector('_trap');

        $result = $detector->detect(['_trap' => 'gotcha'], []);

        self::assertTrue($result->isSpam);
    }

    #[Test]
    public function honeypotPassesWhenFieldIsWhitespaceOnly(): void
    {
        $detector = new HoneypotDetector();

        $result = $detector->detect(['_hp_field' => '   '], []);

        self::assertFalse($result->isSpam);
    }

    // --- ContentHeuristicScorer ---

    #[Test]
    public function contentHeuristicCleanData(): void
    {
        $scorer = new ContentHeuristicScorer();

        $result = $scorer->detect(['message' => 'Hello, this is a normal message.'], []);

        self::assertFalse($result->isSpam);
        self::assertSame(0.0, $result->score);
        self::assertNull($result->reason);
    }

    #[Test]
    public function contentHeuristicExcessiveUrls(): void
    {
        $scorer = new ContentHeuristicScorer();
        $text = 'Visit http://a.com http://b.com http://c.com http://d.com for more';

        $result = $scorer->detect(['body' => $text], []);

        self::assertTrue($result->isSpam);
        self::assertGreaterThanOrEqual(2.0, $result->score);
        self::assertStringContainsString('Excessive URLs', (string) $result->reason);
    }

    #[Test]
    public function contentHeuristicRepeatedCharacters(): void
    {
        $scorer = new ContentHeuristicScorer();
        $text = 'aaaaaaaaaaaaaaa';

        $result = $scorer->detect(['body' => $text], []);

        self::assertTrue($result->isSpam);
        self::assertStringContainsString('Repeated characters', (string) $result->reason);
    }

    #[Test]
    public function contentHeuristicAllCaps(): void
    {
        $scorer = new ContentHeuristicScorer();

        $result = $scorer->detect(['body' => 'THIS IS ALL CAPS TEXT SCREAMING'], []);

        self::assertTrue($result->isSpam);
        self::assertStringContainsString('All-caps', (string) $result->reason);
    }

    #[Test]
    public function contentHeuristicShortAllCapsIgnored(): void
    {
        $scorer = new ContentHeuristicScorer();

        $result = $scorer->detect(['body' => 'OK'], []);

        self::assertFalse($result->isSpam);
    }

    #[Test]
    public function contentHeuristicEmptyRequiredField(): void
    {
        $scorer = new ContentHeuristicScorer(requiredFields: ['name', 'email']);

        $result = $scorer->detect(['name' => '', 'email' => 'test@example.com'], []);

        self::assertTrue($result->isSpam);
        self::assertStringContainsString('Required field empty: name', (string) $result->reason);
    }

    #[Test]
    public function contentHeuristicMissingRequiredField(): void
    {
        $scorer = new ContentHeuristicScorer(requiredFields: ['name']);

        $result = $scorer->detect([], []);

        self::assertGreaterThanOrEqual(3.0, $result->score);
    }

    #[Test]
    public function contentHeuristicSkipsNonStringValues(): void
    {
        $scorer = new ContentHeuristicScorer();

        $result = $scorer->detect(['count' => 42, 'flag' => true], []);

        self::assertFalse($result->isSpam);
    }

    // --- ManagedChallengeDetector ---

    #[Test]
    public function managedChallengeDetectorPassesWhenVerifierAcceptsToken(): void
    {
        $verifier = $this->createStub(CaptchaVerifierInterface::class);
        $verifier->method('verify')->willReturn(true);
        $detector = new ManagedChallengeDetector($verifier);

        $result = $detector->detect([], ['captcha_token' => 'signed.solution']);

        self::assertFalse($result->isSpam);
        self::assertSame(0.0, $result->score);
    }

    #[Test]
    public function managedChallengeDetectorFlagsWhenVerifierRejectsToken(): void
    {
        $verifier = $this->createStub(CaptchaVerifierInterface::class);
        $verifier->method('verify')->willReturn(false);
        $detector = new ManagedChallengeDetector($verifier);

        $result = $detector->detect([], ['captcha_token' => 'forged.solution']);

        self::assertTrue($result->isSpam);
        self::assertSame(9.0, $result->score);
        self::assertSame('Managed-challenge verification failed', $result->reason);
    }

    #[Test]
    public function managedChallengeDetectorFlagsMissingToken(): void
    {
        $verifier = $this->createStub(CaptchaVerifierInterface::class);
        $verifier->method('verify')->willReturn(true);
        $detector = new ManagedChallengeDetector($verifier);

        $result = $detector->detect([], []);

        self::assertTrue($result->isSpam);
        self::assertStringContainsString('Missing', (string) $result->reason);
    }

    #[Test]
    public function managedChallengeDetectorReadsTokenFromDataField(): void
    {
        $verifier = $this->createStub(CaptchaVerifierInterface::class);
        $verifier->method('verify')->willReturn(true);
        $detector = new ManagedChallengeDetector($verifier);

        // No meta token: the detector falls back to the raw widget field in $data.
        $result = $detector->detect(['pulsar-challenge-response' => 'signed.solution'], []);

        self::assertFalse($result->isSpam);
    }

    #[Test]
    public function managedChallengeDetectorRejectsReplayEndToEnd(): void
    {
        // End-to-end through the real signing engine: a solved token is accepted
        // once, then rejected on replay. This is the property the retired bespoke
        // proof of work lacked — any valid (challenge, nonce) pair was replayable
        // forever, with no signature, expiry, or single-use tracking.
        $service = new ManagedChallengeService('0123456789abcdef0123456789abcdef', 4, 300, new InMemoryTaggedCache());
        $detector = new ManagedChallengeDetector(new ManagedChallengeVerifier($service));

        $challenge = $service->mint();
        $submitted = $service->sign($challenge) . '.' . $this->solvePow($challenge->id, $challenge->bits);

        self::assertFalse(
            $detector->detect([], ['captcha_token' => $submitted])->isSpam,
            'a freshly solved managed-challenge token must pass',
        );
        self::assertTrue(
            $detector->detect([], ['captcha_token' => $submitted])->isSpam,
            'replaying the same solved token must be rejected (single-use)',
        );
    }

    /** Brute-force a solution meeting the difficulty (mirrors the browser worker). */
    private function solvePow(string $id, int $bits): string
    {
        for ($i = 0; $i < 1_000_000; $i++) {
            $digest = hash('sha256', $id . '.' . $i, true);
            $count = 0;

            for ($b = 0, $len = strlen($digest); $b < $len; $b++) {
                $byte = ord($digest[$b]);

                if ($byte === 0) {
                    $count += 8;

                    continue;
                }

                for ($mask = 0x80; $mask > 0; $mask >>= 1, $count++) {
                    if (($byte & $mask) !== 0) {
                        break 2;
                    }
                }
            }

            if ($count >= $bits) {
                return (string) $i;
            }
        }

        self::fail('No proof-of-work solution found within bound');
    }

    // --- TimingDetector ---

    #[Test]
    public function timingDetectorPassesForNormalTiming(): void
    {
        $detector = new TimingDetector();
        $renderedAt = time() - 10;

        $result = $detector->detect([], ['_form_rendered_at' => $renderedAt]);

        self::assertFalse($result->isSpam);
    }

    #[Test]
    public function timingDetectorFlagsFastSubmission(): void
    {
        $detector = new TimingDetector();
        $renderedAt = time();

        $result = $detector->detect([], ['_form_rendered_at' => $renderedAt]);

        self::assertTrue($result->isSpam);
        self::assertSame(8.0, $result->score);
        self::assertStringContainsString('too quickly', (string) $result->reason);
    }

    #[Test]
    public function timingDetectorPassesWhenNoTimingData(): void
    {
        $detector = new TimingDetector();

        $result = $detector->detect([], []);

        self::assertFalse($result->isSpam);
        self::assertSame(0.0, $result->score);
    }

    #[Test]
    public function timingDetectorIgnoresDataAndOnlyReadsMeta(): void
    {
        $detector = new TimingDetector();
        $renderedAt = time();

        // TimingDetector reads from $meta only, never $data
        $result = $detector->detect(['_form_rendered_at' => $renderedAt], []);

        self::assertFalse($result->isSpam);
        self::assertSame(0.0, $result->score);
    }

    // --- RateLimitDetector ---

    #[Test]
    public function rateLimitPassesUnderThreshold(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')->willReturn(1);

        $detector = new RateLimitDetector($cache, maxPerHour: 10);

        $result = $detector->detect([], ['ip' => '1.2.3.4']);

        self::assertFalse($result->isSpam);
    }

    #[Test]
    public function rateLimitFlagsOverThreshold(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')->willReturn(15);

        $detector = new RateLimitDetector($cache, maxPerHour: 10);

        $result = $detector->detect([], ['ip' => '1.2.3.4']);

        self::assertTrue($result->isSpam);
        self::assertSame(7.0, $result->score);
        self::assertStringContainsString('Rate limit exceeded', (string) $result->reason);
    }

    #[Test]
    public function rateLimitPassesWhenNoIp(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $detector = new RateLimitDetector($cache);

        $result = $detector->detect([], []);

        self::assertFalse($result->isSpam);
    }

    #[Test]
    public function rateLimitTreatsNullCacheValueAsZero(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')->willReturn(null);

        $detector = new RateLimitDetector($cache, maxPerHour: 10);

        $result = $detector->detect([], ['ip' => '10.0.0.1']);

        self::assertFalse($result->isSpam);
    }
}
