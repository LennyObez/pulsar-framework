<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\CatalogAlreadyBuiltException;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlDeclaration;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\DuplicateControlException;
use Pulsar\Compliance\Probe\TamperEvidentAuditProbe;
use ReflectionClass;

use function array_map;
use function str_contains;

/**
 * The catalog of declarations.
 *
 * It offers no view by outcome, and that absence is the design: a catalog is
 * built at boot from mappings, and at boot nothing is known about the deployment.
 * An outcome exists only after a probe has run against gathered evidence.
 */
#[CoversClass(ControlCatalog::class)]
#[CoversClass(DuplicateControlException::class)]
#[CoversClass(CatalogAlreadyBuiltException::class)]
final class ControlCatalogTest extends TestCase
{
    #[Test]
    public function registeredControlsAreRetrievableByFrameworkAndIdentifier(): void
    {
        $catalog = new ControlCatalog();
        $catalog->register(self::declaration(ComplianceFramework::PciDss, 'Req10.2'));

        self::assertTrue($catalog->has(ComplianceFramework::PciDss, 'Req10.2'));
        self::assertSame('Req10.2', $catalog->get(ComplianceFramework::PciDss, 'Req10.2')?->id);
        self::assertNull($catalog->get(ComplianceFramework::Gdpr, 'Req10.2'));
    }

    /**
     * Control identifiers are only unique within a standard, so the index is keyed
     * by both. A single flat index would let one standard's "A.5.1" silently
     * displace another's — and the only symptom would be a control missing from
     * the report.
     */
    #[Test]
    public function twoStandardsMayUseTheSameControlIdentifier(): void
    {
        $catalog = new ControlCatalog();
        $catalog->register(
            self::declaration(ComplianceFramework::Iso27001, 'A.5.1'),
            self::declaration(ComplianceFramework::Iso42001, 'A.5.1'),
        );

        self::assertSame(2, $catalog->count());
        self::assertCount(1, $catalog->byFramework(ComplianceFramework::Iso27001));
        self::assertCount(1, $catalog->byFramework(ComplianceFramework::Iso42001));
    }

    /**
     * Two mappings declaring the same control can disagree about it, and under
     * silent replacement the only symptom is that whichever ran last decides what
     * the report says.
     */
    #[Test]
    public function declaringTheSameControlTwiceIsRefused(): void
    {
        $catalog = new ControlCatalog();
        $catalog->register(self::declaration(ComplianceFramework::PciDss, 'Req10.2'));

        $this->expectException(DuplicateControlException::class);
        $this->expectExceptionMessageMatches('/Req10\.2/');

        $catalog->register(self::declaration(ComplianceFramework::PciDss, 'Req10.2'));
    }

    #[Test]
    public function frameworksAreListedInRegistrationOrderWithoutRepetition(): void
    {
        $catalog = new ControlCatalog();
        $catalog->register(
            self::declaration(ComplianceFramework::PciDss, 'Req2.3'),
            self::declaration(ComplianceFramework::Gdpr, 'Art32'),
            self::declaration(ComplianceFramework::PciDss, 'Req10.2'),
        );

        self::assertSame(
            [ComplianceFramework::PciDss, ComplianceFramework::Gdpr],
            $catalog->frameworks(),
        );
    }

    #[Test]
    public function anEmptyCatalogHoldsNothingAndNamesNoFramework(): void
    {
        $catalog = new ControlCatalog();

        self::assertSame(0, $catalog->count());
        self::assertSame([], $catalog->all());
        self::assertSame([], $catalog->frameworks());
    }

    /**
     * The catalog must offer no way to ask about an outcome. `byStatus()` was the
     * method that made a mapping's literal look like an answer about a deployment,
     * and nothing shaped like it may come back.
     */
    #[Test]
    public function theCatalogExposesNoViewByOutcome(): void
    {
        $methods = array_map(
            static fn(object $method): string => $method->getName(),
            new ReflectionClass(ControlCatalog::class)->getMethods(),
        );

        foreach ($methods as $method) {
            self::assertFalse(
                str_contains(strtolower($method), 'status') || str_contains(strtolower($method), 'outcome'),
                $method . '() lets the catalog answer a question only a probe may answer.',
            );
        }
    }

    /**
     * The property the whole laziness rests on.
     *
     * A source is a promise of declarations, and the promise must stay unkept
     * until someone reads the catalog. Every boot of every application binds this
     * catalog; only `compliance:report` and the `compliance:check` gate ever read
     * one, so a source executed at contribute() time would charge every request
     * for the seventeen mapping classes and the 215 declarations behind them.
     */
    #[Test]
    public function aDeferredSourceIsNotExecutedUntilTheCatalogIsRead(): void
    {
        $executions = 0;
        $catalog = new ControlCatalog();
        $catalog->contribute(function () use (&$executions): array {
            $executions++;

            return [self::declaration(ComplianceFramework::PciDss, 'Req10.2')];
        });

        self::assertSame(0, $executions, 'contribute() executed the source.');

        self::assertSame(1, $catalog->count());
        self::assertSame(1, $executions);
    }

