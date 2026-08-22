<?php

declare(strict_types=1);

namespace Pulsar\Live;

use Attribute;
use Pulsar\Api\Api;

/**
 * Marks a method as callable from the frontend via wire:click or wire:submit.
 *
 * Usage:
 *   #[LiveAction]
 *   public function increment(): void { ... }
 */
#[Attribute(Attribute::TARGET_METHOD)]
#[Api(since: '1.0.0')]
final readonly class LiveAction
{
    /**
     * @param string $name Custom action name (defaults to method name)
     */
    public function __construct(
        public string $name = '',
    ) {}
}
