<?php

declare(strict_types=1);

namespace Pulsar\View;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Config\ReportsUnknownKeys;
use Pulsar\Config\UnknownKeys;
use Pulsar\Support\Coerce;

use function is_array;
use function is_string;

/**
 * Typed configuration DTO for `config/view.php`.
 *
 * Controls template paths, caching, escaping defaults, theme selection,
 * and untrusted template sandbox limits.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ViewConfig implements ReportsUnknownKeys
{
    /** Keys recognised in config/view.php. */
    private const array KNOWN_KEYS = [
        'template_paths', 'cache_path', 'auto_escape', 'active_theme', 'php_directive_allowed',
        'sandbox_mode', 'sandbox_step_limit', 'sandbox_loop_limit', 'sandbox_output_size_limit',
        'sandbox_wall_clock_check_interval',
    ];

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
        /** @var list<string> */
        public array $unknownKeys = [],
    ) {}

    /**
     * @return list<string>
     */
    public function unknownConfigKeys(): array
    {
        return $this->unknownKeys;
    }

    /**
     * Build a ViewConfig from a raw config array.
     *
     * @param array{
     *     template_paths?: array<array-key, mixed>,
     *     cache_path?: string,
     *     auto_escape?: bool,
     *     active_theme?: string,
     *     php_directive_allowed?: bool,
     *     sandbox_mode?: bool,
     *     sandbox_step_limit?: int,
     *     sandbox_loop_limit?: int,
     *     sandbox_output_size_limit?: int,
     *     sandbox_wall_clock_check_interval?: int,
     * } $data Raw array from config/view.php
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $stepLimit = Coerce::integerLike($data['sandbox_step_limit'] ?? null, 10_000);
        $loopLimit = Coerce::integerLike($data['sandbox_loop_limit'] ?? null, 1_000);
        $outputLimit = Coerce::integerLike($data['sandbox_output_size_limit'] ?? null, 1_048_576);
        $wallClockInterval = Coerce::integerLike($data['sandbox_wall_clock_check_interval'] ?? null, 500);

        return new self(
            templatePaths: self::filterStringList($data['template_paths'] ?? null),
            cachePath: Coerce::string($data['cache_path'] ?? null),
            autoEscape: ($data['auto_escape'] ?? true) !== false,
            activeTheme: Coerce::string($data['active_theme'] ?? null, 'default'),
            phpDirectiveAllowed: ($data['php_directive_allowed'] ?? false) === true,
            sandboxMode: ($data['sandbox_mode'] ?? false) === true,
            sandboxStepLimit: $stepLimit > 0 ? $stepLimit : 10_000,
            sandboxLoopLimit: $loopLimit > 0 ? $loopLimit : 1_000,
            sandboxOutputSizeLimit: $outputLimit > 0 ? $outputLimit : 1_048_576,
            sandboxWallClockCheckInterval: $wallClockInterval > 0 ? $wallClockInterval : 500,
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
        );
    }

    /**
     * Filter an array down to non-empty string values, re-indexed as a list.
     *
     * @return list<string>
     */
    private static function filterStringList(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $strings = [];
        /** @var mixed $item */
        foreach ($items as $item) {
            if (is_string($item) && $item !== '') {
                $strings[] = $item;
            }
        }

        return $strings;
    }
}
