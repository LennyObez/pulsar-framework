<?php

declare(strict_types=1);

namespace Pulsar\Rendering\Storybook;

use Pulsar\Api\Api;

/**
 * Represents a single component story (a rendered example with specific props).
 */
#[Api(since: '1.0.0')]
final readonly class ComponentStory
{
    /**
     * @param array<string, mixed> $props
     */
    public function __construct(
        public string $name,
        public string $description,
        public string $componentClass,
        public array $props,
        public string $category = 'General',
    ) {}
}
