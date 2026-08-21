<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extensibility\CapabilityPolicy;
use Pulsar\Extensibility\ExtensionCapability;
use Pulsar\Extensibility\TrustTier;

use function array_filter;
use function array_map;
use function array_values;
use function dirname;
use function explode;
use function file_get_contents;
use function implode;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function sprintf;
use function trim;

use const DIRECTORY_SEPARATOR;

/**
 * ADR-0023's capability table must say what `CapabilityPolicy` actually does.
 *
 * That table is not decoration. It is the artifact a compliance reviewer reads
 * to decide whether Pulsar meets a control, and it is the only place the tier
 * model is stated in one view. It had already drifted from the code once —
 * showing `ContainerWrite` granted down to Community after that grant was
 * removed, and omitting `ServiceRegister` and `ServiceDecorate` altogether —
 * which is the failure mode that matters most here: documentation that
 * overstates a security control is worse than none, because it is believed.
 *
 * Prose cannot be checked, so this checks the part that can be: every row, both
 * directions. A capability with no row fails; a row naming a capability that no
 * longer exists fails; a cell disagreeing with the policy fails.
 */
#[CoversNothing]
final class TrustTierDocumentationTest extends TestCase
{
    /**
     * The tiers, in the column order the ADR table uses.
     */
    private const array COLUMN_ORDER = [
        TrustTier::Core,
        TrustTier::Verified,
        TrustTier::Community,
        TrustTier::Untrusted,
    ];

    #[Test]
    public function everyCapabilityRowMatchesThePolicy(): void
    {
        $document = self::adr();
        $policy = CapabilityPolicy::defaults();

        foreach (ExtensionCapability::cases() as $capability) {
            $granted = array_map(
                static fn(TrustTier $tier): string => $policy->allows($tier, $capability) ? 'yes' : 'no',
                self::COLUMN_ORDER,
            );

            $row = self::rowFor($document, $capability);

            self::assertNotNull($row, sprintf(
                'ADR-0023 has no row for `%s`. Every capability the policy can grant must appear '
                . 'in the table, or the table understates what an extension may be given.',
                $capability->name,
            ));

            self::assertSame(
                $granted,
                $row,
                sprintf(
                    'ADR-0023 describes `%s` as %s, but CapabilityPolicy::defaults() grants it as %s '
                    . '(columns: Core, Verified, Community, Untrusted). The table is what compliance '
                    . 'reviewers read — correct it rather than the policy, unless the policy is the bug.',
                    $capability->name,
                    implode('/', $row),
                    implode('/', $granted),
                ),
            );
        }
    }

    /**
     * The other direction: a row for a capability that no longer exists.
     *
     * A stale row is the more dangerous half of drift, because it describes a
     * restriction that is not merely unenforced but absent — nothing in the
     * code will ever contradict it.
     */
    #[Test]
    public function theTableNamesNoCapabilityThatDoesNotExist(): void
    {
        $known = array_map(
            static fn(ExtensionCapability $capability): string => $capability->name,
            ExtensionCapability::cases(),
        );

        preg_match_all('/^\| `([A-Za-z]+)` *\|(?: *(?:yes|no) *\|){4}$/m', self::adr(), $matches);

        foreach ($matches[1] as $name) {
            self::assertContains($name, $known, sprintf(
                'ADR-0023 documents a capability `%s` that no longer exists in ExtensionCapability. '
                . 'A row describing a control the code does not have cannot be contradicted by any test '
                . 'but this one.',
                $name,
            ));
        }

        self::assertNotEmpty($matches[1], 'the capability table could not be found — has its format changed?');
    }

    /**
     * The four yes/no cells of this capability's row, or null when absent.
     *
     * @return list<string>|null
     */
    private static function rowFor(string $document, ExtensionCapability $capability): ?array
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

    private static function adr(): string
    {
        $path = dirname(__DIR__, 3)
            . DIRECTORY_SEPARATOR . 'docs'
            . DIRECTORY_SEPARATOR . 'adr'
            . DIRECTORY_SEPARATOR . '0023-extension-trust-tiers.md';

        $contents = file_get_contents($path);

        self::assertIsString($contents, sprintf('ADR-0023 is missing from %s', $path));

        return $contents;
    }
}
