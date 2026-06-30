<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam\Behavior;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\AntiSpam\AntiSpamContext;
use Pulsar\Security\AntiSpam\Behavior\BehavioralSignalsCheck;
use Pulsar\Security\AntiSpam\Behavior\BehaviorFeatureSink;
use Pulsar\Security\AntiSpam\Behavior\HeuristicScorer;
use Pulsar\Security\AntiSpam\Behavior\NullBehaviorFeatureSink;

use function json_encode;

#[CoversClass(BehavioralSignalsCheck::class)]
final class BehavioralSignalsCheckTest extends TestCase
{
    private const string FIELD = 'pulsar-bx';

    private function context(string $blob): AntiSpamContext
    {
        return new AntiSpamContext(body: 'hello', ipHash: 'ip', formFields: [self::FIELD => $blob]);
    }

    private function check(BehaviorFeatureSink $sink): BehavioralSignalsCheck
    {
        return new BehavioralSignalsCheck(new HeuristicScorer(), $sink, self::FIELD);
    }

    #[Test]
    public function nameIsBehavior(): void
    {
        self::assertSame('behavior', $this->check(new NullBehaviorFeatureSink())->name());
    }

    #[Test]
    public function alwaysPassesEvenForAStronglyBotLikeSubmission(): void
    {
        $blob = (string) json_encode(['i' => 1, 'd' => 50, 'pe' => 0.0, 'wd' => 1, 'pr' => 1.0, 'kc' => 1]);

        $result = $this->check(new NullBehaviorFeatureSink())->check($this->context($blob));

        // SCORE-ONLY: it must never fail a submission, only contribute a score.
        self::assertTrue($result->passed);
        self::assertSame('behavior', $result->checkName);
        self::assertGreaterThan(0, $result->score);
    }

    #[Test]
    public function plausibleHumanScoresZeroAndPasses(): void
    {
        $blob = (string) json_encode(['i' => 1, 'd' => 9000, 'pe' => 0.7, 'wd' => 0, 'pr' => 0.0, 'kc' => 60]);

        $result = $this->check(new NullBehaviorFeatureSink())->check($this->context($blob));

        self::assertTrue($result->passed);
        self::assertSame(0, $result->score);
    }

    #[Test]
    public function missingFieldIsANeutralNoOp(): void
    {
        // No-JS / stripped field: pass with zero score, no penalty.
        $context = new AntiSpamContext(body: 'hello', ipHash: 'ip', formFields: []);

        $result = $this->check(new NullBehaviorFeatureSink())->check($context);

        self::assertTrue($result->passed);
        self::assertSame(0, $result->score);
    }

    #[Test]
    public function recordsTheFeatureVectorAndScoreToTheSink(): void
    {
        $sink = new class implements BehaviorFeatureSink {
            /** @var list<array{vector: array<string, bool|float|int>, score: int}> */
            public array $records = [];

            public function record(array $featureVector, int $score): void
            {
                $this->records[] = ['vector' => $featureVector, 'score' => $score];
            }
        };

        $blob = (string) json_encode(['i' => 1, 'd' => 50, 'wd' => 1]);
        $this->check($sink)->check($this->context($blob));

        self::assertCount(1, $sink->records);
        self::assertArrayHasKey('webdriver', $sink->records[0]['vector']);
        self::assertTrue($sink->records[0]['vector']['webdriver']);
        self::assertGreaterThan(0, $sink->records[0]['score']);
    }
}
