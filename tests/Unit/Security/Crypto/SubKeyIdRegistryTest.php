<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Crypto;

use PhpToken;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Crypto\SubKeyId;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_key_exists;
use function count;
use function dirname;
use function file_get_contents;
use function implode;
use function in_array;
use function is_dir;
use function is_int;
use function is_string;
use function ksort;
use function preg_match;
use function preg_match_all;
use function sprintf;
use function str_contains;
use function str_replace;
use function strlen;
use function trim;

use const DIRECTORY_SEPARATOR;
use const PREG_SET_ORDER;
use const SODIUM_CRYPTO_KDF_CONTEXTBYTES;

/**
 * `sodium_crypto_kdf_derive_from_key()` mixes BOTH the sub-key id and the 8-byte
 * context into the derived bytes, so the unit of domain separation is the
 * `(id, context)` PAIR, not the id on its own. Two subsystems sharing an id under
 * different contexts hold cryptographically independent keys — ADR-0006's own
 * assignment table ships several such sharings on purpose (id 2 keys both
 * `audit___` and `mailaudt`; id 3 keys `session_`, `pseudo__`, `rcvrycod` and
 * `stud_enc`). Two subsystems sharing a PAIR hold the SAME key, which is the
 * collapse ADR-0006 exists to prevent.
 *
 * {@see SubKeyId} states that invariant but could not enforce it: it is a list of
 * integers, and every violation to date was written as an integer literal that
 * never mentioned the enum. The application cache shipped the consequence — the
 * key that authenticates an encrypted entry's AAD was also handed to the cache
 * event emitter, which publishes `Hmac::computeHex($cacheKey, $key)` into logs and
 * metric labels. A published MAC under the same key as an integrity tag is a
 * signing oracle for that tag.
 *
 * This rule walks every KDF call site in `src/` and `extensions/` — through
 * `MasterKey`, through `Encryptor::fromDerivedKey()` / `withDerivedKey()`, and
 * through direct `sodium_crypto_kdf_derive_from_key()` calls that bypass
 * `MasterKey` entirely — resolves each `(id, context)` pair, and refuses any pair
 * derived by a file that is not its declared owner.
 *
 * Contexts or ids assembled at runtime (hashed labels, config values) cannot be
 * resolved statically and are left to review; every literal one is judged here.
 * Test suites are out of scope, the root `tests/` tree and each extension's own
 * alike: a test of the audit chain has to derive the audit chain's key to assert
 * against it, which is reproduction rather than a second purpose.
 *
 * Run in isolation:
 *   vendor/bin/phpunit -c tools/php/phpunit.xml --filter SubKeyIdRegistryTest
 */
#[CoversNothing]
final class SubKeyIdRegistryTest extends TestCase
{
    /**
     * Callables that derive a sub-key, mapped to the argument positions holding
     * the sub-key id and the KDF context.
     *
     * @var array<string, array{int, int}>
     */
    private const array KDF_CALLS = [
        'deriveSubKey' => [0, 1],
        'deriveSubKeyHex' => [0, 1],
        'derivePreviousSubKey' => [0, 1],
        'keyId' => [0, 1],
        'previousKeyId' => [0, 1],
        // Encryptor::fromDerivedKey($masterKey, $id, $context, ...)
        'fromDerivedKey' => [1, 2],
        // EncryptorInterface::withDerivedKey($masterKey, $id, $context)
        'withDerivedKey' => [1, 2],
        // The raw primitive: sodium_crypto_kdf_derive_from_key($length, $id, $context, $key)
        'sodium_crypto_kdf_derive_from_key' => [1, 2],
    ];

