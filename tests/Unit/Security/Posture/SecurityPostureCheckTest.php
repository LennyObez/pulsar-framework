<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Posture;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Config\SecurityConfig;
use Pulsar\Core\Wiring\Contract\DegradedFeature;
use Pulsar\Security\Posture\SecurityPostureCheck;
use Pulsar\Security\Posture\SecurityPostureItem;
use Pulsar\Security\Posture\SecurityPostureStatus;
use Pulsar\Security\Posture\SecurityRuntimeBindings;

use function array_filter;
use function array_values;

#[CoversClass(SecurityPostureCheck::class)]
#[CoversClass(SecurityPostureItem::class)]
final class SecurityPostureCheckTest extends TestCase
{
    private const string STRONG_KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /**
     * Sixty-four characters — long enough for the old `strlen` check — that are
     * not hex, so no key decodes out of them. This is the value that produced
     * `[ok] master_key`.
     */
    private const string MALFORMED_KEY = 'zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz';

    protected function tearDown(): void
    {
        putenv('APP_ENV');
    }

    /**
     * @param array<string, mixed> $session
     * @param array<string, mixed> $csrf
     * @param array<string, mixed> $hsts
     */
    private function config(array $session = [], array $csrf = ['enabled' => true], array $hsts = ['enabled' => true, 'max_age' => 63_072_000]): SecurityConfig
    {
        return SecurityConfig::fromArray([
            'session' => [
                'encryption' => true,
                'cookie_secure' => true,
                'cookie_httponly' => true,
                'cookie_samesite' => 'Strict',
                ...$session,
            ],
            'csrf' => $csrf,
            'headers' => ['hsts' => $hsts],
            'rate_limiting' => ['enabled' => false],
        ], Environment::load());
    }

    /**
     * The container SecurityWiring leaves behind when the master key parses:
     * MasterKey, the encryptor and the session encrypter all bound.
     */
    private function liveCrypto(): SecurityRuntimeBindings
    {
        return new SecurityRuntimeBindings(
            masterKeyBound: true,
            encryptorBound: true,
            sessionEncryptionBound: true,
        );
    }

    private function item(\Pulsar\Security\Posture\SecurityPostureReport $report, string $name): SecurityPostureItem
    {
        $matches = array_values(array_filter(
            $report->items,
            static fn(SecurityPostureItem $i): bool => $i->name === $name,
        ));

        self::assertNotEmpty($matches, "Expected a posture item named {$name}");

        return $matches[0];
    }

    #[Test]
    public function aFullyConfiguredProductionPostureHasNoFailures(): void
    {
        $check = new SecurityPostureCheck($this->config(), isProduction: true, debugMode: false, masterKey: self::STRONG_KEY, bindings: $this->liveCrypto());

        $report = $check->evaluate();

        self::assertFalse($report->hasFailures(), 'A correctly configured production posture should not fail');
        self::assertSame(SecurityPostureStatus::Ok, $this->item($report, 'csrf_protection')->status);
        self::assertSame(SecurityPostureStatus::Ok, $this->item($report, 'master_key')->status);
    }

    #[Test]
    public function httpsHstsFailsInProductionWhenNeitherEnabledNorEdgeTerminated(): void
    {
        $check = new SecurityPostureCheck(
            $this->config(hsts: ['enabled' => false]),
            isProduction: true,
            debugMode: false,
            masterKey: self::STRONG_KEY,
            bindings: $this->liveCrypto(),
        );

        self::assertSame(SecurityPostureStatus::Fail, $this->item($check->evaluate(), 'https_hsts')->status);
    }

    #[Test]
    public function httpsHstsPassesWhenTerminatedAtTheEdge(): void
    {
        // App emits no HSTS header (enabled=false) but the edge asserts it:
        // the posture must be OK, not a false HTTP-only failure on every request.
        $check = new SecurityPostureCheck(
            $this->config(hsts: ['enabled' => false, 'emitted_at_edge' => true]),
            isProduction: true,
            debugMode: false,
            masterKey: self::STRONG_KEY,
            bindings: $this->liveCrypto(),
        );

        self::assertSame(SecurityPostureStatus::Ok, $this->item($check->evaluate(), 'https_hsts')->status);
    }

