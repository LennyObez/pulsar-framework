<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Negative;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Pulsar\Tests\Support\FilesystemTestCase;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Integrity\Support\ContractCoverageScanner;
use Pulsar\Tests\Unit\Integrity\Support\PlantsFiles;

use function implode;

/**
 * The contract-coverage rule, watched refusing.
 *
 * The gap it exists for is the quietest kind there is. An interface with a contract test
 * shows up as tested; `#[CoversNothing]` is even the honest annotation for it, because an
 * interface has no executable code. Nothing in the suite, and nothing in the coverage
 * report, says that the 334-line class actually implementing it was never executed once.
 *
 * A rule against an invisible gap, itself never watched firing, is two layers of the same
 * problem. The tree below carries the gap the rule was written after — a repository
 * class, a contract test, and no `#[CoversClass]` anywhere — plus each arrangement the
 * rule must accept.
 */
#[CoversClass(ContractCoverageScanner::class)]
#[GuardsGate(
    gate: 'ContractTestCoverageTest::everyContractTestedInterfaceHasACoveredImplementation',
    plants: 'an interface exercised only by a CoversNothing contract test, whose sole shipped implementation no test declares covering',
)]
final class ContractTestCoverageRefusesTest extends FilesystemTestCase
{
    use PlantsFiles;

    /** The contract test: honest about covering nothing, and the reason the gap hides. */
    private const string CONTRACT_TEST = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Pulsar\Tests\Contract;

        use PHPUnit\Framework\Attributes\CoversNothing;
        use Pulsar\Tickets\TicketRepositoryInterface;

        #[CoversNothing]
        final class TicketRepositoryContractTest
        {
            public function subject(): ?TicketRepositoryInterface
            {
                return null;
            }
        }
        PHP;

    /** The class that actually ships, and the one nothing executes. */
    private const string IMPLEMENTATION = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Pulsar\Tickets;

        final class DbTicketRepository implements TicketRepositoryInterface
        {
            public function find(int $id): ?string
            {
                return null;
            }
        }
        PHP;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->plant($this->tempDirectory, 'tests/Contract/TicketRepositoryContractTest.php', self::CONTRACT_TEST);
        $this->plant($this->tempDirectory, 'src/Tickets/DbTicketRepository.php', self::IMPLEMENTATION);
    }

    #[Test]
    public function itRefusesAnInterfaceWhoseOnlyImplementationIsUncovered(): void
    {
        $gaps = $this->scanner()->gaps();

        self::assertStringContainsString(
            'DbTicketRepository',
            implode(' ', $gaps),
            'The contract-coverage rule stayed silent on an interface whose only shipped '
            . 'implementation no test declares covering. What ships when it stays silent is '
            . 'precisely what shipped before the rule existed: a 334-line repository class '
            . 'with twelve public methods and no test at all, sitting behind a green '
            . 'contract test and a coverage report that attributes nothing to anything. '
            . 'It reported: [' . implode(', ', $gaps) . ']',
        );
    }

    /**
     * One covering test closes the gap, so the refusal above is about coverage and not
     * about the interface merely existing.
     */
    #[Test]
    public function itIsSilentOnceAnImplementationDeclaresItsCoverage(): void
    {
        $this->plant($this->tempDirectory, 'tests/Unit/DbTicketRepositoryTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Pulsar\Tests\Unit;

            use PHPUnit\Framework\Attributes\CoversClass;
            use Pulsar\Tickets\DbTicketRepository;

            #[CoversClass(DbTicketRepository::class)]
            final class DbTicketRepositoryTest
            {
            }
            PHP);

        self::assertSame(
            [],
            $this->scanner()->gaps(),
            'the rule kept reporting a gap that a covering test had closed — a rule that '
            . 'cannot be satisfied is a rule that gets suppressed',
        );
    }

    /**
     * An interface with no shipped implementation is a contract test standing alone
     * legitimately, and reporting it would make the rule unusable.
     */
    #[Test]
    public function itIsSilentOnAnInterfaceNothingImplementsYet(): void
    {
        $this->plant($this->tempDirectory, 'tests/Contract/ArchiveContractTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Pulsar\Tests\Contract;

            use PHPUnit\Framework\Attributes\CoversNothing;
            use Pulsar\Archive\ArchiveStoreInterface;

            #[CoversNothing]
            final class ArchiveContractTest
            {
                public function subject(): ?ArchiveStoreInterface
                {
                    return null;
                }
            }
            PHP);

        $gaps = $this->scanner()->gaps();

        self::assertStringNotContainsString('ArchiveStoreInterface', implode(' ', $gaps));
        self::assertContains('ArchiveStoreInterface', $this->scanner()->interfacesTestedOnlyByContract());
    }

    /**
     * The scan must find the fixture at all, or every silence above means nothing.
     */
    #[Test]
    public function itFindsTheFixtureItIsBeingAskedAbout(): void
    {
        $scanner = $this->scanner();

        self::assertContains('TicketRepositoryInterface', $scanner->interfacesTestedOnlyByContract());
        self::assertArrayHasKey('TicketRepositoryInterface', $scanner->concreteImplementations());
        self::assertSame([], $scanner->classesDeclaredCovered());
    }

    private function scanner(): ContractCoverageScanner
    {
        return new ContractCoverageScanner($this->tempDirectory);
    }
}