    /**
     * The live `(id, context)` registry: which subsystem owns each derived key,
     * and which files are allowed to derive it.
     *
     * A pair listed here with more than one owner is a deliberate sharing — the
     * same key for the same purpose, reconstructed in two places. Adding a file
     * to somebody else's pair is the change this rule exists to force into review.
     *
     * @var array<string, array{purpose: string, owners: list<string>}>
     */
    private const array ASSIGNMENTS = [
        '1|encrypt_' => [
            'purpose' => 'General-purpose Encryptor default key',
            'owners' => ['src/Security/Crypto/Encryptor.php'],
        ],
        '2|audit___' => [
            'purpose' => 'Audit log chain HMAC',
            'owners' => ['src/Core/Wiring/SecurityWiring.php'],
        ],
        '2|mailaudt' => [
            'purpose' => 'Mail audit HMAC',
            'owners' => ['src/Mail/Audit/MailAuditor.php'],
        ],
        '3|pseudo__' => [
            'purpose' => 'Pseudonymisation service',
            'owners' => ['src/Security/Compliance/Pseudonymization/PseudonymizationService.php'],
        ],
        '3|session_' => [
            'purpose' => 'Session AEAD encryption',
            'owners' => ['src/Security/Session/SessionEncryption.php'],
        ],
        '3|rcvrycod' => [
            'purpose' => 'Two-factor recovery code generation',
            'owners' => ['src/Core/Wiring/AuthWiring.php'],
        ],
        '3|stud_enc' => [
            'purpose' => 'Studio encryptor',
            // The dev router boots a standalone Studio with the same encryptor
            // the extension wires, so it derives the same key for the same
            // purpose rather than a second one.
            'owners' => [
                'extensions/studio/src/StudioExtension.php',
                'extensions/studio/dev/router.php',
            ],
        ],
        '4|sess_fp_' => [
            'purpose' => 'Session fingerprint validator MAC',
            'owners' => ['src/Core/Wiring/SecurityWiring.php'],
        ],
        '4|stud_mac' => [
            'purpose' => 'Studio archive MAC',
            'owners' => ['extensions/studio/src/StudioExtension.php'],
        ],
        '4|totpscrt' => [
            'purpose' => 'TOTP secret encryption',
            'owners' => ['src/Core/Wiring/AuthWiring.php'],
        ],
        '5|stud_chn' => [
            'purpose' => 'Studio chain link MAC',
            'owners' => ['extensions/studio/src/StudioExtension.php'],
        ],
        '6|integ_sg' => [
            'purpose' => 'File integrity manifest signing',
            'owners' => ['src/Integrity/ManifestSigner.php'],
        ],
        '7|fw_cache' => [
            'purpose' => 'Framework cache keyed BLAKE2b',
            'owners' => ['src/Cache/FrameworkCache.php'],
        ],
        '7|tokenize' => [
            'purpose' => 'Tokenization service encryption',
            'owners' => ['src/Security/Crypto/TokenizationService.php'],
        ],
        '8|bld_sign' => [
            'purpose' => 'Build artifact signing',
            'owners' => ['src/Build/ArtifactIntegrityVerifier.php'],
        ],
        '8|app_cenc' => [
            'purpose' => 'Application cache value encryption',
            'owners' => ['src/Cache/Application/Encryption/EncryptedCacheDecorator.php'],
        ],
        '9|app_cobs' => [
            // Authenticates the pool/key/tenant/purpose binding of an encrypted
            // cache entry. Its tag is stored and verified, never published — which
            // is why the cache event emitter must not hold this key.
            'purpose' => 'Application cache AAD binding MAC',
            'owners' => ['src/Cache/Application/Encryption/EncryptedCacheDecorator.php'],
        ],
        '10|que_aead' => [
            'purpose' => 'Queue AEAD payload encryption',
            'owners' => ['src/Queue/Middleware/AeadPayloadEncryptor.php'],
        ],
        '10|cms_prev' => [
            'purpose' => 'CMS preview token signing',
            'owners' => ['extensions/cms/src/Internal/Security/CmsKeyManager.php'],
        ],
        '11|cms_mdia' => [
            'purpose' => 'CMS signed media URL signing',
            'owners' => ['extensions/cms/src/Internal/Security/CmsKeyManager.php'],
        ],
        '12|cms_xprt' => [
            'purpose' => 'CMS export archive encryption',
            'owners' => ['extensions/cms/src/Internal/Security/CmsKeyManager.php'],
        ],
        '12|idemcach' => [
            'purpose' => 'Idempotency cache HMAC envelope',
            'owners' => ['src/Idempotency/SignedIdempotencyEnvelope.php'],
        ],
        '13|cms_dwnl' => [
            'purpose' => 'CMS download token signing',
            'owners' => ['extensions/cms/src/Internal/Security/CmsKeyManager.php'],
        ],
        '14|cms_evid' => [
            'purpose' => 'CMS order export evidence hashing',
            'owners' => ['extensions/cms/src/Internal/Security/CmsKeyManager.php'],
        ],
        '14|psd2_sca' => [
            'purpose' => 'PSD2 SCA dynamic-linking MAC',
            'owners' => ['extensions/compliance/psd2/src/Psd2ServiceProvider.php'],
        ],
        '15|cms_apik' => [
            'purpose' => 'CMS API-key HMAC pepper',
            'owners' => ['extensions/cms/src/Internal/Security/CmsKeyManager.php'],
        ],
        '15|secrets_' => [
            'purpose' => 'Secret vault encryption',
            'owners' => ['src/Security/Vault/SecretVault.php'],
        ],
        '15|stu_seed' => [
            'purpose' => 'Studio evidence hash-chain seed',
            'owners' => ['extensions/studio/src/Console/Evidence/HashChain.php'],
        ],
        '15|cmplprb1' => [
            'purpose' => 'Compliance key-derivation probe',
            'owners' => ['src/Compliance/Verification/RuntimeVerifier.php'],
        ],
        '15|cmplprb2' => [
            'purpose' => 'Compliance key-derivation probe, separation arm',
            'owners' => ['src/Compliance/Verification/RuntimeVerifier.php'],
        ],
        '16|antispam' => [
            'purpose' => 'Managed challenge captcha signing',
            'owners' => ['src/Core/Wiring/AntiSpamWiring.php'],
        ],
        '17|antispam' => [
            'purpose' => 'Time-trap anti-spam signing',
            'owners' => ['src/Core/Wiring/AntiSpamWiring.php'],
        ],
        '18|cmp_logs' => [
            'purpose' => 'Compliance log pseudonymisation',
            'owners' => ['src/Core/Wiring/ComplianceLoggingWiring.php'],
        ],
        '19|oa2_code' => [
            'purpose' => 'OAuth2 authorization-code hashing',
            'owners' => ['extensions/auth/src/OAuth2/Token/DbAuthorizationCodeRepository.php'],
        ],
        '20|cmp_evid' => [
            'purpose' => 'Compliance evidence chain HMAC',
            'owners' => ['src/Core/Wiring/ComplianceVerificationWiring.php'],
        ],
        '20|anal_vis' => [
            'purpose' => 'Analytics visitor hashing',
            'owners' => ['extensions/analytics/src/Internal/Security/AnalyticsKeyManager.php'],
        ],
        '21|app_kobs' => [
            // Its output is published: the cache event emitter HMACs the raw cache
            // key with it and the result reaches log lines and metric labels. It
            // must therefore key nothing that is verified.
            'purpose' => 'Application cache key hashing for observability',
            'owners' => ['src/Cache/Application/CacheManager.php'],
        ],
        '22|bkup_arc' => [
            // The only derived key in the tree that is used OFF the running host,
            // by a restore on another machine. A pair shared with an online
            // subsystem would put that subsystem's key material into a
            // disaster-recovery runbook, and would hand the archive key to
            // whoever holds that subsystem's key.
            'purpose' => 'Sealed backup archive AEAD',
            'owners' => ['src/Resilience/Backup/ArchiveSeal.php'],
        ],
        '23|ai_cdgst' => [
            // Published into the audit file next to the entry HMAC, and computed
            // over attacker-influenced text. Under the audit chain's own pair
            // (2, `audit___`) that would be a chosen-message MAC oracle for the
            // key that makes the chain tamper-evident. Keyed nonetheless, because
            // a prompt is low-entropy often enough that an unkeyed hash of it is
            // recovered by guessing.
            'purpose' => 'AI inference content digests (prompt, completion, system prompt, schema)',
            'owners' => ['src/Core/Wiring/AiAuditWiring.php'],
        ],
    ];

