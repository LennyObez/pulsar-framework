<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extensibility\CapabilityPolicy;
use Pulsar\Extensibility\ExtensionCapability;
use Pulsar\Extensibility\TrustTier;
use Pulsar\Tests\Unit\Integrity\Support\TrustTierTableScanner;

use function array_map;
use function count;
use function dirname;
use function file_get_contents;
use function implode;
use function sprintf;

use const DIRECTORY_SEPARATOR;

/**
 * Every statement of the trust-tier model must say what `CapabilityPolicy` actually does.
 *
 * ADR-0023's matrix is not decoration. It is the artifact a compliance reviewer reads to
 * decide whether Pulsar meets a control, and it is the only place the tier model is stated
 * per capability. It had already drifted from the code once — showing `ContainerWrite`
 * granted down to Community after that grant was removed, and omitting `ServiceRegister`
 * and `ServiceDecorate` altogether — which is the failure mode that matters most here:
 * documentation that overstates a security control is worse than none, because it is
 * believed.
 *
 * The model was written down in four places and only that matrix was checked. The other
 * three were left to a reader's luck, and one of them had lost: `config/extensions.php`
 * told operators `verified` gets "all except CryptoKeyAccess, ProcessExec", omitting
 * `ContainerWrite` — the exclusion that stops a verified extension replacing `Session`,
 * `Auth` or `CsrfGuard` with its own. An operator sizing that grant from the stub they were
 * editing got the wrong answer from the file they were editing.
 *
 * So ADR-0023 owns the fact, the contributing guide now points at it, and the two views
 * that remain are checked here against the same policy. Prose cannot be checked; every part
 * of these documents that can be is.
 */
