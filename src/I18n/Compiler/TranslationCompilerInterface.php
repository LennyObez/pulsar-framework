<?php

declare(strict_types=1);

namespace Pulsar\I18n\Compiler;

use Pulsar\Api\Api;

/**
 * Projects catalog entries into the JSON a browser consumes.
 *
 * Serving a translation bundle over HTTP is a published capability, so the
 * controller that does it must be able to name what it depends on. Naming the
 * concrete compiler instead made it construct its own — `new` inside a
 * constructor, against constructor-injection-only — and reach across a module
 * boundary into an internal class to do it.
 *
 * Everything here reads: a compiler answers what a locale contains, it never
 * changes it. The backing catalog is chosen at the composition root, which is
 * also what lets a caller swap in a precompiled bundle without touching this.
 */
#[Api(since: '1.0.0')]
interface TranslationCompilerInterface
{
    /**
     * Compile a single domain for a locale into a JSON string.
     */
    public function compile(string $locale, string $domain = 'messages', bool $prettyPrint = false): string;

    /**
     * Compile all domains for a locale into a single JSON string.
     *
     * @param string $locale Target locale code
     * @param list<string> $domains Domain names to include
     */
    public function compileAll(string $locale, array $domains): string;

    /**
     * Get the list of available keys for a locale and domain.
     *
     * @return list<string>
     */
    public function keys(string $locale, string $domain = 'messages'): array;
}
