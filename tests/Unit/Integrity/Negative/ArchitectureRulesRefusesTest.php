<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Negative;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Pulsar\Tests\Support\FilesystemTestCase;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Integrity\ArchitectureRulesTest;
use Pulsar\Tests\Unit\Integrity\Support\DeptracCoverage;
use Pulsar\Tests\Unit\Integrity\Support\ImportAnalyzer;
use Pulsar\Tests\Unit\Integrity\Support\PlantsFiles;
use Pulsar\Tests\Unit\Integrity\Support\VendorPolicy;

use function implode;

/**
 * The three architecture rules that had no negative test, watched refusing.
 *
 * ArchitectureRulesTest already carried five `fixture_detects_*` methods — genuine
 * negative tests, and the reason its other rules are not repeated here. Three rules had
 * none, and writing these found out why that mattered.
 *
 * The competitor-framework rule could not fire at all. It scanned `$fileReferences`,
 * which ImportAnalyzer::extractReferences() populates with `Pulsar\*` names only, so
 * `Symfony\`, `Illuminate\` and the other ten prefixes were filtered out one step before
 * the rule looked for them. It had been green over an empty list since the day it was
 * written, on the same evidence as the nine gates the audit found: the code existed,
 * therefore the control was reported as implemented. The rule now reads a list collected
 * with its own prefixes, and this file is what would notice if it stopped.
 */
#[CoversClass(VendorPolicy::class)]
#[CoversClass(DeptracCoverage::class)]
#[GuardsGate(
    gate: 'ArchitectureRulesTest::competitor_framework_imports_are_forbidden_in_production_code',
    plants: 'a production file importing Symfony, Illuminate and Doctrine',
)]
#[GuardsGate(
    gate: 'ArchitectureRulesTest::core_has_no_forbidden_vendor_dependencies',
    plants: 'a composer manifest requiring aws/aws-sdk-php and sentry/sdk',
)]
#[GuardsGate(
    gate: 'ArchitectureRulesTest::deptrac_config_covers_all_extensions',
    plants: 'an extension with a src/ tree that the Deptrac configuration never names',
)]
final class ArchitectureRulesRefusesTest extends FilesystemTestCase
{
    use PlantsFiles;

    #[Test]
    public function itRefusesAProductionFileImportingACompetitorFramework(): void
    {
        // The incident the rule was written after: Symfony's Filesystem in production,
        // with no composer `require` entry, which fatals on `composer install --no-dev`.
        $file = $this->plant($this->tempDirectory, 'src/Storage/LocalDisk.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Pulsar\Storage;

            use Doctrine\DBAL\Connection;
            use Illuminate\Support\Collection;
            use Psr\Log\LoggerInterface;
            use Pulsar\Config\Environment;
            use Symfony\Component\Filesystem\Filesystem;

            final class LocalDisk
            {
                public function __construct(
                    private Filesystem $filesystem,
                    private Collection $items,
                    private Connection $connection,
                    private LoggerInterface $logger,
                    private Environment $environment,
                ) {
                }
            }
            PHP);

        $foreign = ImportAnalyzer::extractReferencesWithPrefix($file, ArchitectureRulesTest::COMPETITOR_PREFIXES);

        self::assertContains(
            'Symfony\\Component\\Filesystem\\Filesystem',
            $foreign,
            'The competitor-framework rule stayed silent on production code importing '
            . 'Symfony. What ships when it stays silent is what already shipped: '
            . "Symfony's Filesystem used in src/ with no entry in composer.json's require "
            . 'block, which fatals on `composer install --no-dev` in every consumer that '
            . 'installs Pulsar for production. The rule was green over an empty list the '
            . 'whole time, because the analyser it reads discards every name that is not '
            . 'Pulsar\\*. It reported: [' . implode(', ', $foreign) . ']',
        );
        self::assertContains('Illuminate\\Support\\Collection', $foreign);
        self::assertContains('Doctrine\\DBAL\\Connection', $foreign);
    }

    /**
     * PSR interfaces are standards, not frameworks, and first-party names are the point.
     * A rule that reported those would be unusable on the first file it met.
     */
    #[Test]
    public function itIsSilentOnStandardsAndOnFirstPartyImports(): void
    {
        $file = $this->plant($this->tempDirectory, 'src/Storage/RemoteDisk.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Pulsar\Storage;

            use Psr\Log\LoggerInterface;
            use Pulsar\Config\Environment;

            final class RemoteDisk
            {
                public function __construct(
                    private LoggerInterface $logger,
                    private Environment $environment,
                ) {
                }
            }
            PHP);

        self::assertSame(
            [],
            ImportAnalyzer::extractReferencesWithPrefix($file, ArchitectureRulesTest::COMPETITOR_PREFIXES),
        );

        // And the Pulsar-only scan must still behave exactly as it did, since every other
        // rule in ArchitectureRulesTest reads it.
        self::assertSame(['Pulsar\\Config\\Environment'], ImportAnalyzer::extractReferences($file));
    }

