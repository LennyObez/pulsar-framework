<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Tooling\Support\InvokesCiScript;

/**
 * Guards the gate that refuses green-by-abstention in the extension matrix.
 *
 * tools/ci/assert-extensions.php exists because a job can promise to exercise
 * the redis/memcached/apcu/igbinary/zstd/brotli paths, fail to install one of
 * them, watch every test behind `extension_loaded()` self-skip, and report green
 * over code that never ran once. The gate turns that silence into a failure.
 *
 * A gate of that shape is only worth its own correctness, and its correctness had
 * never been observed: nothing planted an absent extension and watched it refuse.
 * These cases do, and they cover the two exits separately, because CI branches on
 * the code and not on the text — exit 2 (invoked with no expectations, so it
 * measured nothing) must never be confused with exit 0 (it measured, and all was
 * well). The audited shape of that confusion is a gate invoked with an empty
 * argument list quietly reporting "nothing expected, nothing missing".
 */
#[CoversNothing]
#[GuardsGate(gate: 'tools/ci/assert-extensions.php', plants: 'an expected optional extension that is not loaded, and a run with no expectations that must refuse rather than report nothing missing')]
final class ExtensionContractGateTest extends TestCase
{
    use InvokesCiScript;

    private const string SCRIPT = __DIR__ . '/../../../tools/ci/assert-extensions.php';

    /**
     * The planted defect: an extension the job claims but the runner does not have.
     *
     * The name is one PHP cannot have loaded — no such extension exists — which is
     * exactly the state of a matrix entry whose `pecl install` failed while the
     * step carried on.
     */
    #[Test]
    public function itFailsWhenAnExpectedExtensionIsNotLoaded(): void
    {
        [$status, $stdout, $stderr] = $this->runScript(
            self::SCRIPT,
            'json',
            'pulsar_extension_that_cannot_exist',
        );

        self::assertSame(
            1,
            $status,
            'assert-extensions.php accepted an absent extension. What ships on that silence: '
            . 'a CI job reporting green over every code path behind an extension_loaded() guard '
            . 'that never opened — the redis, memcached, apcu, igbinary, zstd and brotli branches '
            . 'shipping to a regulated deployment having never executed in CI.',
        );
        self::assertStringContainsString('MISS  pulsar_extension_that_cannot_exist', $stdout);
        self::assertStringContainsString('not loaded', $stderr);
        self::assertStringContainsString('silently self-skip', $stderr);
    }

    /**
     * The other silence: a gate invoked without expectations has measured nothing.
     *
     * Exit 2 rather than 0 is the whole point. A step whose argument list was lost
     * to a YAML edit would otherwise print "All 0 expected extension(s) loaded."
     * and pass, which is the audited defect wearing the gate's own uniform.
     */
    #[Test]
    public function itRefusesToRunWithNoExpectationsRatherThanReportingNothingMissing(): void
    {
        [$status, , $stderr] = $this->runScript(self::SCRIPT);

        self::assertSame(
            2,
            $status,
            'assert-extensions.php with no arguments must not be mistaken for a clean matrix; '
            . 'a lost argument list would otherwise report an empty promise as a kept one.',
        );
        self::assertStringContainsString('usage:', $stderr);
    }

    /**
     * The control. Without it the two refusals above could be a script that always
     * refuses, which blocks merges without measuring anything either.
     */
    #[Test]
    public function itPassesWhenEveryExpectedExtensionIsLoaded(): void
    {
        // json and Core are compiled into every supported build; if these are
        // absent the runner is not one this repository supports at all.
        [$status, $stdout] = $this->runScript(self::SCRIPT, 'json', 'Core');

        self::assertSame(0, $status, $stdout);
        self::assertStringContainsString('All 2 expected extension(s) loaded.', $stdout);
    }
}
