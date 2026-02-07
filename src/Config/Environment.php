<?php

declare(strict_types=1);

namespace Pulsar\Config;

use function array_key_exists;
use function is_file;
use function is_readable;
use function preg_replace;
use function rtrim;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Config\Exception\ConfigException;

/**
 * Loads environment variables from the OS and an optional `.env` file.
 *
 * OS environment variables always take precedence over `.env` file values.
 * The `.env` parser supports `KEY=VALUE` lines, `#` comments, and blank lines.
 * No interpolation is performed.
 */
#[Api]
final class Environment
{
    /**
     * Merged environment variables (OS + file, OS wins).
     *
     * @var array<string, string>
     */
    private array $variables;

    /**
     * @param array<string, string> $variables
     */
    private function __construct(array $variables)
    {
        $this->variables = $variables;
    }

    /**
     * Create an Environment instance from OS env vars and an optional `.env` file.
     *
     * OS vars are read first. If an `.env` file path is provided and exists,
     * its values are loaded but never override existing OS vars.
     */
    #[NoDiscard]
    public static function load(?string $envFilePath = null): self
    {
        $osVars = self::readOsVars();
        $fileVars = [];

        if ($envFilePath !== null && is_file($envFilePath) && is_readable($envFilePath)) {
            $fileVars = self::parseEnvFile($envFilePath);
        }

        // OS vars take precedence: file vars fill in gaps only
        $merged = $fileVars;
        foreach ($osVars as $key => $value) {
            $merged[$key] = $value;
        }

        return new self($merged);
    }

    /**
     * Get an environment variable value.
     */
    #[NoDiscard]
    public function get(string $key, ?string $default = null): ?string
    {
        return $this->variables[$key] ?? $default;
    }

    /**
     * Get a required environment variable or throw.
     *
     * @throws ConfigException If the variable is not set.
     */
    public function require(string $key): string
    {
        if (!array_key_exists($key, $this->variables)) {
            throw ConfigException::missingRequired($key, 'environment');
        }

        return $this->variables[$key];
    }

    /**
     * Check if an environment variable is set.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->variables);
    }

    /**
     * Resolve the application environment mode.
     */
    public function resolveMode(): EnvironmentMode
    {
        $env = $this->get('APP_ENV');

        if ($env === null) {
            return EnvironmentMode::Local;
        }

        $mode = EnvironmentMode::tryFrom($env);

        return $mode ?? EnvironmentMode::Local;
    }

    /**
     * Get all environment variables.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->variables;
    }

    /**
     * Read OS-level environment variables.
     *
     * @return array<string, string>
     */
    private static function readOsVars(): array
    {
        /** @var array<string, string> $env */
        $env = getenv();

        return $env;
    }

    /**
     * Parse a `.env` file into key-value pairs.
     *
     * Supports:
     * - `KEY=VALUE`
     * - `export KEY=VALUE` (export prefix stripped)
     * - `#` comments (full-line and inline on unquoted values)
     * - Blank lines
     * - Quoted values (single and double quotes stripped from both ends)
     *
     * @return array<string, string>
     */
    private static function parseEnvFile(string $path): array
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            return [];
        }

        $vars = [];
        $lines = explode("\n", $contents);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip blank lines and comments
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Strip export prefix
            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }

            $equalsPos = strpos($line, '=');

            if ($equalsPos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $equalsPos));
            $value = trim(substr($line, $equalsPos + 1));

            // Strip inline comments for unquoted values
            if ($value !== '' && $value[0] !== '"' && $value[0] !== "'") {
                $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;
                $value = rtrim($value);
            }

            // Strip surrounding quotes
            if (
                ($value !== '')
                && (($value[0] === '"' && str_ends_with($value, '"'))
                    || ($value[0] === "'" && str_ends_with($value, "'")))
            ) {
                $value = substr($value, 1, -1);
            }

            if ($key !== '') {
                $vars[$key] = $value;
            }
        }

        return $vars;
    }
}