    /**
     * Memoized, not repeated: two reads of one catalog must not be able to
     * disagree, and a report that read the catalog twice must not pay twice.
     */
    #[Test]
    public function everyDeferredSourceRunsExactlyOnceHoweverOftenTheCatalogIsRead(): void
    {
        $executions = 0;
        $catalog = new ControlCatalog();
        $catalog->contribute(function () use (&$executions): array {
            $executions++;

            return [self::declaration(ComplianceFramework::Gdpr, 'Art32')];
        });

        self::assertSame(1, $catalog->count());
        self::assertCount(1, $catalog->all());
        self::assertSame([ComplianceFramework::Gdpr], $catalog->frameworks());
        self::assertCount(1, $catalog->byFramework(ComplianceFramework::Gdpr));
        self::assertTrue($catalog->has(ComplianceFramework::Gdpr, 'Art32'));
        self::assertNotNull($catalog->get(ComplianceFramework::Gdpr, 'Art32'));

        self::assertSame(1, $executions);
    }

    /**
     * Sources are collected from several places — the framework's own mappings and
     * every installed compliance extension's boot hook — and they are executed
     * once, at the first read. One handed over after that read declares controls
     * that every assessment up to that point silently omitted, and an artefact
     * missing a control is indistinguishable from one whose controls are all
     * present. Refused, therefore, rather than accepted late.
     */
    #[Test]
    public function contributingAfterTheCatalogHasBeenReadIsRefused(): void
    {
        $catalog = new ControlCatalog();
        $catalog->contribute(static fn(): array => [self::declaration(ComplianceFramework::PciDss, 'Req3.4')]);

        self::assertSame(1, $catalog->count());

        $this->expectException(CatalogAlreadyBuiltException::class);
        $this->expectExceptionMessageMatches('/already been read/');

        $catalog->contribute(static fn(): array => [self::declaration(ComplianceFramework::Gdpr, 'Art32')]);
    }

    /**
     * A build that threw registered some sources' declarations and none of the
     * rest. Handing that out on the next read would answer "is this control
     * declared?" with "no" for controls a mapping does declare — so the failure is
     * remembered and re-thrown, and the partial catalog is never readable.
     */
    #[Test]
    public function aCatalogWhoseBuildFailedIsNeverReadableAsAPartialOne(): void
    {
        $catalog = new ControlCatalog();
        $catalog->contribute(static fn(): array => [self::declaration(ComplianceFramework::PciDss, 'Req10.2')]);
        $catalog->contribute(static fn(): array => [self::declaration(ComplianceFramework::PciDss, 'Req10.2')]);

        $refusal = null;

        try {
            self::assertSame(0, $catalog->count());
        } catch (DuplicateControlException $expected) {
            $refusal = $expected;
        }

        self::assertInstanceOf(
            DuplicateControlException::class,
            $refusal,
            'The duplicate control was not refused.',
        );
        self::assertStringContainsString('Req10.2', $refusal->getMessage());

        // The second read must not hand out a catalog of one control as if that
        // were the whole of it.
        $this->expectException(DuplicateControlException::class);

        self::assertSame([], $catalog->all());
    }

    /**
     * register() takes declarations rather than a promise of them, so a late call
     * is visible in count() the instant it happens and cannot be silently missed.
     * It is deliberately not guarded the way contribute() is, and the two must
     * compose: a catalog can hold both.
     */
    #[Test]
    public function directRegistrationAndDeferredSourcesShareOneIndex(): void
    {
        $catalog = new ControlCatalog();
        $catalog->register(self::declaration(ComplianceFramework::Soc2, 'CC6.1'));
        $catalog->contribute(static fn(): array => [self::declaration(ComplianceFramework::Gdpr, 'Art32')]);

        self::assertSame(2, $catalog->count());
        self::assertTrue($catalog->has(ComplianceFramework::Soc2, 'CC6.1'));
        self::assertTrue($catalog->has(ComplianceFramework::Gdpr, 'Art32'));

        $catalog->register(self::declaration(ComplianceFramework::Nis2, 'Art21.2.a'));

        self::assertSame(3, $catalog->count());
    }

    /**
     * @param non-empty-string $id
     */
    private static function declaration(ComplianceFramework $framework, string $id): ControlDeclaration
    {
        return ControlDeclaration::probed(
            id: $id,
            framework: $framework,
            title: 'A control under test',
            requirement: 'Logs shall be produced, stored, protected and analysed.',
            probe: new TamperEvidentAuditProbe(),
        );
    }
}
