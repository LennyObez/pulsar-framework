<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Support;

use Pulsar\Extensibility\CapabilityPolicy;
use Pulsar\Extensibility\ExtensionCapability;
use Pulsar\Extensibility\TrustTier;

use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function in_array;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function sort;
use function sprintf;
use function strpos;
use function substr;
use function trim;

use const PREG_SET_ORDER;

/**
 * The rules behind TrustTierDocumentationTest, pointed at text rather than at this repository.
 *
 * The trust-tier capability model was written down in four places: ADR-0023's per-capability
 * matrix, `docs/extensions.md`'s tier summary, `docs/contributing/extensions.md`'s tier
 * table, and the header docblock of `config/extensions.php`. Only the first was checked
 * against `CapabilityPolicy`, and the fourth had already drifted — it told operators that
 * `verified` gets "all except CryptoKeyAccess, ProcessExec" when the policy also withholds
 * `ContainerWrite`, which is the one exclusion that stops a bundled product overriding
 * `Session`, `Auth` or `CsrfGuard`. That is the dangerous direction of drift: documentation
 * that overstates a security boundary is worse than none, because it is believed.
 *
 * ADR-0023 is now the single owner. The contributing-guide table is gone, replaced by a
 * pointer. The two restatements that remain are the two with a distinct audience — the
 * extension guide's service-level view, and the operator-facing docblock in the config stub
 * they will edit — and neither states anything this class cannot derive from the policy.
 *
 * Everything here is static and takes its text as an argument, so the negative test can hand
 * it a drifted table and watch it refuse instead of only ever seeing the healthy one.
 */
