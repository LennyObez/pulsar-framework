<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Content;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\RevisionRetentionPolicy;

#[CoversClass(RevisionRetentionPolicy::class)]
final class RevisionRetentionPolicyFromArrayTest extends TestCase
{
    #[Test]
    public function fromArrayWithFullData(): void
    {
        $policy = RevisionRetentionPolicy::fromArray([
            'max_revisions_per_content' => 100,
            'max_age_days' => 730,
            'keep_published' => false,
            'keep_first_revision' => false,
        ]);

        self::assertSame(100, $policy->maxRevisionsPerContent);
        self::assertSame(730, $policy->maxAgeDays);
        self::assertFalse($policy->keepPublished);
        self::assertFalse($policy->keepFirstRevision);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $policy = RevisionRetentionPolicy::fromArray([]);

        self::assertSame(50, $policy->maxRevisionsPerContent);
        self::assertSame(365, $policy->maxAgeDays);
        self::assertTrue($policy->keepPublished);
        self::assertTrue($policy->keepFirstRevision);
    }

    #[Test]
    public function fromArrayIgnoresInvalidTypes(): void
    {
        $policy = RevisionRetentionPolicy::fromArray([
            'max_revisions_per_content' => 'many',
            'max_age_days' => null,
            'keep_published' => 'yes',
        ]);

        self::assertSame(50, $policy->maxRevisionsPerContent);
        self::assertSame(365, $policy->maxAgeDays);
        self::assertTrue($policy->keepPublished);
    }

    #[Test]
    public function constructThrowsForNegativeValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('non-negative');

        new RevisionRetentionPolicy(maxRevisionsPerContent: -1);
    }

    #[Test]
    public function constructAllowsZeroForUnlimited(): void
    {
        $policy = new RevisionRetentionPolicy(
            maxRevisionsPerContent: 0,
            maxAgeDays: 0,
        );

        self::assertSame(0, $policy->maxRevisionsPerContent);
        self::assertSame(0, $policy->maxAgeDays);
    }
}
