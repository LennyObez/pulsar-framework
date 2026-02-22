<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Config\ReputationConfig;

#[CoversClass(ReputationConfig::class)]
final class ReputationConfigTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $config = new ReputationConfig();
        self::assertSame(2, $config->pointsPerThread);
        self::assertSame(1, $config->pointsPerPost);
        self::assertSame(5, $config->pointsPerUpvote);
        self::assertSame(-2, $config->pointsPerDownvote);
        self::assertSame(15, $config->pointsPerSolution);
        self::assertSame(50, $config->minReputationToDownvote);
    }

    #[Test]
    public function fromArrayWithEmptyArray(): void
    {
        $config = ReputationConfig::fromArray([]);
        self::assertSame(2, $config->pointsPerThread);
        self::assertSame(50, $config->minReputationToDownvote);
    }

    #[Test]
    public function fromArrayWithFullData(): void
    {
        $config = ReputationConfig::fromArray([
            'points_per_thread' => 5,
            'points_per_post' => 2,
            'points_per_upvote' => 10,
            'points_per_downvote' => -5,
            'points_per_solution' => 25,
            'min_reputation_to_downvote' => 100,
        ]);
        self::assertSame(5, $config->pointsPerThread);
        self::assertSame(2, $config->pointsPerPost);
        self::assertSame(10, $config->pointsPerUpvote);
        self::assertSame(-5, $config->pointsPerDownvote);
        self::assertSame(25, $config->pointsPerSolution);
        self::assertSame(100, $config->minReputationToDownvote);
    }

    #[Test]
    public function fromArrayIgnoresNonIntValues(): void
    {
        $config = ReputationConfig::fromArray([
            'points_per_thread' => 'abc',
            'min_reputation_to_downvote' => null,
        ]);
        self::assertSame(2, $config->pointsPerThread);
        self::assertSame(50, $config->minReputationToDownvote);
    }
}
