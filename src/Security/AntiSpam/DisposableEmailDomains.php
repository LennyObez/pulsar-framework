<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam;

use Pulsar\Api\Internal;

use function array_slice;
use function count;
use function explode;
use function implode;
use function is_file;
use function preg_split;
use function str_starts_with;
use function strtolower;
use function trim;

/**
 * A set of disposable / throwaway e-mail domains, matched against a sender
 * domain and its registrable parents (so a subdomain like x.mailinator.com is
 * caught by the entry mailinator.com).
 *
 * The framework ships a bundled list; a project extends it via config with a
 * file path and/or an inline array. Matching is exact per label boundary — a
 * listed domain never matches a bare public suffix.
 */
#[Internal(reason: 'Disposable-domain set for EmailDomainCheck')]
final readonly class DisposableEmailDomains
{
    /**
     * @var array<string, true> Normalised domain => present
     */
    private array $domains;

    /**
     * @param list<string> $domains Raw domain entries (comments/blank lines ignored)
     */
    public function __construct(array $domains)
    {
        $set = [];

        foreach ($domains as $domain) {
            $normalized = strtolower(trim($domain, ". \t\r\n"));

            if ($normalized === '' || str_starts_with($normalized, '#')) {
                continue;
            }

            $set[$normalized] = true;
        }

        $this->domains = $set;
    }

    /**
     * True when the domain, or any parent down to (but excluding) the bare
     * public suffix, is a listed disposable domain.
     */
    public function contains(string $domain): bool
    {
        $normalized = strtolower(trim($domain, ". \t\r\n"));

        if ($normalized === '') {
            return false;
        }

        $labels = explode('.', $normalized);
        $count = count($labels);

        // Check the full domain and each parent that still has at least two
        // labels: a.b.mailinator.com -> b.mailinator.com -> mailinator.com,
        // never the bare 'com'.
        for ($i = 0; $i <= $count - 2; $i++) {
            $candidate = implode('.', array_slice($labels, $i));

            if (isset($this->domains[$candidate])) {
                return true;
            }
        }

        return false;
    }

    public function count(): int
    {
        return count($this->domains);
    }

    /**
     * Parse a newline-delimited domain list file into raw entries. Missing
     * files yield an empty list so a bad override path can never fatal the
     * pipeline. Lines starting with '#' and blank lines are ignored.
     *
     * @return list<string>
     */
    public static function parseListFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', $contents);

        if ($lines === false) {
            return [];
        }

        $entries = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $entries[] = $trimmed;
        }

        return $entries;
    }
}