    #[Test]
    public function every_kdf_call_site_derives_a_pair_its_own_subsystem_owns(): void
    {
        $root = dirname(__DIR__, 4);
        $sites = self::callSites(self::frameworkSources($root));

        // A rule that silently stops finding call sites stops being a rule. The
        // framework has derived from more than twenty distinct (id, context) pairs
        // since 1.0.0; a count near zero means the scan broke.
        self::assertGreaterThan(
            25,
            count($sites),
            'The KDF registry scan found almost no call sites, which means it is no longer scanning.',
        );

        self::assertSame(
            [],
            self::violations($sites),
            "Every derived (id, context) pair must belong to exactly one subsystem:\n"
            . implode("\n", self::violations($sites)),
        );
    }

    /**
     * A declared pair that nothing derives is a registry entry describing a
     * framework that no longer exists, which is how the enum's own case comments
     * drifted in the first place.
     */
    #[Test]
    public function the_registry_declares_no_pair_that_nothing_derives(): void
    {
        $root = dirname(__DIR__, 4);
        $seen = [];

        foreach (self::callSites(self::frameworkSources($root)) as $site) {
            $seen[$site['pair']] = true;
        }

        $unused = [];

        foreach (self::ASSIGNMENTS as $pair => $entry) {
            if (!array_key_exists($pair, $seen)) {
                $unused[] = sprintf('%s (%s)', $pair, $entry['purpose']);
            }
        }

        self::assertSame(
            [],
            $unused,
            "Declared (id, context) pairs that no call site derives:\n" . implode("\n", $unused),
        );
    }

