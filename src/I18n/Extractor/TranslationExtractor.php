<?php

declare(strict_types=1);

namespace Pulsar\I18n\Extractor;

use Pulsar\Api\Api;

use function count;
use function file_get_contents;
use function in_array;
use function is_array;
use function is_dir;
use function ksort;
use function ltrim;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function token_get_all;

use const T_CONSTANT_ENCAPSED_STRING;
use const T_OBJECT_OPERATOR;
use const T_STRING;

/**
 * Scans PHP source files for translation function calls.
 *
 * Extracts keys from `__('key')`, `trans('key')`, and
 * `$translator->translate('key')` calls using PHP's tokenizer.
 */
#[Api(since: '1.0.0')]
final class TranslationExtractor
{
    /** @var list<string> Function names to detect */
    private const array FUNCTION_NAMES = ['__', 'trans', 'translate'];

    /**
     * Extract translation keys from all PHP files in a directory.
     *
     * @param string $directory Directory to scan
     * @param string $basePath Project root for relative paths
     */
    public function extract(string $directory, string $basePath = ''): ExtractionResult
    {
        $keys = [];

        $this->scanDirectory($directory, $basePath, $keys);

        // Sort domains and keys for determinism
        ksort($keys);

        foreach ($keys as &$domainKeys) {
            ksort($domainKeys);
        }

        return new ExtractionResult($keys);
    }

    /**
     * @param array<string, array<string, list<string>>> $keys
     */
    private function scanDirectory(string $directory, string $basePath, array &$keys): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);

        if (!is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($path)) {
                $this->scanDirectory($path, $basePath, $keys);
                continue;
            }

            if (!str_ends_with($entry, '.php')) {
                continue;
            }

            $this->extractFromFile($path, $basePath, $keys);
        }
    }

    /**
     * @param array<string, array<string, list<string>>> $keys
     */
    private function extractFromFile(string $filePath, string $basePath, array &$keys): void
    {
        $contents = file_get_contents($filePath);

        if ($contents === false) {
            return;
        }

        $tokens = token_get_all($contents);
        $tokenCount = count($tokens);

        $relativePath = $filePath;

        if ($basePath !== '' && str_starts_with($filePath, $basePath)) {
            $relativePath = ltrim(substr($filePath, strlen($basePath)), DIRECTORY_SEPARATOR);
        }

        // Normalize to forward slashes for cross-platform determinism
        $relativePath = str_replace('\\', '/', $relativePath);

        for ($i = 0; $i < $tokenCount; $i++) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                continue;
            }

            // Check for function name: __, trans, or ->translate
            if ($token[0] === T_STRING && in_array($token[1], self::FUNCTION_NAMES, true)) {
                // For 'translate', check it's a method call (preceded by ->)
                if ($token[1] === 'translate') {
                    $prevIndex = $this->findPreviousNonWhitespace($tokens, $i);

                    if ($prevIndex === null || !is_array($tokens[$prevIndex]) || $tokens[$prevIndex][0] !== T_OBJECT_OPERATOR) {
                        continue;
                    }
                }

                $key = $this->extractStringArgument($tokens, $i + 1, $tokenCount);

                if ($key !== null) {
                    $domain = 'messages';
                    $keys[$domain][$key][] = $relativePath . ':' . $token[2];
                }
            }
        }
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     */
    private function extractStringArgument(array $tokens, int $startIndex, int $tokenCount): ?string
    {
        // Skip whitespace to find opening parenthesis
        $i = $startIndex;

        while ($i < $tokenCount && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
            $i++;
        }

        if ($i >= $tokenCount || $tokens[$i] !== '(') {
            return null;
        }

        $i++;

        // Skip whitespace to find string literal
        while ($i < $tokenCount && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
            $i++;
        }

        if ($i >= $tokenCount || !is_array($tokens[$i]) || $tokens[$i][0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        $raw = $tokens[$i][1];

        // Strip quotes
        if (strlen($raw) >= 2 && ($raw[0] === "'" || $raw[0] === '"')) {
            return substr($raw, 1, -1);
        }

        return $raw;
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     */
    private function findPreviousNonWhitespace(array $tokens, int $currentIndex): ?int
    {
        for ($i = $currentIndex - 1; $i >= 0; $i--) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_WHITESPACE) {
                return $i;
            }
        }

        return null;
    }
}
