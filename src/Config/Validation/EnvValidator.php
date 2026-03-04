<?php

declare(strict_types=1);

namespace Pulsar\Config\Validation;

use NoDiscard;
use Pulsar\Api\Api;

use function array_keys;
use function array_values;
use function file_exists;
use function file_get_contents;
use function preg_match_all;
use function trim;

/**
 * Validates environment variables against an .env.example template.
 *
 * Parses the .env.example file to discover required keys (lines without
 * default values or with empty values), then checks whether each is
 * defined in the current environment.
 */
#[Api(since: '1.0.0')]
final readonly class EnvValidator
{
    /**
     * Parse an .env.example file and return the list of required keys.
     *
     * A key is considered required if its line has no value or an empty value,
     * e.g., `DATABASE_URL=` or `API_KEY=`.
     *
     * @return list<string>
     */
    #[NoDiscard]
    public function parseRequiredKeys(string $envExamplePath): array
    {
        if (!file_exists($envExamplePath)) {
            return [];
        }

        $content = file_get_contents($envExamplePath);

        if ($content === false) {
            return [];
        }

        // Match lines like KEY= or KEY="" or KEY='' (empty/no value)
        if (preg_match_all('/^([A-Z][A-Z0-9_]*)=\s*["\']?\s*["\']?\s*$/m', $content, $matches) > 0) {
            return array_values(array_map(strval(...), $matches[1]));
        }

        return [];
    }

    /**
     * Parse all keys from an .env.example file (required and optional).
     *
     * @return list<string>
     */
    #[NoDiscard]
    public function parseAllKeys(string $envExamplePath): array
    {
        if (!file_exists($envExamplePath)) {
            return [];
        }

        $content = file_get_contents($envExamplePath);

        if ($content === false) {
            return [];
        }

        if (preg_match_all('/^([A-Z][A-Z0-9_]*)=/m', $content, $matches) > 0) {
            return array_values(array_map(strval(...), $matches[1]));
        }

        return [];
    }

    /**
     * Validate that all required environment variables are set.
     *
     * @param list<string> $requiredKeys Keys to check
     * @param array<string, string|null> $envValues Current environment values
     */
    #[NoDiscard]
    public function validate(array $requiredKeys, array $envValues): ConfigValidationResult
    {
        $errors = [];
        $definedKeys = array_keys($envValues);

        foreach ($requiredKeys as $key) {
            $value = $envValues[$key] ?? null;

            if ($value === null || trim($value) === '') {
                $errors[] = ConfigValidationError::required(
                    'env.' . $key,
                );
            }
        }

        return new ConfigValidationResult($errors);
    }

    /**
     * Find keys defined in .env.example but missing from the environment.
     *
     * @param list<string> $requiredKeys
     * @param array<string, string|null> $envValues
     * @return list<string>
     */
    #[NoDiscard]
    public function findMissing(array $requiredKeys, array $envValues): array
    {
        $missing = [];

        foreach ($requiredKeys as $key) {
            $value = $envValues[$key] ?? null;

            if ($value === null || trim($value) === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }
}