    /**
     * The rule is only worth having if it reports the collision it was written for,
     * so it is shown the exact shape the application cache shipped: one pair, two
     * subsystems, one of which publishes what the other verifies.
     */
    #[Test]
    public function fixture_detects_a_pair_derived_by_a_foreign_subsystem(): void
    {
        $owned = <<<'PHP'
            <?php
            $this->hmacKey = $masterKey->deriveSubKey(9, 'app_cobs');
            PHP;

        $foreign = <<<'PHP'
            <?php
            $keyHasher = fn(string $key): string => Hmac::computeHex(
                $key,
                $masterKey->deriveSubKey(9, 'app_cobs'),
            );
            PHP;

        $sites = self::callSites([
            'src/Cache/Application/Encryption/EncryptedCacheDecorator.php' => $owned,
            'src/Cache/Application/CacheManager.php' => $foreign,
        ]);

        self::assertCount(2, $sites);

        $violations = self::violations($sites);

        self::assertCount(1, $violations, implode("\n", $violations));
        self::assertStringContainsString('src/Cache/Application/CacheManager.php', $violations[0]);
        self::assertStringContainsString('Application cache AAD binding MAC', $violations[0]);
    }

    /**
     * Constants, `SubKeyId::Case->value` and integer literals must all resolve, or
     * the rule quietly judges a fraction of the call sites it claims to cover.
     */
    #[Test]
    public function fixture_resolves_constants_and_enum_cases(): void
    {
        $source = <<<'PHP'
            <?php
            final class Example
            {
                private const int SUB_KEY_ID = SubKeyId::IdempotencyEnvelope->value;
                private const string KDF_CONTEXT = 'idemcach';

                public function key(MasterKey $masterKey): string
                {
                    return $masterKey->deriveSubKey(self::SUB_KEY_ID, self::KDF_CONTEXT);
                }
            }
            PHP;

        $sites = self::callSites(['src/Idempotency/SignedIdempotencyEnvelope.php' => $source]);

        self::assertCount(1, $sites);
        self::assertSame(12, $sites[0]['id']);
        self::assertSame('idemcach', $sites[0]['context']);
        self::assertSame([], self::violations($sites));
    }

    /**
     * A dynamic id or context cannot be judged, and must not be guessed at either:
     * a half-resolved pair would accuse the wrong file.
     */
    #[Test]
    public function fixture_ignores_pairs_it_cannot_resolve(): void
    {
        $source = <<<'PHP'
            <?php
            $a = $masterKey->deriveSubKey($subKeyId, $context);
            $b = $masterKey->deriveSubKey(9, $computedContext);
            $c = $masterKey->deriveSubKey($id, 'app_cobs');
            PHP;

        self::assertSame([], self::callSites(['src/Security/Crypto/MasterKey.php' => $source]));
    }

