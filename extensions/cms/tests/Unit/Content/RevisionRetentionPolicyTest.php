<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Content;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\RevisionRetentionPolicy;

final class RevisionRetentionPolicyTest extends TestCase
{
    #[Test]
    public function defaults(): void
    {
        $policy = new RevisionRetentionPolicy();

        self::assertSame(50, $policy->maxRevisionsPerContent);
        self::assertSame(365, $policy->maxAgeDays);
        self::assertTrue($policy->keepPublished);
        self::assertTrue($policy->keepFirstRevision);
    }

    #[Test]
    public function custom_values(): void
    {
        $policy = new RevisionRetentionPolicy(
            maxRevisionsPerContent: 10,
            maxAgeDays: 90,
            keepPublished: false,
            keepFirstRevision: false,
        );

        self::assertSame(10, $policy->maxRevisionsPerContent);
        self::assertSame(90, $policy->maxAgeDays);
        self::assertFalse($policy->keepPublished);
        self::assertFalse($policy->keepFirstRevision);
    }

    #[Test]
    public function zero_means_unlimited(): void
    {
        $policy = new RevisionRetentionPolicy(
            maxRevisionsPerContent: 0,
            maxAgeDays: 0,
        );

        self::assertSame(0, $policy->maxRevisionsPerContent);
        self::assertSame(0, $policy->maxAgeDays);
    }

    #[Test]
    public function negative_max_revisions_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-negative');

        new RevisionRetentionPolicy(maxRevisionsPerContent: -1);
    }

    #[Test]
    public function negative_max_age_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RevisionRetentionPolicy(maxAgeDays: -1);
    }

    #[Test]
    public function from_array_with_all_keys(): void
    {
        $policy = RevisionRetentionPolicy::fromArray([
            'max_revisions_per_content' => 20,
            'max_age_days' => 180,
            'keep_published' => false,
            'keep_first_revision' => false,
        ]);

        self::assertSame(20, $policy->maxRevisionsPerContent);
        self::assertSame(180, $policy->maxAgeDays);
        self::assertFalse($policy->keepPublished);
        self::assertFalse($policy->keepFirstRevision);
    }

    #[Test]
    public function from_array_with_empty_array_uses_defaults(): void
    {
        $policy = RevisionRetentionPolicy::fromArray([]);

        self::assertSame(50, $policy->maxRevisionsPerContent);
        self::assertSame(365, $policy->maxAgeDays);
        self::assertTrue($policy->keepPublished);
        self::assertTrue($policy->keepFirstRevision);
    }

    #[Test]
    public function from_array_ignores_non_integer_values(): void
    {
        $policy = RevisionRetentionPolicy::fromArray([
            'max_revisions_per_content' => 'not-an-int',
            'max_age_days' => null,
        ]);

        self::assertSame(50, $policy->maxRevisionsPerContent);
        self::assertSame(365, $policy->maxAgeDays);
    }
}
