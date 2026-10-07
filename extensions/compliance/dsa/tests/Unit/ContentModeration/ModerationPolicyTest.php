<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dsa\Tests\Unit\ContentModeration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Dsa\ContentModeration\ModerationPolicy;

#[CoversClass(ModerationPolicy::class)]
final class ModerationPolicyTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $policy = new ModerationPolicy(
            id: 'policy-1',
            name: 'Hate Speech Policy',
            description: 'Prohibits content that promotes hatred',
            legalBasis: 'Framework Decision 2008/913/JHA',
            requiresHumanReview: true,
            category: 'illegal_content',
        );

        self::assertSame('policy-1', $policy->id);
        self::assertSame('Hate Speech Policy', $policy->name);
        self::assertSame('Prohibits content that promotes hatred', $policy->description);
        self::assertSame('Framework Decision 2008/913/JHA', $policy->legalBasis);
        self::assertTrue($policy->requiresHumanReview);
        self::assertSame('illegal_content', $policy->category);
    }

    #[Test]
    public function constructorUsesDefaultsForOptionalProperties(): void
    {
        $policy = new ModerationPolicy(
            id: 'policy-2',
            name: 'Test',
            description: 'Test description',
            legalBasis: 'Terms of service',
        );

        self::assertTrue($policy->requiresHumanReview);
        self::assertSame('terms_violation', $policy->category);
    }

    #[Test]
    public function fromArrayWithValidData(): void
    {
        $policy = ModerationPolicy::fromArray([
            'id' => 'policy-3',
            'name' => 'CSAM Policy',
            'description' => 'Child safety policy',
            'legal_basis' => 'Directive 2011/93/EU',
            'requires_human_review' => false,
            'category' => 'illegal_content',
        ]);

        self::assertSame('policy-3', $policy->id);
        self::assertSame('CSAM Policy', $policy->name);
        self::assertFalse($policy->requiresHumanReview);
        self::assertSame('illegal_content', $policy->category);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $policy = ModerationPolicy::fromArray([]);

        self::assertSame('', $policy->id);
        self::assertSame('', $policy->name);
        self::assertTrue($policy->requiresHumanReview);
        self::assertSame('terms_violation', $policy->category);
    }

    #[Test]
    public function fromArrayIgnoresInvalidTypes(): void
    {
        $policy = ModerationPolicy::fromArray([
            'id' => 42,
            'requires_human_review' => 'yes',
        ]);

        self::assertSame('', $policy->id);
        self::assertTrue($policy->requiresHumanReview);
    }

    #[Test]
    public function toArrayReturnsAllFields(): void
    {
        $policy = new ModerationPolicy(
            id: 'policy-4',
            name: 'Spam Policy',
            description: 'Prevents spam content',
            legalBasis: 'Terms of service',
            requiresHumanReview: false,
            category: 'terms_violation',
        );

        $array = $policy->toArray();

        self::assertSame('policy-4', $array['id']);
        self::assertSame('Spam Policy', $array['name']);
        self::assertSame('Prevents spam content', $array['description']);
        self::assertSame('Terms of service', $array['legal_basis']);
        self::assertFalse($array['requires_human_review']);
        self::assertSame('terms_violation', $array['category']);
    }
}
