<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Internal\Security;

use Pulsar\Api\Internal;
use Pulsar\Extension\McpServer\Exception\McpException;

use function file_exists;
use function ltrim;
use function mb_strlen;
use function preg_match;
use function realpath;
use function str_contains;
use function str_replace;
use function str_starts_with;

use const DIRECTORY_SEPARATOR;

/**
 * Validates and sanitizes MCP tool input parameters against strict format constraints.
 */
#[Internal]
final readonly class ParamValidator
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    private const string CLIENT_ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';
    private const string FILTER_PATTERN = '/^[A-Za-z0-9_:.\\\\-]{1,256}$/';
    private const int FILTER_MAX_LENGTH = 256;

    public static function validateClientId(string $clientId): string
    {
        if (preg_match(self::CLIENT_ID_PATTERN, $clientId) !== 1) {
            throw McpException::protocolError('Invalid client_id format', -32602);
        }

        return $clientId;
    }

    public static function validateFilter(?string $filter): ?string
    {
        if ($filter === null) {
            return null;
        }

        if (mb_strlen($filter) > self::FILTER_MAX_LENGTH) {
            throw McpException::protocolError('Filter exceeds maximum length', -32602);
        }

        if (preg_match(self::FILTER_PATTERN, $filter) !== 1) {
            throw McpException::protocolError('Filter contains invalid characters', -32602);
        }

        return $filter;
    }

    public static function validatePath(?string $path, string $projectRoot): ?string
    {
        if ($path === null) {
            return null;
        }

        if (str_contains($path, '..')) {
            throw McpException::protocolError('Path traversal not allowed', -32602);
        }

        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $absolute = $projectRoot . DIRECTORY_SEPARATOR . ltrim($normalized, DIRECTORY_SEPARATOR);

        if (file_exists($absolute)) {
            $real = realpath($absolute);

            if ($real === false) {
                throw McpException::protocolError('Path escapes project root', -32602);
            }

            if (!str_starts_with($real, realpath($projectRoot) ?: $projectRoot)) {
                throw McpException::protocolError('Path escapes project root', -32602);
            }

            return $real;
        }

        return $absolute;
    }

    public static function validateType(string $type): string
    {
        if ($type !== 'php' && $type !== 'js') {
            throw McpException::protocolError('type must be "php" or "js"', -32602);
        }

        return $type;
    }

    public static function validateAnalyzer(string $analyzer): string
    {
        if ($analyzer !== 'phpstan' && $analyzer !== 'psalm') {
            throw McpException::protocolError('analyzer must be "phpstan" or "psalm"', -32602);
        }

        return $analyzer;
    }
}
