<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Seo;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Content\Redirect;
use Pulsar\Extension\Cms\Content\RedirectRepositoryInterface;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Seo\RedirectManagerInterface;
use Pulsar\Extension\Cms\Support\UuidGenerator;

use function count;
use function explode;
use function in_array;
use function parse_url;
use function preg_match;
use function rawurldecode;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function substr;
use function trim;

use const PHP_URL_SCHEME;

/**
 * Redirect manager with chain collapse, open redirect protection, and CSV bulk import.
 */
#[Internal(reason: 'Use RedirectManagerInterface for public API')]
final readonly class RedirectManager implements RedirectManagerInterface
{
    /** Maximum redirect chain depth to prevent infinite loops. */
    private const int MAX_CHAIN_DEPTH = 10;

    public function __construct(
        private RedirectRepositoryInterface $repository,
        private LoggerInterface $logger,
    ) {}

    public function resolve(string $path, ?string $locale = null, ?string $tenantId = null): ?Redirect
    {
        $redirect = $this->repository->findByPath($path, $locale, $tenantId);

        if ($redirect === null) {
            return null;
        }

        // Follow chain to find the final destination
        $visited = [$path];
        $current = $redirect;
        $depth = 0;

        while ($depth < self::MAX_CHAIN_DEPTH) {
            $next = $this->repository->findByPath($current->toPath, $locale, $tenantId);

            if ($next === null) {
                break;
            }

            if (in_array($next->toPath, $visited, true)) {
                $this->logger->warning('Redirect cycle detected', [
                    'path' => $path,
                    'cycle_at' => $next->toPath,
                ]);

                break;
            }

            $visited[] = $next->toPath;
            $current = $next;
            $depth++;
        }

        // Record the hit on the original redirect
        $this->repository->incrementHits($redirect->id);

        // If we followed a chain, return a collapsed redirect pointing to the final destination
        if ($current !== $redirect) {
            return new Redirect(
                id: $redirect->id,
                tenantId: $redirect->tenantId,
                fromPath: $redirect->fromPath,
                toPath: $current->toPath,
                statusCode: $redirect->statusCode,
                locale: $redirect->locale,
                hits: $redirect->hits + 1,
                lastHitAt: new DateTimeImmutable(),
                createdAt: $redirect->createdAt,
                createdBy: $redirect->createdBy,
                reason: $redirect->reason,
            );
        }

        return $redirect;
    }

    public function create(
        string $fromPath,
        string $toPath,
        int $statusCode,
        string $createdBy,
        string $reason,
        ?string $locale = null,
        ?string $tenantId = null,
    ): Redirect {
        $this->validateTargetUrl($toPath);

        if (!in_array($statusCode, [301, 308], true)) {
            $statusCode = 301;
        }

        $redirect = new Redirect(
            id: UuidGenerator::v7(),
            tenantId: $tenantId,
            fromPath: $fromPath,
            toPath: $toPath,
            statusCode: $statusCode,
            locale: $locale,
            hits: 0,
            lastHitAt: null,
            createdAt: new DateTimeImmutable(),
            createdBy: $createdBy,
            reason: $reason,
        );

        $this->repository->save($redirect);

        $this->logger->info('Redirect created', [
            'id' => $redirect->id,
            'from' => $fromPath,
            'to' => $toPath,
            'status_code' => $statusCode,
        ]);

        return $redirect;
    }

    public function delete(string $redirectId): void
    {
        $this->repository->delete($redirectId);

        $this->logger->info('Redirect deleted', ['id' => $redirectId]);
    }

    public function importCsv(string $csvContent, string $createdBy, string $reason, ?string $tenantId = null): array
    {
        $lines = explode("\n", $csvContent);
        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($lines as $lineNumber => $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Skip CSV header row
            if ($lineNumber === 0 && str_contains(strtolower($line), 'from_path')) {
                continue;
            }

            $parts = str_getcsv($line, ',', '"', '');

            if (count($parts) < 2) {
                $errors[] = sprintf('Line %d: insufficient columns', $lineNumber + 1);
                $skipped++;

                continue;
            }

            $fromPath = trim($parts[0]);
            $toPath = trim($parts[1]);
            $statusCode = isset($parts[2]) ? (int) trim($parts[2]) : 301;

            if ($fromPath === '' || $toPath === '') {
                $errors[] = sprintf('Line %d: empty from_path or to_path', $lineNumber + 1);
                $skipped++;

                continue;
            }

            try {
                $this->create($fromPath, $toPath, $statusCode, $createdBy, $reason, tenantId: $tenantId);
                $imported++;
            } catch (CmsException $e) {
                $errors[] = sprintf('Line %d: %s', $lineNumber + 1, $e->getMessage());
                $skipped++;
            }
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    public function listAll(int $page = 1, int $perPage = 50, ?string $tenantId = null): array
    {
        return $this->repository->findAll($page, $perPage, $tenantId);
    }

    /**
     * Validate that a target URL is safe (not an open redirect).
     *
     * Rejects: javascript:, data:, vbscript:, protocol-relative URLs (//evil.com),
     * and any scheme other than http/https or relative paths.
     *
     * @throws CmsException If the URL is unsafe
     */
    private function validateTargetUrl(string $url): void
    {
        // URL-decode to prevent encoding bypass (e.g., %6A%61%76%61%73%63%72%69%70%74:)
        $decoded = rawurldecode($url);
        $lower = strtolower(trim($decoded));

        // Block dangerous schemes (case-insensitive, after URL decode)
        $dangerousSchemes = ['javascript:', 'data:', 'vbscript:', 'file:', 'ftp:'];

        foreach ($dangerousSchemes as $scheme) {
            if (str_starts_with($lower, $scheme)) {
                throw CmsException::openRedirectBlocked($url);
            }
        }

        // Block protocol-relative URLs (//evil.com)
        if (str_starts_with($lower, '//')) {
            throw CmsException::openRedirectBlocked($url);
        }

        // Block null bytes
        if (str_contains($decoded, "\0")) {
            throw CmsException::openRedirectBlocked($url);
        }

        // If it has a scheme, it must be http or https
        $parsedScheme = parse_url($decoded, PHP_URL_SCHEME);

        if ($parsedScheme !== null && $parsedScheme !== false) {
            $schemeLower = strtolower($parsedScheme);

            if (!in_array($schemeLower, ['http', 'https'], true)) {
                throw CmsException::openRedirectBlocked($url);
            }
        }

        // Block URLs that look like scheme:payload but bypassed parse_url
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $decoded) === 1) {
            $colonPos = strpos($decoded, ':');

            if ($colonPos === false) {
                return;
            }

            $candidateScheme = strtolower(substr($decoded, 0, $colonPos));

            if (!in_array($candidateScheme, ['http', 'https'], true)) {
                throw CmsException::openRedirectBlocked($url);
            }
        }
    }
}