    /**
     * {@see SubKeyId}'s case comments point the reader at the subsystem that owns
     * each id. Three of them named `extensions/oauth2` and `extensions/webauthn`,
     * neither of which has ever existed in this repository — OAuth2 and WebAuthn
     * ship inside `extensions/auth`. A pointer to a directory that is not there
     * sends the next allocator looking for a registry owner they cannot find.
     */
    #[Test]
    public function every_directory_the_registry_names_exists(): void
    {
        $root = dirname(__DIR__, 4);
        $registry = $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Security'
            . DIRECTORY_SEPARATOR . 'Crypto' . DIRECTORY_SEPARATOR . 'SubKeyId.php';

        $source = file_get_contents($registry);
        self::assertIsString($source, 'SubKeyId.php must be readable.');

        $matched = preg_match_all('~\bextensions/[A-Za-z0-9_\-/]+~', $source, $matches);
        self::assertIsInt($matched);
        self::assertGreaterThan(
            0,
            $matched,
            'SubKeyId names no extension directory at all, so this rule is no longer checking anything.',
        );

        $missing = [];

        foreach ($matches[0] as $path) {
            if (!is_dir($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path))) {
                $missing[] = $path;
            }
        }

        self::assertSame(
            [],
            $missing,
            "SubKeyId points at directories that do not exist:\n" . implode("\n", $missing),
        );
    }

    /**
     * Every PHP file under `src/` and `extensions/`, keyed by repository-relative
     * path with forward slashes.
     *
     * @return array<string, string>
     */
    private static function frameworkSources(string $root): array
    {
        $sources = [];

        foreach (['src', 'extensions'] as $directory) {
            $base = $root . DIRECTORY_SEPARATOR . $directory;

            if (!is_dir($base)) {
                continue;
            }

            /** @var RecursiveIteratorIterator<RecursiveDirectoryIterator> $files */
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($files as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $relative = str_replace('\\', '/', $file->getPathname());
                $relative = str_replace(str_replace('\\', '/', $root) . '/', '', $relative);

                // Test code reproduces production pairs on purpose - an audit-chain
                // test has to derive the audit chain's key to assert against it -
                // so an extension's own test suite is out of scope here, exactly as
                // the root `tests/` tree is.
                if (
                    str_contains($relative, '/tests/')
                    || str_contains($relative, '/vendor/')
                    || str_contains($relative, '/node_modules/')
                ) {
                    continue;
                }

                $source = file_get_contents($file->getPathname());

                if ($source === false) {
                    continue;
                }

                $sources[$relative] = $source;
            }
        }

        ksort($sources);

        return $sources;
    }

    /**
     * Every KDF call whose sub-key id and context both resolve to literals.
     *
     * @param array<string, string> $sources path => PHP source
     *
     * @return list<array{file: string, line: int, call: string, id: int, context: string, pair: string}>
     */
    private static function callSites(array $sources): array
    {
        $found = [];

        foreach ($sources as $path => $source) {
            $constants = self::constants($source);
            $tokens = PhpToken::tokenize($source);
            $count = count($tokens);

            for ($i = 0; $i < $count; $i++) {
                $token = $tokens[$i];

                if ($token->id !== T_STRING || !array_key_exists($token->text, self::KDF_CALLS)) {
                    continue;
                }

                if (self::isDeclaration($tokens, $i)) {
                    continue;
                }

                $open = $i + 1;
                while ($open < $count && $tokens[$open]->isIgnorable()) {
                    $open++;
                }

                if ($open >= $count || $tokens[$open]->text !== '(') {
                    continue;
                }

                [$idPosition, $contextPosition] = self::KDF_CALLS[$token->text];
                $arguments = self::arguments($tokens, $open, $count);

                if (count($arguments) <= $contextPosition) {
                    continue;
                }

                $id = self::resolveId($arguments[$idPosition], $constants);
                $context = self::resolveContext($arguments[$contextPosition], $constants);

                if ($id === null || $context === null) {
                    continue;
                }

                $found[] = [
                    'file' => $path,
                    'line' => $token->line,
                    'call' => $token->text,
                    'id' => $id,
                    'context' => $context,
                    'pair' => $id . '|' . $context,
                ];
            }
        }

        return $found;
    }

    /**
     * @param list<array{file: string, line: int, call: string, id: int, context: string, pair: string}> $sites
     *
     * @return list<string>
     */
    private static function violations(array $sites): array
    {
        $violations = [];

        foreach ($sites as $site) {
            if (!array_key_exists($site['pair'], self::ASSIGNMENTS)) {
                $violations[] = sprintf(
                    '%s:%d %s() derives undeclared pair (id %d, context "%s"). '
                    . 'Allocate a SubKeyId case and register the pair before shipping it.',
                    $site['file'],
                    $site['line'],
                    $site['call'],
                    $site['id'],
                    $site['context'],
                );

                continue;
            }

            $entry = self::ASSIGNMENTS[$site['pair']];

            if (in_array($site['file'], $entry['owners'], true)) {
                continue;
            }

            $violations[] = sprintf(
                '%s:%d %s() derives (id %d, context "%s"), which is the key of "%s" owned by %s. '
                . 'The same key for two purposes is the domain separation ADR-0006 requires; '
                . 'allocate a fresh pair instead.',
                $site['file'],
                $site['line'],
                $site['call'],
                $site['id'],
                $site['context'],
                $entry['purpose'],
                implode(', ', $entry['owners']),
            );
        }

        return $violations;
    }

    /**
     * Class constants declared in $source, resolved to their literal values.
     *
     * @return array<string, int|string>
     */
    private static function constants(string $source): array
    {
        $matched = preg_match_all(
            '/\bconst\s+(?:int|string)\s+([A-Za-z_][A-Za-z0-9_]*)\s*=\s*([^;]+);/',
            $source,
            $matches,
            PREG_SET_ORDER,
        );

        if ($matched === false || $matched === 0) {
            return [];
        }

        $constants = [];

        foreach ($matches as $match) {
            $value = self::literal(trim($match[2]));

            if ($value !== null) {
                $constants[$match[1]] = $value;
            }
        }

        return $constants;
    }

    /**
     * An integer literal, a single-quoted string, or a `SubKeyId::Case->value`.
     */
    private static function literal(string $expression): int|string|null
    {
        if (preg_match('/^-?\d+$/', $expression) === 1) {
            return (int) $expression;
        }

        if (preg_match("/^'([^'\\\\]*)'$/", $expression, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/^(?:\\\\?[A-Za-z0-9_\\\\]*\\\\)?SubKeyId::([A-Za-z0-9_]+)->value$/', $expression, $matches) === 1) {
            foreach (SubKeyId::cases() as $case) {
                if ($case->name === $matches[1]) {
                    return $case->value;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, int|string> $constants
     */
    private static function resolveId(string $expression, array $constants): ?int
    {
        $value = self::literal($expression);

        if ($value === null && preg_match('/^self::([A-Za-z_][A-Za-z0-9_]*)$/', $expression, $matches) === 1) {
            $value = $constants[$matches[1]] ?? null;
        }

        return is_int($value) ? $value : null;
    }

    /**
     * @param array<string, int|string> $constants
     */
    private static function resolveContext(string $expression, array $constants): ?string
    {
        $value = self::literal($expression);

        if ($value === null && preg_match('/^self::([A-Za-z_][A-Za-z0-9_]*)$/', $expression, $matches) === 1) {
            $value = $constants[$matches[1]] ?? null;
        }

        if (!is_string($value) || strlen($value) !== SODIUM_CRYPTO_KDF_CONTEXTBYTES) {
            // Length is KdfContextLengthTest's rule; a context of the wrong length
            // is not a pair this rule can attribute.
            return null;
        }

        return $value;
    }

    /**
     * True when the T_STRING at $index is the name in a function declaration
     * rather than a call.
     *
     * @param array<PhpToken> $tokens
     */
    private static function isDeclaration(array $tokens, int $index): bool
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            if ($tokens[$i]->isIgnorable()) {
                continue;
            }

            return $tokens[$i]->id === T_FUNCTION;
        }

        return false;
    }

    /**
     * Split the argument list that starts at $open into top-level argument sources.
     *
     * @param array<PhpToken> $tokens as `PhpToken::tokenize()` returns them
     *
     * @return list<string>
     */
    private static function arguments(array $tokens, int $open, int $count): array
    {
        $depth = 0;
        $arguments = [];
        $current = '';

        for ($i = $open; $i < $count; $i++) {
            $text = $tokens[$i]->text;

            if ($text === '(' || $text === '[') {
                $depth++;

                if ($depth === 1) {
                    continue;
                }
            } elseif ($text === ')' || $text === ']') {
                $depth--;

                if ($depth === 0) {
                    $arguments[] = trim($current);

                    break;
                }
            }

            if ($depth === 1 && $text === ',') {
                $arguments[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $text;
        }

        return $arguments;
    }
}
