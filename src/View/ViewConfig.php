<?php

declare(strict_types=1);

namespace Pulsar\View;

use NoDiscard;
use Pulsar\Api\Api;

use function is_array;
use function is_int;
use function is_string;

/**
 * Typed configuration DTO for `config/view.php`.
 *
 * Controls template paths, caching, escaping defaults, theme selection,
 * and untrusted template sandbox limits.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ViewConfig
{
    /**
     * @param list<string> $templatePaths Ordered list of template search directories
     * @param string $cachePath Directory for compiled template cache
     * @param bool $autoEscape Whether output is HTML-escaped by default
     * @param string $activeTheme Active theme name (maps to resources/themes/{name}.css)
     * @param bool $phpDirectiveAllowed Whether @php blocks are permitted at compile time
     * @param bool $sandboxMode When true, compiled output is validated against a function/class allowlist
     * @param int $sandboxStepLimit Maximum AST node evaluations in untrusted mode
     * @param int $sandboxLoopLimit Maximum iterations per loop construct in untrusted mode
     * @param int $sandboxOutputSizeLimit Maximum rendered output bytes in untrusted mode
     * @param int $sandboxWallClockCheckInterval Steps between wall-clock checks in untrusted mode
     */
    public function __construct(
        public array $templatePaths,
        public string $cachePath,
        public bool $autoEscape = true,
        public string $activeTheme = 'default',
        public bool $phpDirectiveAllowed = false,
        public bool $sandboxMode = false,
        public int $sandboxStepLimit = 10_000,
        public int $sandboxLoopLimit = 1_000,
        public int $sandboxOutputSizeLimit = 1_048_576,
        public int $sandboxWallClockCheckInterval = 500,
    ) {}

    /**
     * Build a ViewConfig from a raw config array.
     *
     * @param array<string, mixed> $data Raw array from config/view.php
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $rawPaths = $data['template_paths'] ?? [];
        $templatePaths = is_array($rawPaths) ? self::filterStringList($rawPaths) : [];

        $rawCachePath = $data['cache_path'] ?? '';
        $cachePath = is_string($rawCachePath) ? $rawCachePath : '';

        $autoEscape = ($data['auto_escape'] ?? true) !== false;

        $rawTheme = $data['active_theme'] ?? 'default';
        $activeTheme = is_string($rawTheme) ? $rawTheme : 'default';

        $phpDirectiveAllowed = ($data['php_directive_allowed'] ?? false) === true;
        $sandboxMode = ($data['sandbox_mode'] ?? false) === true;

        $rawStepLimit = $data['sandbox_step_limit'] ?? 10_000;
        $sandboxStepLimit = is_int($rawStepLimit) && $rawStepLimit > 0 ? $rawStepLimit : 10_000;

        $rawLoopLimit = $data['sandbox_loop_limit'] ?? 1_000;
        $sandboxLoopLimit = is_int($rawLoopLimit) && $rawLoopLimit > 0 ? $rawLoopLimit : 1_000;

        $rawOutputLimit = $data['sandbox_output_size_limit'] ?? 1_048_576;
        $sandboxOutputSizeLimit = is_int($rawOutputLimit) && $rawOutputLimit > 0 ? $rawOutputLimit : 1_048_576;

        $rawWallClockInterval = $data['sandbox_wall_clock_check_interval'] ?? 500;
        $sandboxWallClockCheckInterval = is_int($rawWallClockInterval) && $rawWallClockInterval > 0 ? $rawWallClockInterval : 500;

        return new self(
            templatePaths: $templatePaths,
            cachePath: $cachePath,
            autoEscape: $autoEscape,
            activeTheme: $activeTheme,
            phpDirectiveAllowed: $phpDirectiveAllowed,
            sandboxMode: $sandboxMode,
            sandboxStepLimit: $sandboxStepLimit,
            sandboxLoopLimit: $sandboxLoopLimit,
            sandboxOutputSizeLimit: $sandboxOutputSizeLimit,
            sandboxWallClockCheckInterval: $sandboxWallClockCheckInterval,
        );
    }

    /**
     * Filter an array down to non-empty string values, re-indexed as a list.
     *
     * @param array<array-key, mixed> $items
     * @return list<string>
     */
    private static function filterStringList(array $items): array
    {
        $strings = [];

        foreach ($items as $item) {
            if (is_string($item) && $item !== '') {
                $strings[] = $item;
            }
        }

        return $strings;
    }
}