final readonly class TrustTierTableScanner
{
    /**
     * The tiers, in the column order ADR-0023's matrix uses.
     *
     * @var list<TrustTier>
     */
    public const array COLUMN_ORDER = [
        TrustTier::Core,
        TrustTier::Verified,
        TrustTier::Community,
        TrustTier::Untrusted,
    ];

    /**
     * How much of the container each tier reaches, most to least.
     *
     * The word is a rendering of a capability, not a second opinion about one: a tier that
     * may override an existing binding is "Full", one that may decorate is "Most", one that
     * may register its own services is "Limited", and one that may only resolve is
     * "Read-only". Stated here once so `docs/extensions.md` can use the reader-friendly word
     * and still be checked.
     *
     * @var list<array{0: string, 1: ExtensionCapability}>
     */
    private const array CONTAINER_VOCABULARY = [
        ['Full', ExtensionCapability::ContainerWrite],
        ['Most', ExtensionCapability::ServiceDecorate],
        ['Limited', ExtensionCapability::ServiceRegister],
        ['Read-only', ExtensionCapability::ContainerRead],
    ];

    /**
     * How far each tier's route registration reaches, most to least.
     *
     * @var list<array{0: string, 1: ExtensionCapability}>
     */
    private const array ROUTE_VOCABULARY = [
        ['Global', ExtensionCapability::RouteRegisterGlobal],
        ['Prefixed', ExtensionCapability::RouteRegister],
    ];

    /**
     * The four yes/no cells of a capability's row in ADR-0023's matrix, or null when absent.
     *
     * @return list<string>|null
     */
    public static function matrixRow(string $document, ExtensionCapability $capability): ?array
    {
        $pattern = sprintf('/^\| `%s` *\|(.*)$/m', preg_quote($capability->name, '/'));

        if (preg_match($pattern, $document, $match) !== 1) {
            return null;
        }

        return array_values(array_filter(
            array_map(trim(...), explode('|', $match[1])),
            static fn(string $cell): bool => $cell !== '',
        ));
    }

    /**
     * What the policy grants this capability, as ADR-0023's matrix would render it.
     *
     * @return list<string>
     */
    public static function grantedRow(CapabilityPolicy $policy, ExtensionCapability $capability): array
    {
        return array_map(
            static fn(TrustTier $tier): string => $policy->allows($tier, $capability) ? 'yes' : 'no',
            self::COLUMN_ORDER,
        );
    }

    /**
     * Every capability name ADR-0023's matrix has a row for.
     *
     * @return list<string>
     */
    public static function matrixCapabilityNames(string $document): array
    {
        preg_match_all('/^\| `([A-Za-z]+)` *\|(?: *(?:yes|no) *\|){4}$/m', $document, $matches);

        return $matches[1];
    }

    /**
     * The tier summary in `docs/extensions.md`, read as tier => list of cells.
     *
     * The header names the capability each yes/no column is about, so the mapping a reader
     * needs and the mapping this scan needs are the same string. A column header nobody
     * updated after renaming a capability fails `summaryColumns` below rather than silently
     * checking the wrong thing.
     *
     * @return array<string, list<string>>
     */
    public static function summaryRows(string $document): array
    {
        preg_match_all(
            '/^\| *(Core|Verified|Community|Untrusted) *\|([^\n]*)\|[^\n|]*$/m',
            $document,
            $matches,
            PREG_SET_ORDER,
        );

        $rows = [];

        foreach ($matches as $match) {
            $rows[$match[1]] = array_values(array_map(
                trim(...),
                explode('|', trim($match[2], '|')),
            ));
        }

        return $rows;
    }

    /**
     * The capability each yes/no column of the tier summary is about, in column order.
     *
     * The first two columns are the container and route vocabularies rather than a single
     * capability, so they are returned as null and checked by `containerWord` and
     * `routeWord`.
     *
     * @return list<ExtensionCapability|null>
     */
    public static function summaryColumns(string $document): array
    {
        if (preg_match('/^\| *Tier *\|([^\n]*)\|[^\n|]*$/m', $document, $match) !== 1) {
            return [];
        }

        $columns = [];

        foreach (explode('|', trim($match[1], '|')) as $header) {
            $header = trim($header);

            if (preg_match('/`([A-Za-z]+)`/', $header, $name) !== 1) {
                $columns[] = null;

                continue;
            }

            $columns[] = self::capabilityNamed($name[1]);
        }

        return $columns;
    }

    /**
     * The container word this tier's grants render to.
     */
    public static function containerWord(CapabilityPolicy $policy, TrustTier $tier): string
    {
        foreach (self::CONTAINER_VOCABULARY as [$word, $capability]) {
            if ($policy->allows($tier, $capability)) {
                return $word;
            }
        }

        return 'None';
    }

    /**
     * The route word this tier's grants render to.
     */
    public static function routeWord(CapabilityPolicy $policy, TrustTier $tier): string
    {
        foreach (self::ROUTE_VOCABULARY as [$word, $capability]) {
            if ($policy->allows($tier, $capability)) {
                return $word;
            }
        }

        return 'No';
    }

    /**
     * Every capability the header docblock of `config/extensions.php` names.
     *
     * Scoped to the text above `return [`, because the per-extension comments below it name
     * capabilities for a different reason — to say why one extension was granted one — and
     * folding those in would make this assert something it does not mean.
     *
     * @return list<string>
     */
    public static function docblockCapabilityNames(string $stub): array
    {
        $end = strpos($stub, 'return [');
        $header = $end === false ? $stub : substr($stub, 0, $end);

        preg_match_all('/`([A-Za-z]+)`/', $header, $matches);

        $named = [];

        foreach ($matches[1] as $candidate) {
            if (self::capabilityNamed($candidate) !== null && !in_array($candidate, $named, true)) {
                $named[] = $candidate;
            }
        }

        sort($named);

        return $named;
    }

    /**
     * The capabilities the policy withholds from a tier, by name.
     *
     * @return list<string>
     */
    public static function withheldFrom(CapabilityPolicy $policy, TrustTier $tier): array
    {
        $withheld = [];

        foreach (ExtensionCapability::cases() as $capability) {
            if (!$policy->allows($tier, $capability)) {
                $withheld[] = $capability->name;
            }
        }

        sort($withheld);

        return $withheld;
    }

    private static function capabilityNamed(string $name): ?ExtensionCapability
    {
        foreach (ExtensionCapability::cases() as $capability) {
            if ($capability->name === $name) {
                return $capability;
            }
        }

        return null;
    }
}
