<?php

declare(strict_types=1);

namespace Pulsar\Extensibility;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extensibility\Exception\ExtensionException;

use function array_fill_keys;
use function array_intersect_key;
use function array_key_exists;
use function array_keys;
use function array_unique;
use function array_values;
use function is_array;

/**
 * The configuration each extension ships, keyed by section name.
 *
 * Sixteen bundled extensions ship a `config/<name>.php`, and eleven service
 * providers expected to read it back from the container under a `config.<name>`
 * string key. That could never work: the container resolves services and rejects
 * a factory returning anything but an object, so an array binding throws on
 * resolution. The convention survived only because every test that exercised it
 * mocked `ContainerInterface`, where the rule does not apply — the production
 * path had no implementation at all, and all eleven providers silently fell back
 * to their hard-coded defaults.
 *
 * This is the typed replacement: one service, holding the section-to-file map,
 * reading a file only when a provider asks for its section.
 *
 * @api
 */
#[Api(since: '1.0.0')]
final class ExtensionConfigRegistry
{
    /**
     * @param array<string, string>                   $files    Section name mapped to an absolute file path.
     * @param array<string, array<string, mixed>>     $sections Sections already resolved, bypassing the file.
     */
    public function __construct(
        private readonly array $files = [],
        private array $sections = [],
    ) {}

    /**
     * Whether any extension (or the host, overriding one) ships this section.
     */
    public function has(string $section): bool
    {
        return array_key_exists($section, $this->sections)
            || array_key_exists($section, $this->files);
    }

    /**
     * The section's contents, or an empty array when nobody ships it.
     *
     * An empty array is the honest answer for "not configured": every consumer
     * builds its config object through a `fromArray()` that fills in defaults.
     *
     * @return array<string, mixed>
     * @throws ExtensionException If the file exists but does not return an array.
     */
    public function section(string $section): array
    {
        if (array_key_exists($section, $this->sections)) {
            return $this->sections[$section];
        }

        if (!array_key_exists($section, $this->files)) {
            return [];
        }

        /** @var mixed $data */
        $data = require $this->files[$section];

        if (!is_array($data)) {
            throw ExtensionException::invalidConfigFile($this->files[$section]);
        }

        /** @var array<string, mixed> $data */
        return $this->sections[$section] = $data;
    }

    /**
     * A view of this registry holding only the named sections.
     *
     * A section is one extension's configuration file, and the host's copy of it
     * when the host overrides one — `payments.php` carries a webhook secret and a
     * provider API key in a real deployment. One registry instance holds every
     * section from every extension, so handing the whole object to a sandboxed
     * extension would let any tier holding `ContainerRead` read every other
     * extension's credentials by asking for a section name it does not own.
     *
     * This is what {@see \Pulsar\Extensibility\Internal\ScopedContainerProxy}
     * hands back instead, narrowed to the sections the receiving extension itself
     * ships — the same exchange it already makes for a container and a router.
     * Filtering by NAME rather than rebuilding from the extension's directory is
     * deliberate: a host override replaces the FILE behind a section, never the
     * section's name, so the narrowed registry keeps the operator's override of
     * the extension's own section and gains nothing else.
     *
     * @param list<string> $sections Section names to keep. Names that no
     *                               extension ships are ignored rather than
     *                               rejected: an extension that ships no config
     *                               file gets an empty registry, whose
     *                               {@see self::has()} answers false and whose
     *                               {@see self::section()} answers `[]` — which
     *                               is what every consumer's `fromArray()`
     *                               already treats as "not configured".
     */
    #[NoDiscard]
    public function restrictedTo(array $sections): self
    {
        $keep = array_fill_keys($sections, true);

        return new self(
            array_intersect_key($this->files, $keep),
            array_intersect_key($this->sections, $keep),
        );
    }

    /**
     * Every section name on offer, so diagnostics can list what was found.
     *
     * @return list<string>
     */
    public function sections(): array
    {
        return array_values(array_unique([
            ...array_keys($this->sections),
            ...array_keys($this->files),
        ]));
    }
}
