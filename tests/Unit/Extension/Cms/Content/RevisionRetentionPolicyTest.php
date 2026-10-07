<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Content;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\RevisionRetentionPolicy;

#[CoversClass(RevisionRetentionPolicy::class)]
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
    public function customValues(): void
    {
        $policy = new RevisionRetentionPolicy(
            maxRevisionsPerContent: 10,
            maxAgeDays: 30,
            keepPublished: false,
            keepFirstRevision: false,
        );

        self::assertSame(10, $policy->maxRevisionsPerContent);
        self::assertSame(30, $policy->maxAgeDays);
        self::assertFalse($policy->keepPublished);
        self::assertFalse($policy->keepFirstRevision);
    }

    #[Test]
    public function fromArrayDefaults(): void
    {
        $policy = RevisionRetentionPolicy::fromArray([]);

        self::assertSame(50, $policy->maxRevisionsPerContent);
        self::assertSame(365, $policy->maxAgeDays);
        self::assertTrue($policy->keepPublished);
        self::assertTrue($policy->keepFirstRevision);
    }

    #[Test]
    public function fromArrayExplicitValues(): void
    {
        $policy = RevisionRetentionPolicy::fromArray([
            'max_revisions_per_content' => 25,
            'max_age_days' => 90,
            'keep_published' => false,
            'keep_first_revision' => false,
        ]);

        self::assertSame(25, $policy->maxRevisionsPerContent);
        self::assertSame(90, $policy->maxAgeDays);
        self::assertFalse($policy->keepPublished);
        self::assertFalse($policy->keepFirstRevision);
    }

    #[Test]
    public function fromArrayZeroUnlimited(): void
    {
        $policy = RevisionRetentionPolicy::fromArray([
            'max_revisions_per_content' => 0,
            'max_age_days' => 0,
        ]);

        self::assertSame(0, $policy->maxRevisionsPerContent);
        self::assertSame(0, $policy->maxAgeDays);
    }
}
