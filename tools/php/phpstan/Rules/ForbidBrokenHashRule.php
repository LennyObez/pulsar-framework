<?php

declare(strict_types=1);

namespace Pulsar\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function array_keys;
use function in_array;
use function ltrim;
use function rtrim;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strncasecmp;
use function strtolower;
use function substr;
use function trim;

/**
 * Rejects MD2/MD4/MD5/SHA-1 digests outside the zones where an external
 * standard or a non-cryptographic purpose requires them (ASVS V6.2.5, ADR-0006).
 *
 * This replaces the Semgrep rule `pulsar.security.forbidden-broken-hash`, which
 * was severity WARNING, was invoked by no workflow, and whose `--severity=ERROR`
 * companion script therefore selected an empty rule set. A PHPStan rule runs
 * inside `composer phpstan`, which is a CI step, and a reported error fails the
 * build — there is no severity dial to turn it into advice.
 *
 * Every exemption below states why the algorithm is required at that path. An
 * entry that only means "not migrated yet" does not belong here: the call site
 * is the thing to change, not this table.
 *
 * @implements Rule<FuncCall>
 */
final class ForbidBrokenHashRule implements Rule
{
    /**
     * Functions whose name alone fixes the algorithm.
     *
     * @var array<string, string>
     */
    private const array BROKEN_FUNCTIONS = [
        'md5' => 'MD5',
        'md5_file' => 'MD5',
        'sha1' => 'SHA-1',
        'sha1_file' => 'SHA-1',
    ];

    /**
     * Unkeyed digests taking the algorithm name as their first (`algo`) parameter.
     *
     * `hash_init` is here even though `HASH_HMAC` can key it, because reading the
     * flag argument to decide would buy nothing: no call site in the repository
     * uses that form, and treating it as unkeyed only over-reports.
     *
     * @var list<string>
     */
    private const array UNKEYED_DIGEST_FUNCTIONS = [
        'hash',
        'hash_file',
        'hash_init',
    ];

    /**
     * Keyed constructions taking the algorithm name as their first parameter.
     *
     * @var list<string>
     */
    private const array KEYED_DIGEST_FUNCTIONS = [
        'hash_hmac',
        'hash_hmac_file',
        'hash_pbkdf2',
    ];

    /**
     * Algorithms with practical collision attacks, keyed by display name.
     *
     * @var array<string, string>
     */
    private const array BROKEN_ALGORITHMS = [
        'md2' => 'MD2',
        'md4' => 'MD4',
        'md5' => 'MD5',
        'sha1' => 'SHA-1',
    ];

    /**
     * The subset still forbidden inside HMAC and PBKDF2.
     *
     * SHA-1 is absent deliberately. HMAC's security does not rest on collision
     * resistance, so the SHA-1 collision work does not break HMAC-SHA-1, and
     * ADR-0006 names `hash_hmac('sha1', ...)` as an approved exception for
     * RFC 6238 TOTP. Reporting it would put this gate at odds with the document
     * it enforces, which is how gates get exempted into uselessness. MD5 stays
     * forbidden because nothing here speaks a protocol that requires HMAC-MD5.
     *
     * @var array<string, string>
     */
    private const array BROKEN_UNDER_A_KEY = [
        'md2' => 'MD2',
        'md4' => 'MD4',
        'md5' => 'MD5',
    ];

    /**
     * Repository-relative path prefixes where a broken digest is required, and why.
     *
     * Public because the reason column is the only thing separating this table
     * from a baseline, and a test reads it to assert every entry carries one.
     *
     * @var array<string, string>
     */
    public const array EXEMPT_PATHS = [
        'src/WebSocket/' => 'RFC 6455 section 1.3 defines Sec-WebSocket-Accept as '
            . 'base64(SHA-1(client key . GUID)); any other digest fails every browser handshake.',
        'src/Config/SharedMemoryConfigStore.php' => 'Derives the SysV shared-memory segment key that '
            . 'ftok() would otherwise take from an inode. The digest names a segment; nothing verifies it.',
        'extensions/compliance/psd2/src/Internal/Certificate/' => 'RFC 5280 section 4.2.1.2 '
            . '(SubjectKeyIdentifier) and RFC 6960 section 4.1.1 (CertID issuerNameHash) are SHA-1 over DER '
            . 'by definition, and the digests are compared against values minted by external CAs.',
        'extensions/cms/src/Http/Controller/Api/CommentApiController.php' => "Gravatar's published URL "
            . 'scheme addresses avatars by the MD5 of the lowercased e-mail. The digest is a third-party '
            . 'resource identifier and guards nothing.',
    ];

