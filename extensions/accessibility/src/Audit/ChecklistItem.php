<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Audit;

use Pulsar\Api\Api;

/**
 * A single item in the manual accessibility testing checklist.
 */
#[Api(since: '1.0.0')]
final readonly class ChecklistItem
{
    public function __construct(
        public string $id,
        public string $category,
        public string $description,
        public string $wcagCriterion,
        public string $wcagLevel,
        public string $guidance,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category,
            'description' => $this->description,
            'wcag_criterion' => $this->wcagCriterion,
            'wcag_level' => $this->wcagLevel,
            'guidance' => $this->guidance,
        ];
    }
}
