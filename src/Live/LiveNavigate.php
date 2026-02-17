<?php

declare(strict_types=1);

namespace Pulsar\Live;

use Pulsar\Api\Api;

use function htmlspecialchars;

use const ENT_QUOTES;

/**
 * Configuration and helpers for wire:navigate SPA-like navigation.
 *
 * wire:navigate intercepts link clicks to fetch pages via AJAX and morph
 * the DOM, providing SPA-like speed without a full page reload. The server
 * returns the full HTML response; the frontend extracts and swaps the body.
 *
 * Usage in templates:
 *   <a href="/dashboard" wire:navigate>Dashboard</a>
 *   <a href="/settings" wire:navigate.prefetch>Settings</a>
 */
#[Api(since: '1.0.0')]
final readonly class LiveNavigate
{
    public function __construct(
        public bool $enabled = true,
        public bool $prefetch = true,
        public bool $progressBar = true,
        public string $progressColor = '#4f46e5',
        public int $progressHeight = 2,
    ) {}

    /**
     * Generate the wire:navigate attribute string for an anchor tag.
     *
     * @param bool $prefetch Whether to prefetch on hover
     */
    public function attribute(bool $prefetch = false): string
    {
        if (!$this->enabled) {
            return '';
        }

        return $prefetch ? 'wire:navigate.prefetch' : 'wire:navigate';
    }

    /**
     * Render the progress bar CSS for SPA navigation.
     */
    public function progressBarStyles(): string
    {
        if (!$this->progressBar) {
            return '';
        }

        $color = htmlspecialchars($this->progressColor, ENT_QUOTES, 'UTF-8');
        $height = $this->progressHeight;

        return <<<CSS
            <style>
            [data-live-progress] {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                height: {$height}px;
                background: {$color};
                z-index: 99999;
                transition: width 200ms ease;
            }
            </style>
            CSS;
    }
}
