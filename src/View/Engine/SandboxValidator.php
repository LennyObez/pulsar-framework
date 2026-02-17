<?php

declare(strict_types=1);

namespace Pulsar\View\Engine;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\View\ViewConfig;
use Pulsar\View\ViewException;

use function array_diff;
use function array_unique;
use function array_values;
use function in_array;
use function preg_match_all;
use function strtolower;

use const PREG_OFFSET_CAPTURE;

/**
 * Runtime sandbox validator for compiled template output.
 *
 * Validates compiled PHP against a configurable allowlist of permitted
 * functions and classes. Unlike SandboxCompiler (which uses a denylist),
 * this validator uses an allowlist approach: only explicitly permitted
 * symbols are allowed.
 *
 * Operates at compile time to catch violations before any template
 * code executes. Enabled when ViewConfig::$sandboxMode is true.
 */
#[Internal(reason: 'Sandbox validation is an engine implementation detail')]
final readonly class SandboxValidator
{
    /** Functions always safe for template output (pure/presentation-only). */
    private const array DEFAULT_ALLOWED_FUNCTIONS = [
        // String functions
        'htmlspecialchars', 'htmlentities', 'nl2br', 'strtolower', 'strtoupper',
        'ucfirst', 'ucwords', 'lcfirst', 'trim', 'ltrim', 'rtrim', 'str_pad',
        'str_repeat', 'str_replace', 'str_contains', 'str_starts_with',
        'str_ends_with', 'substr', 'strlen', 'wordwrap', 'mb_strtolower',
        'mb_strtoupper', 'mb_substr', 'mb_strlen', 'mb_detect_encoding',
        'mb_convert_encoding',
        // Array functions (read-only)
        'count', 'array_key_exists', 'array_keys', 'array_values',
        'array_merge', 'array_map', 'array_filter', 'array_slice',
        'array_chunk', 'array_reverse', 'array_unique', 'array_column',
        'array_combine', 'array_flip', 'array_pop', 'array_shift',
        'array_search', 'array_sum', 'array_any', 'array_all',
        'in_array', 'sort', 'rsort', 'ksort', 'krsort', 'usort', 'uasort',
        'uksort', 'array_multisort', 'implode', 'explode', 'join',
        // Type checking
        'is_string', 'is_int', 'is_float', 'is_bool', 'is_array', 'is_null',
        'is_numeric', 'is_callable', 'is_object', 'is_iterable', 'isset',
        'empty', 'get_debug_type', 'gettype',
        // Number formatting
        'number_format', 'round', 'ceil', 'floor', 'abs', 'min', 'max',
        'intval', 'floatval', 'strval',
        // Date/time (read-only)
        'date', 'time', 'strtotime', 'gmdate', 'mktime',
        // JSON (read-only)
        'json_encode',
        // Escaping
        'urlencode', 'rawurlencode', 'http_build_query',
        // Misc safe
        'sprintf', 'printf', 'vsprintf', 'str_word_count',
        'preg_match', 'preg_replace', 'preg_split',
        'array_key_first', 'array_key_last',
        'range', 'str_getcsv',
        // Cast
        'intdiv', 'fmod',
        // Internal template helpers
        'ob_start', 'ob_get_clean', 'ob_end_clean', 'ob_get_level',
        'extract', 'compact',
    ];

    /** Classes always safe for template use (immutable/read-only). */
    private const array DEFAULT_ALLOWED_CLASSES = [
        'DateTimeImmutable', 'DateTimeInterface', 'DateInterval',
        'IntlDateFormatter', 'NumberFormatter', 'Locale',
        'stdClass', 'ArrayObject', 'SplFixedArray',
        'JsonSerializable', 'Stringable',
    ];

    /** Functions that may appear in compiled template infrastructure. */
    private const array INFRASTRUCTURE_TOKENS = [
        'echo', 'print', 'foreach', 'endforeach', 'if', 'elseif', 'else',
        'endif', 'for', 'endfor', 'while', 'endwhile', 'switch', 'case',
        'default', 'endswitch', 'match', 'unset', 'list',
    ];

    /** @var list<string> */
    private array $allowedFunctions;

    /** @var list<string> */
    private array $allowedClasses;

    /**
     * @param list<string> $additionalFunctions Extra functions to permit
     * @param list<string> $additionalClasses Extra classes to permit
     * @param list<string> $removedFunctions Functions to remove from the default allowlist
     * @param list<string> $removedClasses Classes to remove from the default allowlist
     */
    public function __construct(
        array $additionalFunctions = [],
        array $additionalClasses = [],
        array $removedFunctions = [],
        array $removedClasses = [],
    ) {
        $baseFunctions = array_diff(self::DEFAULT_ALLOWED_FUNCTIONS, $removedFunctions);
        $this->allowedFunctions = array_values(array_unique([...$baseFunctions, ...$additionalFunctions]));

        $baseClasses = array_diff(self::DEFAULT_ALLOWED_CLASSES, $removedClasses);
        $this->allowedClasses = array_values(array_unique([...$baseClasses, ...$additionalClasses]));
    }

    /**
     * Create a validator from a ViewConfig.
     *
     * Returns null if sandbox mode is disabled.
     */
    #[NoDiscard]
    public static function fromConfig(ViewConfig $config): ?self
    {
        if (!$config->sandboxMode) {
            return null;
        }

        return new self();
    }

    /**
     * Validate compiled template output against the allowlist.
     *
     * @param string $compiledOutput The compiled PHP code to validate
     * @param string $templateName Template name for error context
     *
     * @throws ViewException If a disallowed function or class is found
     */
    public function validate(string $compiledOutput, string $templateName): void
    {
        $this->validateFunctions($compiledOutput, $templateName);
        $this->validateClasses($compiledOutput, $templateName);
    }

    /**
     * Check all function calls against the allowlist.
     *
     * @throws ViewException If a function call is not on the allowlist
     */
    private function validateFunctions(string $compiledOutput, string $templateName): void
    {
        if (preg_match_all('/\b([a-zA-Z_]\w*)\s*\(/', $compiledOutput, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return;
        }

        $constructorNames = $this->extractConstructorNames($compiledOutput);
        $seen = [];

        foreach ($matches[1] as [$function, $offset]) {
            $lower = strtolower($function);

            if (isset($seen[$lower])) {
                continue;
            }

            $seen[$lower] = true;

            if ($this->isInfrastructureToken($lower)) {
                continue;
            }

            // Skip class constructors: validated separately by validateClasses()
            if (isset($constructorNames[$function])) {
                continue;
            }

            if (!in_array($lower, $this->allowedFunctions, true)) {
                throw ViewException::sandboxViolation($templateName, $function, 'function');
            }
        }
    }

    /**
     * Extract class names used in `new ClassName()` expressions.
     *
     * @return array<string, true>
     */
    private function extractConstructorNames(string $compiledOutput): array
    {
        $names = [];

        if (preg_match_all('/\bnew\s+([a-zA-Z_]\w*)/', $compiledOutput, $matches) > 0) {
            foreach ($matches[1] as $name) {
                $names[$name] = true;
            }
        }

        return $names;
    }

    /**
     * Check all class references against the allowlist.
     *
     * @throws ViewException If a class reference is not on the allowlist
     */
    private function validateClasses(string $compiledOutput, string $templateName): void
    {
        if (preg_match_all('/\bnew\s+([A-Z]\w*)|([A-Z]\w*)\s*::/', $compiledOutput, $matches) === 0) {
            return;
        }

        $referencedClasses = [];

        foreach ($matches[1] as $match) {
            if ($match !== '') {
                $referencedClasses[] = $match;
            }
        }

        foreach ($matches[2] as $match) {
            if ($match !== '') {
                $referencedClasses[] = $match;
            }
        }

        $referencedClasses = array_values(array_unique($referencedClasses));

        foreach ($referencedClasses as $class) {
            if (!in_array($class, $this->allowedClasses, true)) {
                throw ViewException::sandboxViolation($templateName, $class, 'class');
            }
        }
    }

    /**
     * Check whether a token is a PHP language construct or control-flow keyword.
     */
    #[NoDiscard]
    private function isInfrastructureToken(string $token): bool
    {
        return in_array($token, self::INFRASTRUCTURE_TOKENS, true);
    }

    /**
     * Get the current function allowlist.
     *
     * @return list<string>
     */
    #[NoDiscard]
    public function allowedFunctions(): array
    {
        return $this->allowedFunctions;
    }

    /**
     * Get the current class allowlist.
     *
     * @return list<string>
     */
    #[NoDiscard]
    public function allowedClasses(): array
    {
        return $this->allowedClasses;
    }

    /**
     * Check whether a specific function is allowed.
     */
    #[NoDiscard]
    public function isFunctionAllowed(string $function): bool
    {
        return in_array(strtolower($function), $this->allowedFunctions, true)
            || $this->isInfrastructureToken(strtolower($function));
    }

    /**
     * Check whether a specific class is allowed.
     */
    #[NoDiscard]
    public function isClassAllowed(string $class): bool
    {
        return in_array($class, $this->allowedClasses, true);
    }
}