    #[Test]
    public function itRefusesAVendorSdkInTheProductionRequireBlock(): void
    {
        $manifest = $this->plant($this->tempDirectory, 'composer.json', <<<'JSON'
            {
              "require": {
                "php": ">=8.5.0",
                "psr/log": "^3.0",
                "ext-json": "*",
                "aws/aws-sdk-php": "^3.0",
                "sentry/sdk": "^4.0"
              }
            }
            JSON);

        $forbidden = ArchitectureRulesTest::vendorPolicy()->forbiddenRequirements($manifest);

        self::assertCount(
            2,
            $forbidden,
            'The vendor rule stayed silent on SDKs in the framework\'s own require block. '
            . 'What ships when it stays silent is lock-in the extension system exists to '
            . 'prevent: every application installing Pulsar also installs that SDK, its '
            . 'transitive tree and its release cadence, whether or not it ever calls the '
            . 'service. It reported: [' . implode(', ', $forbidden) . ']',
        );
        self::assertStringContainsString('aws/aws-sdk-php', implode(' ', $forbidden));
        self::assertStringContainsString('sentry/sdk', implode(' ', $forbidden));
    }

    #[Test]
    public function itIsSilentOnAManifestOfStandardsOnly(): void
    {
        $manifest = $this->plant($this->tempDirectory, 'clean.json', <<<'JSON'
            {
              "require": {
                "php": ">=8.5.0",
                "psr/log": "^3.0",
                "psr/container": "^2.0",
                "ext-json": "*"
              }
            }
            JSON);

        self::assertSame([], ArchitectureRulesTest::vendorPolicy()->forbiddenRequirements($manifest));
    }

    /**
     * An extension outside deptrac.yaml is not reported by Deptrac as unanalysed. It is
     * simply not analysed, and `deptrac analyse` is green over it — so this rule is the
     * only thing standing between an unanalysed extension and a merge.
     */
    #[Test]
    public function itRefusesAnExtensionTheDeptracConfigNeverNames(): void
    {
        $this->plant($this->tempDirectory, 'extensions/tickets/src/TicketsExtension.php', "<?php\n");
        $this->plant($this->tempDirectory, 'extensions/social-sso/src/SocialSsoExtension.php', "<?php\n");

        $config = $this->plant($this->tempDirectory, 'deptrac.yaml', <<<'YAML'
            deptrac:
              layers:
                - name: Tickets
                  collectors:
                    - type: classLike
                      value: ^Pulsar\\Extension\\Tickets\\.*
            YAML);

        $uncovered = new DeptracCoverage($this->tempDirectory)->uncoveredExtensions($config);

        self::assertSame(
            ['social-sso'],
            $uncovered,
            'The Deptrac-coverage rule stayed silent on an extension the configuration '
            . 'never names. What ships when it stays silent is an entire extension sitting '
            . 'outside the structural boundary gate while that gate reports green — Deptrac '
            . 'does not announce what it was not asked to analyse. It reported: ['
            . implode(', ', $uncovered) . ']',
        );
    }

    #[Test]
    public function itIsSilentWhenEveryExtensionIsNamed(): void
    {
        $this->plant($this->tempDirectory, 'extensions/tickets/src/TicketsExtension.php', "<?php\n");
        $this->plant($this->tempDirectory, 'extensions/opentelemetry/src/OtelExtension.php', "<?php\n");

        $config = $this->plant($this->tempDirectory, 'deptrac.yaml', <<<'YAML'
            deptrac:
              layers:
                - name: Tickets
                  collectors:
                    - type: classLike
                      value: ^Pulsar\\Extension\\Tickets\\.*
                - name: OpenTelemetry
                  collectors:
                    - type: classLike
                      value: ^Pulsar\\Extension\\OpenTelemetry\\.*
            YAML);

        $coverage = new DeptracCoverage($this->tempDirectory);

        self::assertCount(2, $coverage->extensionDirectories(), 'the fixture extensions were not found');
        self::assertSame(
            [],
            $coverage->uncoveredExtensions($config),
            'the rule reported an extension the configuration does name — most likely the '
            . 'directory-name to namespace-segment mapping, which is where opentelemetry '
            . 'becomes OpenTelemetry rather than Opentelemetry',
        );
    }
}