#[CoversNothing]
final class TrustTierDocumentationTest extends TestCase
{
    #[Test]
    public function everyCapabilityRowMatchesThePolicy(): void
    {
        $document = self::adr();
        $policy = CapabilityPolicy::defaults();

        foreach (ExtensionCapability::cases() as $capability) {
            $granted = TrustTierTableScanner::grantedRow($policy, $capability);
            $row = TrustTierTableScanner::matrixRow($document, $capability);

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
     * A stale row is the more dangerous half of drift, because it describes a restriction
     * that is not merely unenforced but absent — nothing in the code will ever contradict it.
     */
    #[Test]
    public function theTableNamesNoCapabilityThatDoesNotExist(): void
    {
        $known = array_map(
            static fn(ExtensionCapability $capability): string => $capability->name,
            ExtensionCapability::cases(),
        );

        $named = TrustTierTableScanner::matrixCapabilityNames(self::adr());

        foreach ($named as $name) {
            self::assertContains($name, $known, sprintf(
                'ADR-0023 documents a capability `%s` that no longer exists in ExtensionCapability. '
                . 'A row describing a control the code does not have cannot be contradicted by any test '
                . 'but this one.',
                $name,
            ));
        }

        self::assertNotEmpty($named, 'the capability table could not be found — has its format changed?');
    }

    /**
     * The service-level view in `docs/extensions.md` must render the same grants.
     *
     * It answers a different question than the matrix — "what can this extension reach",
     * not "which capabilities does it hold" — which is why it is worth keeping as a second
     * view rather than deleting. What makes it safe to keep is that every column names the
     * capability it renders, so it is derived rather than transcribed.
     */
    #[Test]
    public function theExtensionGuideSummaryMatchesThePolicy(): void
    {
        $document = self::extensionGuide();
        $policy = CapabilityPolicy::defaults();

        $columns = TrustTierTableScanner::summaryColumns($document);
        $rows = TrustTierTableScanner::summaryRows($document);

        self::assertNotSame([], $columns, 'the tier summary header could not be found in docs/extensions.md');
        self::assertCount(
            4,
            $rows,
            'the tier summary must have one row per trust tier; it has ' . count($rows)
            . '. A missing row is a tier nobody checked.',
        );

        foreach (TrustTierTableScanner::COLUMN_ORDER as $tier) {
            $name = $tier->name;
            self::assertArrayHasKey($name, $rows, sprintf('the tier summary has no row for %s', $name));

            $cells = $rows[$name];

            self::assertSameSize($columns, $cells, sprintf(
                'the %s row has %d cells and the header has %d columns, so the cells below are '
                . 'being read against the wrong capabilities.',
                $name,
                count($cells),
                count($columns),
            ));

            self::assertSame(
                TrustTierTableScanner::containerWord($policy, $tier),
                $cells[0],
                sprintf(
                    'docs/extensions.md says %s reaches "%s" of the container, but the policy grants '
                    . '"%s". Full=ContainerWrite, Most=ServiceDecorate, Limited=ServiceRegister, '
                    . 'Read-only=ContainerRead.',
                    $name,
                    $cells[0],
                    TrustTierTableScanner::containerWord($policy, $tier),
                ),
            );

            self::assertSame(
                TrustTierTableScanner::routeWord($policy, $tier),
                $cells[1],
                sprintf(
                    'docs/extensions.md says %s registers "%s" routes, but the policy grants "%s". '
                    . 'Global=RouteRegisterGlobal, Prefixed=RouteRegister, No=neither.',
                    $name,
                    $cells[1],
                    TrustTierTableScanner::routeWord($policy, $tier),
                ),
            );

            foreach ($columns as $index => $capability) {
                if ($capability === null) {
                    continue;
                }

                $expected = $policy->allows($tier, $capability) ? 'yes' : 'no';

                self::assertSame($expected, $cells[$index], sprintf(
                    'docs/extensions.md says %s is "%s" for `%s`, but the policy says "%s". This '
                    . 'table is the one an operator reads to decide what an extension can reach; '
                    . 'a cell that overstates the grant is read as a boundary that is not there.',
                    $name,
                    $cells[$index],
                    $capability->name,
                    $expected,
                ));
            }
        }
    }

    /**
     * The `config/extensions.php` header must name exactly what Verified is withheld.
     *
     * This is the file an operator edits to raise an extension's tier, so its docblock is
     * the statement most likely to be the only one they read. It is also the one that had
     * drifted: `ContainerWrite` was missing from the exclusions, which turned "cannot
     * replace a core security service" into "can".
     */
    #[Test]
    public function theConfigStubNamesExactlyWhatVerifiedIsWithheld(): void
    {
        $named = TrustTierTableScanner::docblockCapabilityNames(self::configStub());
        $withheld = TrustTierTableScanner::withheldFrom(CapabilityPolicy::defaults(), TrustTier::Verified);

        self::assertSame(
            $withheld,
            $named,
            "config/extensions.php's header docblock names [" . implode(', ', $named)
            . '] as what `verified` does not get; CapabilityPolicy::defaults() withholds ['
            . implode(', ', $withheld) . "].\n\n"
            . 'An operator raising an extension to verified sizes that grant from this docblock. '
            . 'A name missing here is a power they hand over without meaning to — which is exactly '
            . 'how `ContainerWrite`, the exclusion that stops a verified extension replacing '
            . 'Session, Auth or CsrfGuard, went unstated. A name too many is a restriction they '
            . 'will rely on and not have.',
        );
    }

    private static function adr(): string
    {
        return self::read('docs' . DIRECTORY_SEPARATOR . 'adr' . DIRECTORY_SEPARATOR . '0023-extension-trust-tiers.md');
    }

    private static function extensionGuide(): string
    {
        return self::read('docs' . DIRECTORY_SEPARATOR . 'extensions.md');
    }

    private static function configStub(): string
    {
        return self::read('config' . DIRECTORY_SEPARATOR . 'extensions.php');
    }

    private static function read(string $relativePath): string
    {
        $path = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . $relativePath;
        $contents = file_get_contents($path);

        self::assertIsString($contents, sprintf('%s is missing from %s', $relativePath, $path));

        return $contents;
    }
}
