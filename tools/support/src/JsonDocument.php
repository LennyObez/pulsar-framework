<?php

declare(strict_types=1);

namespace Pulsar\Tooling\Support;

use JsonException;
use RuntimeException;

use function array_is_list;
use function is_array;
use function is_bool;
use function is_file;
use function is_float;
use function is_int;
use function is_readable;
use function is_string;
use function sprintf;

/**
 * Typed, fail-loud access to a decoded JSON document.
 *
 * The repository's tooling reads a lot of JSON: composer.lock, the boundary
 * baseline, the API snapshot, coverage reports, benchmark baselines. Every one of
 * those scripts had written the same shape:
 *
 *     $data = json_decode(file_get_contents($path), true);
 *     foreach ($data['violations'] as $v) { $key = $v['file'] . '|' . $v['import']; }
 *
 * which is `mixed` all the way down. That is not merely untyped — in a gate it is
 * unsound. A baseline whose entries lost their `file` key keys on `'|import'` and
 * silently exempts an unrelated violation; an SBOM whose `packages` came back as a
 * string emits `(string) mixed` into a supply-chain artefact. Both failures are
 * invisible, and both look like success.
 *
 * This wraps the decode once so a malformed document fails at the point of use,
 * naming the file and the key, instead of degrading into a wrong answer.
 */
final readonly class JsonDocument
{
    /**
     * @param array<array-key, mixed> $data
     * @param string $source Human-readable origin, used in every error message
     */
    private function __construct(private array $data, private string $source) {}

    public static function fromFile(string $path): self
    {
        // Checked before reading rather than after: file_get_contents() on a
        // missing or unreadable path raises a warning on its way to returning
        // false, so the failure would surface twice — once as engine noise the
        // caller cannot catch, once as the exception below.
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException(sprintf('Could not read JSON from "%s".', $path));
        }

        $json = file_get_contents($path);

        if ($json === false) {
            throw new RuntimeException(sprintf('Could not read JSON from "%s".', $path));
        }

        return self::fromString($json, $path);
    }

    public static function fromString(string $json, string $source): self
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf('%s is not valid JSON: %s', $source, $e->getMessage()), 0, $e);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('%s did not decode to a JSON object or array.', $source));
        }

        return new self($decoded, $source);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data, string $source): self
    {
        return new self($data, $source);
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    public function source(): string
    {
        return $this->source;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * The document as a string-keyed map, which is what a JSON *object* is.
     *
     * toArray() has to admit integer keys because a JSON array decodes to a list.
     * Consumers that take an object — a composer.json, a composer.lock — need the
     * narrower type, and getting it here beats every caller re-filtering.
     *
     * @return array<string, mixed>
     */
    public function toMap(): array
    {
        $map = [];

        /** @var mixed $value */
        foreach ($this->data as $key => $value) {
            if (is_string($key)) {
                $map[$key] = $value;
            }
        }

        return $map;
    }

    public function string(string $key): string
    {
        $value = $this->data[$key] ?? null;

        if (!is_string($value)) {
            throw $this->missing($key, 'a string');
        }

        return $value;
    }

    public function stringOr(string $key, string $default): string
    {
        $value = $this->data[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    public function int(string $key): int
    {
        $value = $this->data[$key] ?? null;

        if (!is_int($value)) {
            throw $this->missing($key, 'an integer');
        }

        return $value;
    }

    public function intOr(string $key, int $default): int
    {
        $value = $this->data[$key] ?? null;

        return is_int($value) ? $value : $default;
    }

    /**
     * An optional integer, distinguishing "absent or not a number" from a real 0.
     *
     * A metric that is genuinely zero and one the runtime could not report mean
     * different things, and collapsing them into a default would print one as the
     * other.
     */
    public function intOrNull(string $key): ?int
    {
        $value = $this->data[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    public function floatOr(string $key, float $default): float
    {
        $value = $this->data[$key] ?? null;

        return is_int($value) || is_float($value) ? (float) $value : $default;
    }

    /**
     * A nested object, required to be present.
     */
    public function child(string $key): self
    {
        $value = $this->data[$key] ?? null;

        if (!is_array($value)) {
            throw $this->missing($key, 'an object');
        }

        return new self($value, $this->source . '.' . $key);
    }

    /**
     * A nested object, empty when the key is absent.
     */
    public function childOrEmpty(string $key): self
    {
        return isset($this->data[$key]) ? $this->child($key) : new self([], $this->source . '.' . $key);
    }

    /**
     * Every string value in a `{"name": "version"}` style map, skipping the rest.
     *
     * @return array<string, string>
     */
    public function stringMap(string $key): array
    {
        $result = [];

        /** @var mixed $value */
        foreach ($this->childOrEmpty($key)->data as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $result[$name] = $value;
            }
        }

        return $result;
    }

    /**
     * Every string entry of a JSON array, skipping the rest.
     *
     * @return list<string>
     */
    public function stringList(string $key): array
    {
        $result = [];

        /** @var mixed $value */
        foreach ($this->childOrEmpty($key)->data as $value) {
            if (is_string($value)) {
                $result[] = $value;
            }
        }

        return $result;
    }

    /**
     * Each object in a JSON array of objects, as its own document.
     *
     * Non-object entries are rejected rather than skipped: a list of records with
     * a stray scalar in it means the document is not what the caller believes, and
     * quietly dropping the entry is how a gate loses a violation.
     *
     * @return list<self>
     */
    public function children(string $key): array
    {
        $container = $this->childOrEmpty($key);

        if ($container->data !== [] && !array_is_list($container->data)) {
            throw new RuntimeException(sprintf('%s.%s is an object, not a list of objects.', $this->source, $key));
        }

        $documents = [];

        /** @var mixed $entry */
        foreach ($container->data as $index => $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException(sprintf('%s.%s[%s] is not an object.', $this->source, $key, (string) $index));
            }

            $documents[] = new self($entry, sprintf('%s.%s[%s]', $this->source, $key, (string) $index));
        }

        return $documents;
    }

    /**
     * Each value of a nested JSON object, as its own document, keyed by its name.
     *
     * @return array<string, self>
     */
    public function childMap(string $key): array
    {
        return $this->childOrEmpty($key)->documents();
    }

    /**
     * Each value of *this* object, as its own document, keyed by its name.
     *
     * For documents whose records sit at the root — a profile matrix, a lock
     * file's package map — where there is no wrapping key to descend into.
     *
     * @return array<string, self>
     */
    public function documents(): array
    {
        $documents = [];

        /** @var mixed $entry */
        foreach ($this->data as $name => $entry) {
            if (!is_string($name)) {
                continue;
            }

            if (!is_array($entry)) {
                throw new RuntimeException(sprintf('%s.%s is not an object.', $this->source, $name));
            }

            $documents[$name] = new self($entry, sprintf('%s.%s', $this->source, $name));
        }

        return $documents;
    }

    public function boolOr(string $key, bool $default): bool
    {
        $value = $this->data[$key] ?? null;

        return is_bool($value) ? $value : $default;
    }

    private function missing(string $key, string $expected): RuntimeException
    {
        return new RuntimeException(sprintf('%s.%s is not %s.', $this->source, $key, $expected));
    }
}
