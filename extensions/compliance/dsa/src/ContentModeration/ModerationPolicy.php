<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dsa\ContentModeration;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Content moderation policy definition per DSA Article 14.
 *
 * Platforms must publish clear terms of service describing their
 * content moderation policies, including the types of restrictions
 * applied and the grounds for those restrictions.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ModerationPolicy
{
    /**
     * @param string $id                  Unique policy identifier
     * @param string $name                Human-readable policy name
     * @param string $description         Detailed policy description
     * @param string $legalBasis          Legal basis for the policy (e.g., national law, terms of service)
     * @param bool   $requiresHumanReview Whether decisions under this policy require human review
     * @param string $category            Content category (e.g., illegal_content, terms_violation, harmful_content)
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public string $legalBasis,
        public bool $requiresHumanReview = true,
        public string $category = 'terms_violation',
    ) {}

    /**
     * @param array{
     *     id?: string,
     *     name?: string,
     *     description?: string,
     *     legal_basis?: string,
     *     requires_human_review?: bool,
     *     category?: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? '',
            name: $data['name'] ?? '',
            description: $data['description'] ?? '',
            legalBasis: $data['legal_basis'] ?? '',
            requiresHumanReview: $data['requires_human_review'] ?? true,
            category: $data['category'] ?? 'terms_violation',
        );
    }

    /**
     * @return array<string, mixed>
     */
    #[NoDiscard]
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'legal_basis' => $this->legalBasis,
            'requires_human_review' => $this->requiresHumanReview,
            'category' => $this->category,
        ];
    }
}
