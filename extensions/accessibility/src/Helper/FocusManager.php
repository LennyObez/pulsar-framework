<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Helper;

use Pulsar\Api\Api;

use function sprintf;

/**
 * Generates data attributes for JavaScript focus management.
 *
 * These attributes are consumed by client-side scripts to implement
 * focus trapping, focus restoration, roving tabindex, and skip-to behavior.
 */
#[Api(since: '1.0.0')]
final readonly class FocusManager
{
    public function trapAttributes(string $groupId): string
    {
        return sprintf(
            'data-focus-trap="%s" tabindex="-1"',
            htmlspecialchars($groupId, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        );
    }

    public function restoreFocusAttributes(): string
    {
        return 'data-focus-restore="true"';
    }

    public function skipToAttributes(string $targetId): string
    {
        return sprintf(
            'data-skip-to="%s"',
            htmlspecialchars($targetId, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        );
    }

    public function autofocusAttributes(): string
    {
        return 'data-focus-auto="true"';
    }

    public function rovingtabAttributes(string $groupId): string
    {
        return sprintf(
            'data-roving-tab="%s" tabindex="-1"',
            htmlspecialchars($groupId, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        );
    }
}
