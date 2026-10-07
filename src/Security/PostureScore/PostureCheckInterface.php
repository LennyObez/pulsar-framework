<?php

declare(strict_types=1);

namespace Pulsar\Security\PostureScore;

use Pulsar\Api\Api;

/**
 * Checks whether a specific security control is active.
 * @api
 */
#[Api(since: '1.0.0')]
interface PostureCheckInterface
{
    /**
     * The control this check evaluates.
     */
    public function control(): SecurityControl;

    /**
     * Whether the control is currently active/configured.
     */
    public function isActive(): bool;

    /**
     * Human-readable description of how to fix when inactive.
     */
    public function recommendation(): string;
}
