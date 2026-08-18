<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Verification;

use Pulsar\Api\Api;
use Pulsar\Compliance\ComplianceProfile;
use Pulsar\Security\Crypto\FipsValidator;

use function extension_loaded;
use function function_exists;

/**
 * Verifies runtime security controls: FIPS mode, TLS, encryption, audit.
 *
 * Each check returns a CheckResult. The verifier is stateless; all
 * configuration/state is injected via constructor parameters.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class RuntimeVerifier
{
    /**
     * @param bool        $sessionEncryptionActive Whether session encryption is configured
     * @param bool        $masterKeyDerived        Whether master key is derived (not raw env)
     * @param bool        $auditLogActive          Whether audit logging is writing entries
     * @param bool        $dbTlsActive             Whether database connection uses TLS
     */
    public function __construct(
        private ComplianceProfile $profile,
        private bool $sessionEncryptionActive = false,
        private bool $masterKeyDerived = false,
        private bool $auditLogActive = false,
        private bool $dbTlsActive = false,
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

    private function checkFipsMode(): CheckResult
    {
        if (!$this->profile->encryptionAtRest && !$this->profile->encryptionInTransit) {
            return CheckResult::skip(
                'runtime.fips_mode',
                'FIPS check skipped: no encryption requirements in compliance profile.',
                ComplianceCheckDomain::Encryption,
            );
        }

        $result = FipsValidator::verify();

        if ($result->compliant) {
            return CheckResult::pass(
                'runtime.fips_mode',
                'OpenSSL FIPS mode is active and validated.',
                ComplianceCheckDomain::Encryption,
                ['openssl_version: ' . $result->opensslVersion],
            );
        }

        if ($result->aes256GcmAvailable && $result->hmacSha256Available) {
            return CheckResult::pass(
                'runtime.fips_mode',
                'FIPS-approved algorithms (AES-256-GCM, HMAC-SHA-256) available. '
                    . 'FIPS provider not detected; deploy with FIPS-validated OpenSSL for full compliance.',
                ComplianceCheckDomain::Encryption,
                ['openssl_version: ' . $result->opensslVersion, 'fips_provider: not detected'],
            );
        }

        return CheckResult::fail(
            'runtime.fips_mode',
            'Required cryptographic algorithms are unavailable.',
            ComplianceCheckDomain::Encryption,
            [
                'Deploy with OpenSSL built with FIPS provider.',
                'Ensure AES-256-GCM and HMAC-SHA-256 are available.',
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
            return CheckResult::pass(
                'runtime.db_tls',
                'Database connection uses TLS encryption.',
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

        return CheckResult::pass(
            'runtime.session_encryption',
            'Session encryption disabled; not required by compliance profile.',
            ComplianceCheckDomain::Encryption,
        );
    }

    private function checkMasterKeyDerivation(): CheckResult
    {
        if ($this->masterKeyDerived) {
            return CheckResult::pass(
                'runtime.master_key_derived',
                'Master key uses proper KDF derivation.',
                ComplianceCheckDomain::Encryption,
            );
        }

        return CheckResult::fail(
            'runtime.master_key_derived',
            'Master key is not using KDF derivation. Raw environment key detected.',
            ComplianceCheckDomain::Encryption,
            [
                'Use PULSAR_MASTER_KEY environment variable with sodium_crypto_kdf_derive_from_key().',
                'Ensure MasterKey::deriveSubKey() is used for domain-separated keys.',
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
