<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tests\Unit\Integrity\Support\ContractCoverageScanner;

use function dirname;
use function implode;

/**
 * A contract test proves an interface is implementable. It proves nothing about the
 * classes that ship.
 *
 * `#[CoversNothing]` on an interface test is accurate — an interface has no executable
 * code — but it also makes the interface look tested while its implementations may not
 * be. That gap is invisible: the suite is green, the coverage report attributes nothing
 * to the test, and no one is told which shipped class was never exercised.
 *
 * This is the rule the gap needs: an interface exercised only by contract must have at
 * least one concrete implementation carrying its own `#[CoversClass]`.
 *
 * It found DbTicketRepository — 334 lines, twelve public methods, no test at all — and
 * TicketService, exercised by seventeen tests that declared no coverage target.
 *
 * The rule lives in ContractCoverageScanner, which takes the root it reads, so
 * ContractTestCoverageRefusesTest can build a tree carrying that exact gap and watch the
 * rule name it.
 */
#[CoversNothing]
final class ContractTestCoverageTest extends TestCase
{
    #[Test]
    public function everyContractTestedInterfaceHasACoveredImplementation(): void
    {
        $scanner = new ContractCoverageScanner(dirname(__DIR__, 3));

        self::assertNotSame(
            [],
            $scanner->interfacesTestedOnlyByContract(),
            'no contract-tested interfaces found at all — the check would pass vacuously',
        );

        $gaps = $scanner->gaps();

        self::assertSame(
            [],
            $gaps,
            "An interface tested only by contract, whose implementations carry no #[CoversClass],\n"
            . "looks tested and is not. Give one implementation a covering test, or state why\n"
            . "the contract alone is enough:\n  " . implode("\n  ", $gaps),
        );
    }
}