    /**
     * @param string $projectRoot Absolute path of the repository root, used to make
     *                            analysed file paths comparable with EXEMPT_PATHS.
     *                            A wrong value makes the rule over-report rather than
     *                            fall silent, which is the safe direction for a gate.
     */
    public function __construct(private readonly string $projectRoot) {}

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    /**
     * @param FuncCall $node
     *
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->isFirstClassCallable() || !$node->name instanceof Node\Name) {
            return [];
        }

        $function = strtolower($scope->resolveName($node->name));
        $algorithm = self::BROKEN_FUNCTIONS[$function] ?? $this->brokenAlgorithmArgument($function, $node, $scope);

        if ($algorithm === null) {
            return [];
        }

        if ($this->isExempt($scope->getFile())) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                $algorithm . ' has practical collision attacks and must not be used for any digest '
                . '(ADR-0006, ASVS V6.2.5).',
            )
                ->identifier('pulsar.hash.broken')
                ->tip(
                    'Use sodium_crypto_generichash($input, $subKey, 32) with a 32-byte MasterKey subkey when the '
                    . 'digest must resist forgery, or sodium_crypto_generichash($domain . "\0" . $input, \'\', 32) '
                    . 'unkeyed. The second argument is a key: it must be \'\' or 16-64 bytes, so a short domain '
                    . 'label throws. If an external standard mandates ' . $algorithm . ' on the wire, amend '
                    . 'ADR-0006 and add the path to ' . self::class . '::EXEMPT_PATHS with the clause that '
                    . 'requires it.',
                )
                ->build(),
        ];
    }

    /**
     * Display name of the broken algorithm this call names, or null.
     */
    private function brokenAlgorithmArgument(string $function, FuncCall $node, Scope $scope): ?string
    {
        if (in_array($function, self::UNKEYED_DIGEST_FUNCTIONS, true)) {
            $forbidden = self::BROKEN_ALGORITHMS;
        } elseif (in_array($function, self::KEYED_DIGEST_FUNCTIONS, true)) {
            $forbidden = self::BROKEN_UNDER_A_KEY;
        } else {
            return null;
        }

        foreach ($node->getArgs() as $position => $arg) {
            $isAlgorithmArgument = $arg->name === null
                ? $position === 0 && !$arg->unpack
                : $arg->name->toString() === 'algo';

            if (!$isAlgorithmArgument) {
                continue;
            }

            // Constant strings rather than String_ literals, so a class constant
            // holding the algorithm name is resolved too. A value the analyser
            // cannot pin down is left alone: the rule reports what it knows.
            foreach ($scope->getType($arg->value)->getConstantStrings() as $constantString) {
                $name = strtolower(trim($constantString->getValue()));

                if (isset($forbidden[$name])) {
                    return $forbidden[$name];
                }
            }
        }

        return null;
    }

    private function isExempt(string $file): bool
    {
        $relative = $this->relativePath($file);

        // Test code pins the RFC vectors the exempt production paths implement,
        // and builds fixture identifiers that no control reads.
        if ($this->isTestPath($relative)) {
            return true;
        }

        foreach (array_keys(self::EXEMPT_PATHS) as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function relativePath(string $file): string
    {
        $normalized = str_replace('\\', '/', $file);
        $root = rtrim(str_replace('\\', '/', $this->projectRoot), '/') . '/';

        // Case-insensitive: on Windows the analysed path and the configured root
        // can disagree on the drive letter's case while naming the same file.
        if (strncasecmp($normalized, $root, strlen($root)) === 0) {
            return substr($normalized, strlen($root));
        }

        return ltrim($normalized, '/');
    }

    private function isTestPath(string $relative): bool
    {
        return str_starts_with($relative, 'tests/')
            || str_starts_with($relative, 'benchmarks/')
            || str_contains($relative, '/tests/');
    }
}
