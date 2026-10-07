<?php

declare(strict_types=1);

namespace Pulsar\Deploy\Check;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Config\Environment;
use Pulsar\Container\ContainerInterface;
use Pulsar\Deploy\CheckResult;
use Pulsar\Deploy\DeployCheckInterface;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Crypto\MasterKeyFailure;

use function array_map;

/**
 * Validates that the master key actually produced a key in staging/production.
 *
 * Every at-rest protection Pulsar has is derived from `PULSAR_MASTER_KEY`, and
 * `SecurityWiring` registers all of them inside a single conditional branch. With
 * no key the branch does not run: there is no `EncryptorInterface`, no session
 * payload encryption, no audit HMAC chain and no secret vault. Nothing throws.
 * The application serves traffic, the session cookie is still `Secure` and
 * `HttpOnly`, and `config/security.php` still says `'encryption' => true` —
 * which is a statement of intent that nothing is left to honour.
 *
 * Both shipped templates deliberately leave the key empty, because a key
 * committed to a template is a published secret. That makes an unfilled key the
 * single most likely way to deploy Pulsar with its at-rest protections silently
 * absent, and this gate is what turns that from silent into refused.
 *
 * The check reads the CONTAINER, not the environment variable, so it is the
 * same question the compliance report asks: which implementation resolved. A key
 * that is present but malformed is caught by `MasterKey::fromHex()` inside
 * `SecurityWiring`, which logs the rejection and records a `MasterKeyFailure`
 * but lets the boot continue, so a syntactically broken key leaves exactly the
 * same empty container as a missing one and a check that only tested the
 * variable for emptiness would pass it.
 */
#[Internal]
final readonly class MasterKeyCheck implements DeployCheckInterface
{
    private const string CHECK_NAME = 'master-key';

    /** What the missing branch takes with it, in the order an operator will notice them. */
    private const array WITHHELD = [
        'EncryptorInterface — no application-level encryption at rest',
        'SessionEncryption — session payloads are stored unencrypted',
        'the audit HMAC chain — audit entries are no longer tamper-evident',
        'SecretVault and the tokenization service — PAN tokenization is unavailable',
    ];

    public function __construct(
        private ContainerInterface $container,
        private Environment $environment,
    ) {}

    #[Override]
    public function getName(): string
    {
        return self::CHECK_NAME;
    }

    #[Override]
    public function getDescription(): string
    {
        return 'Validates PULSAR_MASTER_KEY resolved a usable key in staging/production';
    }

    #[Override]
    public function check(string $environment): CheckResult
    {
        if ($environment === 'local') {
            return CheckResult::pass(
                self::CHECK_NAME,
                'Master key check is skipped in local environment',
            );
        }

        if ($this->container->has(MasterKey::class)) {
            return CheckResult::pass(
                self::CHECK_NAME,
                'PULSAR_MASTER_KEY resolved: encryption, session encryption and the audit HMAC chain are active',
            );
        }

        // A supplied-and-refused key binds a MasterKeyFailure carrying the reason.
        // Preferring it over re-reading the variable means the two causes are told
        // apart by what the boot actually did, rather than by a second guess at
        // the input — and it reports the reason the parse gave.
        if ($this->container->has(MasterKeyFailure::class)) {
            $failure = $this->container->get(MasterKeyFailure::class);
            $reason = $failure instanceof MasterKeyFailure ? $failure->reason : 'reason not recorded';

            return CheckResult::error(
                self::CHECK_NAME,
                "PULSAR_MASTER_KEY was supplied in $environment and rejected ($reason), so no key was derived",
                [
                    'The value must be 64 hexadecimal characters (32 bytes). Regenerate it with:',
                    'php bin/pulsar key:generate --write',
                    'The boot continued without it, so the deployment is running with the same gaps',
                    'as one that never set a key at all:',
                    ...self::withheldLines(),
                ],
            );
        }

        $raw = $this->environment->get('PULSAR_MASTER_KEY');

        if ($raw !== null && $raw !== '') {
            // A value is present, no key resolved, and no failure was recorded:
            // the crypto branch never ran. Reported apart from the other two
            // because the remediation is a wiring question, not a key question.
            return CheckResult::error(
                self::CHECK_NAME,
                "PULSAR_MASTER_KEY is set in $environment but no MasterKey was bound, so the security wiring did not run",
                [
                    'Confirm SecurityWiring is present in WiringList, and that the key reaches the',
                    'Environment the kernel booted with.',
                    ...self::withheldLines(),
                ],
            );
        }

        return CheckResult::error(
            self::CHECK_NAME,
            "PULSAR_MASTER_KEY is not set in $environment: encryption at rest, session encryption and audit integrity are all inactive",
            [
                'Generate a key with: php bin/pulsar key:generate --write',
                'In production, supply it through your secret manager rather than a committed file.',
                ...self::withheldLines(),
            ],
        );
    }

    /**
     * @return list<string>
     */
    private static function withheldLines(): array
    {
        return array_map(
            static fn(string $subsystem): string => "Without it: $subsystem.",
            self::WITHHELD,
        );
    }
}
