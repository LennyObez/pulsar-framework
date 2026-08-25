<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Verification;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Compliance\ComplianceProfile;
use Pulsar\Security\Crypto\AesGcmCipherSuite;
use Pulsar\Security\Crypto\CipherSuiteInterface;
use Pulsar\Security\Crypto\FipsValidator;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Crypto\SubKeyId;
use SodiumException;

use function extension_loaded;
use function function_exists;
use function hash_equals;
use function in_array;
use function sodium_memzero;
use function sprintf;
use function strlen;

use const SODIUM_CRYPTO_SECRETBOX_KEYBYTES;

/**
 * Verifies runtime security controls: FIPS mode, TLS, encryption, audit.
 *
 * Each check returns a CheckResult. The verifier is stateless; all
 * configuration/state is injected via constructor parameters.
 *
 * Two of those parameters are objects rather than booleans, and the reason is
 * the one ADR-0045 states: a boolean is a verdict somebody else already reached,
 * and both of these verdicts were being reached wrongly.
 *
 *  - `runtime.fips_mode` used to pass whenever OpenSSL merely OFFERED
 *    AES-256-GCM and HMAC-SHA-256. Every deployment offers them, and the
 *    default cipher suite is {@see \Pulsar\Security\Crypto\SodiumCipherSuite} —
 *    XChaCha20-Poly1305 and crypto_secretbox, neither of them FIPS 140
 *    approved. The check therefore certified an approved-algorithm posture for
 *    deployments that encrypt with an unapproved one, on the strength of a
 *    capability they never use. It now reads the cipher suite that is actually
 *    bound.
 *  - `runtime.master_key_derived` used to be handed `$container->has(MasterKey::class)`,
 *    which proves a hex string parsed and nothing whatever about derivation. It
 *    now receives the key and runs the KDF.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class RuntimeVerifier
{
    /**
     * Cipher suite identifiers ({@see CipherSuiteInterface::name()}) whose
     * algorithms are FIPS 140 approved.
     *
     * An accept list, so a suite added later is reported unapproved until
     * somebody decides otherwise, rather than approved until somebody notices.
     *
     * @var list<string>
     */
    private const array FIPS_APPROVED_SUITES = ['aes-gcm'];

    /**
     * The two KDF contexts the derivation probe uses. Both are exactly the eight
     * bytes libsodium requires. They differ so that domain separation can be
     * demonstrated rather than assumed: derivations under different contexts
     * must not collide.
     */
    private const string DERIVATION_CONTEXT = 'cmplprb1';
    private const string DERIVATION_ALT_CONTEXT = 'cmplprb2';

    /**
     * @param bool                    $sessionEncryptionActive Whether session encryption is configured
     * @param MasterKey|null          $masterKey               The key in service, or null when none is. Passed
     *        rather than a boolean because the check derives from it: see {@see checkMasterKeyDerivation()}.
     * @param bool                    $auditLogActive          Whether audit logging is writing entries
     * @param bool                    $dbTlsActive             Whether database connection uses TLS
     * @param CipherSuiteInterface|null $activeCipherSuite     The suite bound in the container — what the
     *        deployment encrypts with — or null when nothing is bound to encrypt at all.
     */
    public function __construct(
        private ComplianceProfile $profile,
        private bool $sessionEncryptionActive = false,
        private ?MasterKey $masterKey = null,
        private bool $auditLogActive = false,
        private bool $dbTlsActive = false,
        private ?CipherSuiteInterface $activeCipherSuite = null,
    ) {}

    /**
     * Run all runtime checks and return results.
     *
     * @return list<CheckResult>
     */
    public function verify(): array
    {
        $results = [];

        $results[] = $this->checkFipsMode();
        $results[] = $this->checkDatabaseTls();
        $results[] = $this->checkSessionEncryption();
        $results[] = $this->checkMasterKeyDerivation();
        $results[] = $this->checkAuditLogging();
        $results[] = $this->checkSodiumExtension();

        return $results;
    }

    /**
     * Whether the cryptography this deployment PERFORMS runs on a FIPS 140
     * validated path.
     *
     * A pass now needs three facts at once, and the middle one is the fact the
     * old implementation never established: a cipher suite is bound, its
     * algorithm is FIPS approved, and the module executing it is in FIPS mode.
     * The old version asked only whether the platform could offer AES-256-GCM
     * and HMAC-SHA-256 — a question every mainstream OpenSSL answers yes to,
     * including on the default deployment, which encrypts with
     * XChaCha20-Poly1305 and touches neither algorithm. That produced a green
     * FIPS line for a deployment doing no FIPS-approved cryptography at all.
     *
     * Outcomes that are not passes are deliberately split. Nothing bound to
     * encrypt while the profile requires encryption is a FAIL: a control the
     * deployment is obliged to have is missing. Encrypting with an unapproved
     * suite is a SKIP, because FIPS validation is simply not established for
     * this deployment and no framework Pulsar maps demands it — turning that
     * into a FAIL would refuse the boot of every GDPR deployment under
     * compliance strict mode over a requirement GDPR does not impose. The
     * distinction matters more than it looks: a skip reports `present = false`
     * to the evidence gatherer exactly as a fail does, so no control is
     * satisfied either way. Only the boot-refusal semantics differ.
     */
    private function checkFipsMode(): CheckResult
    {
        if (!$this->profile->encryptionAtRest && !$this->profile->encryptionInTransit) {
            return CheckResult::skip(
                'runtime.fips_mode',
                'FIPS check skipped: no encryption requirements in compliance profile.',
                ComplianceCheckDomain::Encryption,
            );
        }

        if ($this->activeCipherSuite === null) {
            return CheckResult::fail(
                'runtime.fips_mode',
                'No cipher suite is bound, so the deployment performs no encryption at all '
                    . 'while its compliance profile requires it. No algorithm can be approved '
                    . 'when no algorithm runs.',
                ComplianceCheckDomain::Encryption,
                [
                    'Set PULSAR_MASTER_KEY to 64 hex characters decoding to 32 bytes; '
                        . 'SecurityWiring binds the cipher suite only once the master key parses.',
                ],
            );
        }

        $suite = $this->activeCipherSuite->name();
        $result = FipsValidator::verify();

        if (!in_array($suite, self::FIPS_APPROVED_SUITES, true)) {
            return CheckResult::skip(
                'runtime.fips_mode',
                sprintf(
                    'FIPS is not established: the deployment encrypts with the "%s" cipher suite, '
                        . 'whose algorithms are not FIPS 140 approved. What OpenSSL offers alongside '
                        . 'it is irrelevant — the approved algorithms are the ones that run. Set '
                        . 'security.cipher_suite to "aes-gcm" to be assessed against FIPS.',
                    $suite,
                ),
                ComplianceCheckDomain::Encryption,
            );
        }

        if (!$result->aes256GcmAvailable || !$result->hmacSha256Available) {
            return CheckResult::fail(
                'runtime.fips_mode',
                sprintf(
                    'The "%s" cipher suite is bound but this build provides AES-256-GCM: %s, '
                        . 'HMAC-SHA-256: %s.',
                    $suite,
                    $result->aes256GcmAvailable ? 'yes' : 'no',
                    $result->hmacSha256Available ? 'yes' : 'no',
                ),
                ComplianceCheckDomain::Encryption,
                [
                    'Deploy against an OpenSSL build providing AES-256-GCM and HMAC-SHA-256.',
                ],
            );
        }

        // The suite may execute AES-256-GCM through libsodium instead of OpenSSL,
        // which it does by default wherever the CPU offers AES-NI. libsodium is not
        // a FIPS-validated module, so the algorithm is approved and the thing
        // running it is not. Reporting that as a pass because OpenSSL happens to
        // carry a FIPS provider elsewhere in the process would certify a module the
        // ciphertext never went through.
        $backend = $this->activeCipherSuite instanceof AesGcmCipherSuite && $this->activeCipherSuite->isUsingSodium()
            ? 'libsodium'
            : 'openssl';

        if ($backend === 'libsodium') {
            return CheckResult::skip(
                'runtime.fips_mode',
                sprintf(
                    'FIPS is not established: the "%s" suite runs AES-256-GCM through libsodium, '
                        . 'which is not a FIPS-validated module. The algorithm is approved; the '
                        . 'implementation executing it is not.',
                    $suite,
                ),
                ComplianceCheckDomain::Encryption,
            );
        }

        if (!$result->compliant) {
            return CheckResult::skip(
                'runtime.fips_mode',
                sprintf(
                    'FIPS is not established: the "%s" suite runs AES-256-GCM through OpenSSL, but '
                        . 'no FIPS provider was detected in %s. Deploy against a NIST-validated '
                        . 'OpenSSL FIPS provider with FIPS mode enabled.',
                    $suite,
                    $result->opensslVersion,
                ),
                ComplianceCheckDomain::Encryption,
            );
        }

        return CheckResult::pass(
            'runtime.fips_mode',
            sprintf(
                'The deployment encrypts with the "%s" suite, running AES-256-GCM through an '
                    . 'OpenSSL build in FIPS mode.',
                $suite,
            ),
            ComplianceCheckDomain::Encryption,
            [
                'cipher_suite: ' . $suite,
                'backend: ' . $backend,
                'openssl_version: ' . $result->opensslVersion,
            ],
        );
    }

    private function checkDatabaseTls(): CheckResult
    {
        if (!$this->profile->encryptionInTransit) {
            return CheckResult::skip(
                'runtime.db_tls',
                'Database TLS check skipped: encryption in transit not required.',
                ComplianceCheckDomain::TransportSecurity,
            );
        }

        if ($this->dbTlsActive) {
            // Deliberately not "the connection uses TLS": the flag this reads —
            // {@see \Pulsar\Compliance\Evidence\DatabaseTlsObserver::configuredForTls()} —
            // is also true for a deployment with no networked connection at all,
            // and asserting encryption of a transport that does not exist is the
            // inversion that carried PCI Req 2.3. What is true in both cases is
            // that nothing travels unprotected, and that is what is claimed. The
            // evidence-grade answer, which tells the two cases apart, is
            // {@see \Pulsar\Compliance\Evidence\DatabaseTlsObserver::observe()}.
            return CheckResult::pass(
                'runtime.db_tls',
                'No database connection travels unprotected: every connection that crosses a '
                    . 'network is configured for TLS, and the rest do not cross one.',
                ComplianceCheckDomain::TransportSecurity,
            );
        }

        return CheckResult::fail(
            'runtime.db_tls',
            'Database connection is not using TLS.',
            ComplianceCheckDomain::TransportSecurity,
            [
                'PostgreSQL: set database.options.sslmode to "require" or "verify-full" '
                . 'in config/database.php; it is carried into the DSN, where libpq reads it.',
                'MySQL: set the PDO SSL attributes (Pdo\Mysql::ATTR_SSL_CA and friends). '
                . 'A string "ssl_mode" key does not work there — PDO indexes driver options '
                . 'by integer constant and discards string keys.',
            ],
        );
    }

    private function checkSessionEncryption(): CheckResult
    {
        if ($this->sessionEncryptionActive) {
            return CheckResult::pass(
                'runtime.session_encryption',
                'Session data encryption is active.',
                ComplianceCheckDomain::Encryption,
            );
        }

        if ($this->profile->encryptionAtRest) {
            return CheckResult::fail(
                'runtime.session_encryption',
                'Session encryption is disabled but required by compliance profile.',
                ComplianceCheckDomain::Encryption,
                ['Set session.encryption to true in config/security.php.'],
            );
        }

        // A skip, not a pass. Session encryption is OFF here; the profile simply
        // does not demand it, which is a statement about the profile and not about
        // the deployment. Reported as a pass it read as "session encryption is
        // fine", and any consumer turning these results into compliance evidence
        // — {@see \Pulsar\Compliance\Evidence\ControlEvidenceGatherer::fromRuntimeCheck()}
        // is one — would have published the absence of a control as the control
        // holding. Skip is the honest answer: nothing was established.
        return CheckResult::skip(
            'runtime.session_encryption',
            'Session encryption is DISABLED; the active compliance profile does not require it, '
                . 'so nothing was established about session payloads at rest.',
            ComplianceCheckDomain::Encryption,
        );
    }

    /**
     * Run the KDF and check what it produced.
     *
     * This check used to be handed `$container->has(MasterKey::class)`, and the
     * message it printed on the strength of that — "Master key uses proper KDF
     * derivation" — was a claim about `sodium_crypto_kdf_derive_from_key`
     * founded on a hex string having decoded to thirty-two bytes. The two are
     * not related. A binding proves a constructor ran.
     *
     * So the derivation runs here. Three properties are asserted, each of them
     * something the deployment's own libsodium has to demonstrate rather than
     * something this class assumes:
     *
     *  - the KDF returns a subkey of the expected length;
     *  - the same (id, context) reproduces it, without which nothing sealed
     *    under a derived key could ever be reopened;
     *  - a different context yields different material, which is the
     *    domain-separation guarantee ADR-0006 has every subsystem relying on.
     *
     * The bytes are compared in constant time, zeroed immediately, and never
     * leave the method.
     */
    private function checkMasterKeyDerivation(): CheckResult
    {
        if ($this->masterKey === null) {
            return CheckResult::fail(
                'runtime.master_key_derived',
                'No master key is in service, so no subkey can be derived and every '
                    . 'subsystem that seals data under a derived key is inert.',
                ComplianceCheckDomain::Encryption,
                [
                    'Set PULSAR_MASTER_KEY to 64 hex characters decoding to 32 bytes.',
                    'Check the boot log: a supplied key that fails to parse leaves the '
                        . 'crypto stack unbound and is logged as an error by SecurityWiring.',
                ],
            );
        }

        try {
            $derived = $this->masterKey->deriveSubKey(
                SubKeyId::ComplianceDerivationProbe->value,
                self::DERIVATION_CONTEXT,
            );
            $repeated = $this->masterKey->deriveSubKey(
                SubKeyId::ComplianceDerivationProbe->value,
                self::DERIVATION_CONTEXT,
            );
            $separated = $this->masterKey->deriveSubKey(
                SubKeyId::ComplianceDerivationProbe->value,
                self::DERIVATION_ALT_CONTEXT,
            );
            // Only SodiumException is caught. MasterKey::deriveSubKey() also rejects
            // a context that is not exactly SODIUM_CRYPTO_KDF_CONTEXTBYTES long, but
            // both contexts here are constants of that length — an invariant
            // RuntimeVerifierTest asserts by reflection, so a later edit that broke
            // it would fail a test rather than throw out of a boot-time check.
        } catch (SodiumException $failure) {
            return CheckResult::fail(
                'runtime.master_key_derived',
                sprintf('Key derivation failed on this runtime: %s', $failure->getMessage()),
                ComplianceCheckDomain::Encryption,
                [
                    'Install a libsodium build providing sodium_crypto_kdf_derive_from_key().',
                ],
            );
        }

        $length = strlen($derived);
        $reproducible = hash_equals($derived, $repeated);
        $domainSeparated = !hash_equals($derived, $separated);

        sodium_memzero($derived);
        sodium_memzero($repeated);
        sodium_memzero($separated);

        return $this->derivationVerdict($length, $reproducible, $domainSeparated);
    }

    /**
     * Turn the three measured properties into a result.
     *
     * Split out so the key material is already zeroed before anything is
     * formatted: no branch of the reporting can reach the bytes.
     */
    #[NoDiscard]
    private function derivationVerdict(int $length, bool $reproducible, bool $domainSeparated): CheckResult
    {
        if ($length !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return CheckResult::fail(
                'runtime.master_key_derived',
                sprintf(
                    'Key derivation returned %d bytes where %d were requested.',
                    $length,
                    SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
                ),
                ComplianceCheckDomain::Encryption,
                ['Install a libsodium build whose KDF honours the requested subkey length.'],
            );
        }

        if (!$reproducible) {
            return CheckResult::fail(
                'runtime.master_key_derived',
                'Key derivation is not reproducible: two derivations of the same subkey id '
                    . 'and context produced different material, so nothing sealed under a '
                    . 'derived key could be reopened.',
                ComplianceCheckDomain::Encryption,
                ['Install a libsodium build with a conforming sodium_crypto_kdf_derive_from_key().'],
            );
        }

        if (!$domainSeparated) {
            return CheckResult::fail(
                'runtime.master_key_derived',
                'Key derivation is not domain-separated: two different contexts produced the '
                    . 'same material, which collapses the isolation between subsystems that '
                    . 'ADR-0006 depends on.',
                ComplianceCheckDomain::Encryption,
                ['Install a libsodium build with a conforming sodium_crypto_kdf_derive_from_key().'],
            );
        }

        return CheckResult::pass(
            'runtime.master_key_derived',
            sprintf(
                'Key derivation verified against the running key: sodium_crypto_kdf_derive_from_key '
                    . 'produced %d bytes, reproduced them for the same subkey id and context, and '
                    . 'produced different material for a different context.',
                $length,
            ),
            ComplianceCheckDomain::Encryption,
            [
                'kdf: sodium_crypto_kdf_derive_from_key',
                'subkey_bytes: ' . $length,
                'domain_separation: verified across two contexts',
            ],
        );
    }

    private function checkAuditLogging(): CheckResult
    {
        if ($this->auditLogActive) {
            return CheckResult::pass(
                'runtime.audit_logging',
                'Audit logging is active and writing entries.',
                ComplianceCheckDomain::AuditLogging,
            );
        }

        if ($this->profile->tamperEvidentAudit) {
            return CheckResult::fail(
                'runtime.audit_logging',
                'Audit logging is not active. Tamper-evident audit is required by compliance profile.',
                ComplianceCheckDomain::AuditLogging,
                [
                    'Configure AuditLogger with an AuditSinkInterface in the service container.',
                    'Ensure audit_key is set in config/security.php.',
                ],
            );
        }

        return CheckResult::skip(
            'runtime.audit_logging',
            'Audit logging is not active; no compliance framework mandates it for this profile.',
            ComplianceCheckDomain::AuditLogging,
        );
    }

    private function checkSodiumExtension(): CheckResult
    {
        if (extension_loaded('sodium') && function_exists('sodium_crypto_generichash')) {
            return CheckResult::pass(
                'runtime.sodium_extension',
                'libsodium extension is loaded and operational.',
                ComplianceCheckDomain::Encryption,
                ['extension: sodium'],
            );
        }

        return CheckResult::fail(
            'runtime.sodium_extension',
            'libsodium PHP extension is not loaded.',
            ComplianceCheckDomain::Encryption,
            [
                'Install the sodium PHP extension (ext-sodium).',
                'Ensure php.ini includes extension=sodium.',
            ],
        );
    }
}