    #[Test]
    public function aDegradedSecurityFeatureIsReportedAsFail(): void
    {
        // The acceptance case: a captcha whose single-use replay cache is unbound
        // (TaggedCacheInterface missing) is inert and MUST be a hard FAIL.
        $degraded = new DegradedFeature(
            component: 'AntiSpam',
            feature: 'Managed challenge single-use replay protection',
            missingBinding: 'Pulsar\\Cache\\Application\\TaggedCacheInterface',
            fix: 'Enable the cache so CacheWiring binds TaggedCacheInterface',
            security: true,
        );

        $check = new SecurityPostureCheck($this->config(), isProduction: true, debugMode: false, masterKey: self::STRONG_KEY, bindings: $this->liveCrypto(), degradedSecurityFeatures: [$degraded]);

        $report = $check->evaluate();

        self::assertTrue($report->hasFailures());
        $item = $this->item($report, 'feature:AntiSpam.Managed challenge single-use replay protection');
        self::assertSame(SecurityPostureStatus::Fail, $item->status);
        self::assertStringContainsString('TaggedCacheInterface', $item->reason);
    }

    #[Test]
    public function csrfDisabledFailsInProductionButOnlyDegradesInDev(): void
    {
        $prod = new SecurityPostureCheck($this->config(csrf: ['enabled' => false]), isProduction: true, debugMode: false, masterKey: self::STRONG_KEY, bindings: $this->liveCrypto());
        self::assertSame(SecurityPostureStatus::Fail, $this->item($prod->evaluate(), 'csrf_protection')->status);

        $dev = new SecurityPostureCheck($this->config(csrf: ['enabled' => false]), isProduction: false, debugMode: true, masterKey: self::STRONG_KEY, bindings: $this->liveCrypto());
        self::assertSame(SecurityPostureStatus::Degraded, $this->item($dev->evaluate(), 'csrf_protection')->status);
    }

    #[Test]
    public function missingMasterKeyFailsInProduction(): void
    {
        $check = new SecurityPostureCheck($this->config(), isProduction: true, debugMode: false, masterKey: null, bindings: new SecurityRuntimeBindings());

        self::assertSame(SecurityPostureStatus::Fail, $this->item($check->evaluate(), 'master_key')->status);
    }

    /**
     * The reported defect, both halves of it, in one deployment.
     *
     * PULSAR_MASTER_KEY is sixty-four characters — long enough for the old
     * strlen check — and is not hex, so MasterKey::fromHex rejects it and
     * SecurityWiring binds no encryptor, no session encrypter and no audit
     * logger. `security.session.encryption` is true, because the operator asked
     * for it. The old check read that DTO and printed `[ok] session_encryption`
     * and `[ok] master_key` over a process writing sessions in cleartext.
     */
    #[Test]
    public function aMalformedMasterKeyFailsMasterKeyAndSessionEncryption(): void
    {
        $check = new SecurityPostureCheck(
            $this->config(),
            isProduction: false,
            debugMode: false,
            masterKey: self::MALFORMED_KEY,
            bindings: new SecurityRuntimeBindings(
                masterKeyFailure: 'Invalid master key: expected 32 bytes, got 0',
            ),
        );

        $report = $check->evaluate();

        self::assertSame(SecurityPostureStatus::Fail, $this->item($report, 'master_key')->status);
        self::assertSame(SecurityPostureStatus::Fail, $this->item($report, 'session_encryption')->status);
        self::assertTrue($report->hasFailures());
    }

    /**
     * The wiring's recorded rejection is reported even outside production. A key
     * that does not decode is not a weakened control, it is an absent one, and
     * a laptop cannot read it any better than a production host can.
     */
    #[Test]
    public function aRejectedMasterKeyFailsOutsideProductionToo(): void
    {
        $check = new SecurityPostureCheck(
            $this->config(),
            isProduction: false,
            debugMode: true,
            masterKey: self::STRONG_KEY,
            bindings: new SecurityRuntimeBindings(masterKeyFailure: 'Invalid master key: expected 32 bytes, got 12'),
        );

        $item = $this->item($check->evaluate(), 'master_key');

        self::assertSame(SecurityPostureStatus::Fail, $item->status);
        self::assertStringContainsString('expected 32 bytes, got 12', $item->reason);
    }

    /**
     * The parse is run here, not deferred to the wiring's verdict: a
     * sixty-four-character non-hex value fails on its own, with no
     * MasterKeyFailure recorded, so this report cannot certify a value the
     * wiring would have thrown away.
     */
    #[Test]
    public function aNonHexKeyOfSufficientLengthFailsOnItsOwn(): void
    {
        $check = new SecurityPostureCheck(
            $this->config(),
            isProduction: false,
            debugMode: false,
            masterKey: self::MALFORMED_KEY,
            bindings: $this->liveCrypto(),
        );

        $item = $this->item($check->evaluate(), 'master_key');

        self::assertSame(SecurityPostureStatus::Fail, $item->status);
        self::assertStringContainsString('does not decode', $item->reason);
    }

