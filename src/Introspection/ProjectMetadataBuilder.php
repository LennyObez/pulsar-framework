<?php

declare(strict_types=1);

namespace Pulsar\Introspection;

use Closure;
use Pulsar\Api\Api;
use Pulsar\Introspection\Data\ContributedMetadata;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;

use function array_keys;
use function array_map;
use function array_slice;
use function count;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_resource;
use function is_string;
use function json_encode;
use function mb_strlen;
use function preg_replace;
use function sprintf;
use function strtolower;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * Mutable builder scoped to a single metadata contributor.
 *
 * Enforces per-contributor resource limits (section count, nesting depth,
 * keys per object, and total serialized size) to prevent any single
 * contributor from degrading the introspection snapshot.
 * @api
 */
#[Api(since: '1.0.0')]
final class ProjectMetadataBuilder
{
    private const int MAX_SECTIONS = 64;
    private const int MAX_DEPTH = 8;
    private const int MAX_KEYS_PER_OBJECT = 200;
    private const int MAX_BYTES = 262_144; // 256 KB

    /** @var array<string, array<string, mixed>> */
    private array $sections = [];

    /** @var list<string> */
    private array $warnings = [];

    private int $estimatedBytes = 0;

    private function __construct(
        private readonly string $contributorId,
    ) {}

    /**
     * Create a new builder scoped to the given contributor.
     */
    public static function forContributor(string $contributorId): self
    {
        return new self($contributorId);
    }

    /**
     * Add a named section of metadata.
     *
     * The key is normalized to lowercase alphanumeric with dots, hyphens, and
     * underscores. Duplicate keys use last-write-wins semantics with a warning.
     *
     * @param array<string, mixed> $data
     */
    public function addSection(string $key, array $data): void
    {
        $normalizedKey = self::normalizeKey($key);

        if ($normalizedKey === '') {
            $this->warnings[] = sprintf(
                'Contributor "%s": section key "%s" normalized to empty string, skipped.',
                $this->contributorId,
                $key,
            );

            return;
        }

        if (!isset($this->sections[$normalizedKey]) && count($this->sections) >= self::MAX_SECTIONS) {
            $this->warnings[] = sprintf(
                'Contributor "%s": maximum section count (%d) reached, section "%s" skipped.',
                $this->contributorId,
                self::MAX_SECTIONS,
                $normalizedKey,
            );

            return;
        }

        $validationErrors = [];
        $validated = $this->validateData($data, $validationErrors, depth: 0);

        foreach ($validationErrors as $error) {
            $this->warnings[] = sprintf(
                'Contributor "%s", section "%s": %s',
                $this->contributorId,
                $normalizedKey,
                $error,
            );
        }

        $encoded = json_encode($validated, JSON_THROW_ON_ERROR);
        $sectionBytes = mb_strlen($encoded, '8bit');

        if ($this->estimatedBytes + $sectionBytes > self::MAX_BYTES) {
            $this->warnings[] = sprintf(
                'Contributor "%s": adding section "%s" (%d bytes) would exceed %d byte limit, skipped.',
                $this->contributorId,
                $normalizedKey,
                $sectionBytes,
                self::MAX_BYTES,
            );

            return;
        }

        if (isset($this->sections[$normalizedKey])) {
            $oldEncoded = json_encode($this->sections[$normalizedKey], JSON_THROW_ON_ERROR);
            $this->estimatedBytes -= mb_strlen($oldEncoded, '8bit');

            $this->warnings[] = sprintf(
                'Contributor "%s": section "%s" already exists, overwriting (last-write-wins).',
                $this->contributorId,
                $normalizedKey,
            );
        }

        $this->sections[$normalizedKey] = $validated;
        $this->estimatedBytes += $sectionBytes;
    }

    /**
     * Build the final contributed metadata, scrubbing sensitive values.
     */
    public function build(SensitiveDataScrubber $scrubber): ContributedMetadata
    {
        /** @var array<string, array<string, mixed>> $scrubbedSections */
        $scrubbedSections = array_map(
            static fn(array $section): array => $scrubber->scrub($section),
            $this->sections,
        );

        $encoded = json_encode($scrubbedSections, JSON_THROW_ON_ERROR);
        $sizeBytes = mb_strlen($encoded, '8bit');

        return new ContributedMetadata(
            contributorId: $this->contributorId,
            sections: $scrubbedSections,
            sizeBytes: $sizeBytes,
        );
    }

    /**
     * Get accumulated validation warnings.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Normalize a section key: lowercase, trim, keep only [a-z0-9_.-].
     */
    private static function normalizeKey(string $key): string
    {
        $key = strtolower(trim($key));

        return preg_replace('/[^a-z0-9_.-]/', '', $key) ?? '';
    }

    /**
     * Recursively validate data, stripping disallowed types and enforcing depth/key limits.
     *
     * @param array<string, mixed> $data
     * @param list<string>         $errors Collected by reference
     *
     * @return array<string, mixed>
     */
    private function validateData(array $data, array &$errors, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            $errors[] = sprintf('Maximum nesting depth (%d) exceeded, subtree truncated.', self::MAX_DEPTH);

            return [];
        }

        $keys = array_keys($data);

        if (count($keys) > self::MAX_KEYS_PER_OBJECT) {
            $errors[] = sprintf(
                'Object has %d keys, exceeding limit of %d. Extra keys truncated.',
                count($keys),
                self::MAX_KEYS_PER_OBJECT,
            );
            $keys = array_slice($keys, 0, self::MAX_KEYS_PER_OBJECT);
        }

        $result = [];

        foreach ($keys as $key) {
            /** @var mixed $value */
            $value = $data[$key];

            if (is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $result = [...$result, $key => $value];
            } elseif (is_array($value)) {
                /** @var array<string, mixed> $value */
                $result = [...$result, $key => $this->validateData($value, $errors, $depth + 1)];
            } elseif ($value instanceof Closure) {
                $errors[] = sprintf('Key "%s": closures are not allowed, removed.', $key);
            } elseif (is_object($value)) {
                $errors[] = sprintf('Key "%s": objects are not allowed, removed.', $key);
            } elseif (is_resource($value)) {
                $errors[] = sprintf('Key "%s": resources are not allowed, removed.', $key);
            }
        }

        return $result;
    }
}
