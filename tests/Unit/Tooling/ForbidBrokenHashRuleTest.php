<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;
use Pulsar\PHPStan\Rules\ForbidBrokenHashRule;

use function file_get_contents;
use function sprintf;
use function strlen;

/**
 * Guards the gate that replaced an advisory that could not fail.
 *
 * ADR-0006's weak-algorithm clause used to be the Semgrep rule
 * `pulsar.security.forbidden-broken-hash`. Three things were wrong with it at
 * once: no workflow under `.github/` invoked Semgrep, the rule was severity
 * WARNING so Semgrep exited 0 even when it matched, and the
 * `security:lint:strict` script that passed `--severity=ERROR --error` selected
 * an empty rule set because no Pulsar rule was ever ERROR. The ASVS L2 matrix
 * cited it as the control for V6.2.
 *
 * So the assertions below come in two halves: the rule reports what the policy
 * forbids, and the rule is wired into something that runs and can fail.
 *
 * @extends RuleTestCase<ForbidBrokenHashRule>
 */
final class ForbidBrokenHashRuleTest extends RuleTestCase
{
    private const string FIXTURE_ROOT = __DIR__ . '/data/broken-hash';

    private const string REPOSITORY_ROOT = __DIR__ . '/../../..';

    private const string MESSAGE = '%s has practical collision attacks and must not be used for any digest '
        . '(ADR-0006, ASVS V6.2.5).';

    private const string TIP = 'Use sodium_crypto_generichash($input, $subKey, 32) with a 32-byte MasterKey '
        . 'subkey when the digest must resist forgery, or sodium_crypto_generichash($domain . "\0" . $input, '
        . '\'\', 32) unkeyed. The second argument is a key: it must be \'\' or 16-64 bytes, so a short domain '
        . 'label throws. If an external standard mandates %s on the wire, amend ADR-0006 and add the path to '
        . ForbidBrokenHashRule::class . '::EXEMPT_PATHS with the clause that requires it.';

    /**
     * Line of `data/broken-hash/app/Digests.php` => algorithm the rule must name.
     *
     * @var array<int, string>
     */
    private const array EXPECTED_REPORTS = [
        11 => 'MD5',
        16 => 'SHA-1',
        21 => 'SHA-1',
        26 => 'MD5',
        // Named argument: `algo:` rather than position 0.
        31 => 'MD5',
        // hash() lowercases the algorithm name, so `MD5` reaches the same lookup.
        36 => 'MD5',
        // Algorithm held in a constant rather than written at the call site.
        41 => 'MD5',
        // sha1_file(), which the Semgrep patterns this replaced never matched.
        46 => 'SHA-1',
    ];

    #[Test]
    public function reportsBrokenDigestsWhateverSyntaxNamesThem(): void
    {
        $expected = [];

        foreach (self::EXPECTED_REPORTS as $line => $algorithm) {
            $expected[] = [
                sprintf(self::MESSAGE, $algorithm),
                $line,
                sprintf(self::TIP, $algorithm),
            ];
        }

        $this->analyse([self::FIXTURE_ROOT . '/app/Digests.php'], $expected);
    }

    /**
     * SHA-256, HMAC-SHA-256, HMAC-SHA-1, an algorithm only known at runtime and
     * BLAKE2b are all outside this rule.
     *
     * HMAC-SHA-1 because HMAC does not rest on collision resistance and ADR-0006
     * names it as an approved exception for RFC 6238 TOTP. SHA-256 because the
     * BLAKE2b half of ADR-0006 is a preference with roughly 57 unmigrated call
     * sites and no gate; reporting it would make `composer phpstan` red on code
     * outside the reach of the change that added this rule.
     */
    #[Test]
    public function leavesApprovedAndUndecidableAlgorithmsAlone(): void
    {
        $this->analyse([self::FIXTURE_ROOT . '/app/Approved.php'], []);
    }

    #[Test]
    public function acceptsThePathsAnExternalStandardBinds(): void
    {
        $this->analyse([self::FIXTURE_ROOT . '/src/WebSocket/Handshake.php'], []);
    }

    #[Test]
    public function acceptsTestCodeThatPinsThoseStandardsVectors(): void
    {
        $this->analyse([self::FIXTURE_ROOT . '/tests/VectorFixture.php'], []);
    }

    #[Test]
    public function everyExemptionStatesTheStandardOrPurposeThatRequiresIt(): void
    {
        self::assertNotSame([], ForbidBrokenHashRule::EXEMPT_PATHS);

        foreach (ForbidBrokenHashRule::EXEMPT_PATHS as $path => $reason) {
            self::assertGreaterThan(
                40,
                strlen($reason),
                "Exemption for {$path} must state why the digest is required, not that it exists.",
            );
        }
    }

    /**
     * The defect this rule was written for was never the policy — it was that
     * nothing ran the policy. Assert the wiring, or the next refactor can drop
     * the registration and leave every other test here green.
     */
    #[Test]
    public function theRuleIsRegisteredInTheConfigThatCiRuns(): void
    {
        $analyserConfig = file_get_contents(self::REPOSITORY_ROOT . '/tools/php/phpstan.neon');
        $workflow = file_get_contents(self::REPOSITORY_ROOT . '/.github/workflows/ci.yml');

        self::assertIsString($analyserConfig);
        self::assertIsString($workflow);
        self::assertStringContainsString(ForbidBrokenHashRule::class, $analyserConfig);
        self::assertStringContainsString('composer phpstan', $workflow);
    }

    /**
     * `security:lint:strict` ran `semgrep --severity=ERROR --error` over a rule
     * set in which every rule was WARNING, so it reported nothing and exited 0
     * whatever the code did. A script shaped like a security gate that cannot
     * fail is worse than no script.
     */
    #[Test]
    public function theEmptyStrictSemgrepScriptIsGone(): void
    {
        $manifest = file_get_contents(self::REPOSITORY_ROOT . '/composer.json');

        self::assertIsString($manifest);
        self::assertStringNotContainsString('security:lint:strict', $manifest);
    }

    protected function getRule(): Rule
    {
        return new ForbidBrokenHashRule(self::FIXTURE_ROOT);
    }
}