    /**
     * The passing state must still pass, or the fix is just a check that always
     * fails: a valid key with the crypto stack up reports OK.
     */
    #[Test]
    public function aValidKeyWithTheCryptoStackUpReportsOk(): void
    {
        $check = new SecurityPostureCheck(
            $this->config(),
            isProduction: true,
            debugMode: false,
            masterKey: self::STRONG_KEY,
            bindings: $this->liveCrypto(),
        );

        $report = $check->evaluate();

        self::assertSame(SecurityPostureStatus::Ok, $this->item($report, 'master_key')->status);
        self::assertSame(SecurityPostureStatus::Ok, $this->item($report, 'session_encryption')->status);
        self::assertFalse($report->hasFailures());
    }

    /**
     * A key that parses but produced no MasterKey is still a failure: something
     * later in the crypto stack gave up, and nothing that encrypts is running.
     */
    #[Test]
    public function aParsableKeyThatBoundNothingFails(): void
    {
        $check = new SecurityPostureCheck(
            $this->config(),
            isProduction: false,
            debugMode: false,
            masterKey: self::STRONG_KEY,
            bindings: new SecurityRuntimeBindings(sessionEncryptionBound: true),
        );

        $item = $this->item($check->evaluate(), 'master_key');

        self::assertSame(SecurityPostureStatus::Fail, $item->status);
        self::assertStringContainsString('no MasterKey is bound', $item->reason);
    }

    /**
     * A MasterKey with no encryptor behind it is degraded rather than failed:
     * subkeys can still be derived, so signing and replay protection work, but
     * nothing in the application can encrypt.
     */
    #[Test]
    public function aMasterKeyWithoutAnEncryptorDegrades(): void
    {
        $check = new SecurityPostureCheck(
            $this->config(),
            isProduction: true,
            debugMode: false,
            masterKey: self::STRONG_KEY,
            bindings: new SecurityRuntimeBindings(masterKeyBound: true, sessionEncryptionBound: true),
        );

        self::assertSame(
            SecurityPostureStatus::Degraded,
            $this->item($check->evaluate(), 'master_key')->status,
        );
    }

    /**
     * Session encryption requested and never started is a FAIL in every
     * environment. `security.session.encryption = true` beside an unbound
     * SessionEncryption means cleartext on disk, which is not an
     * environment-dependent relaxation.
     */
    #[Test]
    public function sessionEncryptionEnabledButUnboundFailsEvenInDev(): void
    {
        $check = new SecurityPostureCheck(
            $this->config(),
            isProduction: false,
            debugMode: true,
            masterKey: self::STRONG_KEY,
            bindings: new SecurityRuntimeBindings(masterKeyBound: true, encryptorBound: true),
        );

        $item = $this->item($check->evaluate(), 'session_encryption');

        self::assertSame(SecurityPostureStatus::Fail, $item->status);
        self::assertStringContainsString('cleartext', $item->reason);
    }

    /**
     * Encryption switched off in configuration keeps its old environment-aware
     * treatment: that is a deliberate operator choice, not a broken control.
     */
    #[Test]
    public function sessionEncryptionDisabledInConfigStillFailsOnlyInProduction(): void
    {
        $disabled = $this->config(session: ['encryption' => false]);

        $prod = new SecurityPostureCheck($disabled, isProduction: true, debugMode: false, masterKey: self::STRONG_KEY, bindings: $this->liveCrypto());
        self::assertSame(SecurityPostureStatus::Fail, $this->item($prod->evaluate(), 'session_encryption')->status);

        $dev = new SecurityPostureCheck($disabled, isProduction: false, debugMode: true, masterKey: self::STRONG_KEY, bindings: $this->liveCrypto());
        self::assertSame(SecurityPostureStatus::Degraded, $this->item($dev->evaluate(), 'session_encryption')->status);
    }

    #[Test]
    public function debugModeFailsInProductionButIsAcceptedInDev(): void
    {
        $prod = new SecurityPostureCheck($this->config(), isProduction: true, debugMode: true, masterKey: self::STRONG_KEY, bindings: $this->liveCrypto());
        self::assertSame(SecurityPostureStatus::Fail, $this->item($prod->evaluate(), 'debug_mode')->status);

        $dev = new SecurityPostureCheck($this->config(), isProduction: false, debugMode: true, masterKey: self::STRONG_KEY, bindings: $this->liveCrypto());
        self::assertSame(SecurityPostureStatus::Ok, $this->item($dev->evaluate(), 'debug_mode')->status);
    }
}
