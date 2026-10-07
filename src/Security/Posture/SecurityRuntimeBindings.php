<?php

declare(strict_types=1);

namespace Pulsar\Security\Posture;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Container\ContainerInterface;
use Pulsar\Security\Crypto\EncryptorInterface;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Crypto\MasterKeyFailure;
use Pulsar\Security\Session\SessionEncryption;

/**
 * What the wired container actually holds, for the posture items whose subject
 * is a live service rather than a configuration value.
 *
 * {@see SecurityPostureCheck} used to answer nine of its ten questions out of a
 * {@see \Pulsar\Config\SecurityConfig} DTO. A DTO records what the operator
 * asked for. `security.session.encryption = true` beside a container with no
 * {@see SessionEncryption} in it is not "session encryption is enabled"; it is
 * session encryption requested and never started, with the payloads going to
 * disk in cleartext while the report prints `[ok] session_encryption`. The two
 * states are indistinguishable from the DTO and trivially distinguishable from
 * the container, so the container is what the check is given.
 *
 * The observation happens once, at the composition root — the only place
 * allowed to hold a container — and travels to the check as facts. That is the
 * same shape {@see \Pulsar\Core\Wiring\Contract\DegradedFeature} already uses,
 * and it keeps {@see SecurityPostureCheck} free of a service locator and
 * directly testable in both directions.
 *
 * There is deliberately no "not observed" constructor. A posture item that
 * cannot tell "nobody looked" from "looked and found nothing" is the defect
 * this type was added to remove, so every construction states what it saw.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class SecurityRuntimeBindings
{
    /**
     * @param bool        $masterKeyBound         A {@see MasterKey} resolved from the container.
     * @param bool        $encryptorBound         An {@see EncryptorInterface} resolved, so something can encrypt.
     * @param bool        $sessionEncryptionBound A {@see SessionEncryption} resolved, so session payloads are sealed.
     * @param string|null $masterKeyFailure       Why a supplied master key was rejected, or null when none was.
     */
    public function __construct(
        public bool $masterKeyBound = false,
        public bool $encryptorBound = false,
        public bool $sessionEncryptionBound = false,
        public ?string $masterKeyFailure = null,
    ) {}

    /**
     * Read the four facts off a fully wired container.
     *
     * Uses `has()` only: resolving here would build services during the posture
     * preflight and could cache a lazily-bound singleton against a container
     * that is not yet complete — the ordering trap ADR-0045 records for the
     * compliance evidence gatherer. Presence is all these items claim, and the
     * claim they make of it is deliberately narrow: a bound service is not
     * evidence that a control works, it is only proof that the subsystem the
     * configuration asked for actually started.
     */
    #[NoDiscard]
    public static function observe(ContainerInterface $container): self
    {
        $failure = null;

        if ($container->has(MasterKeyFailure::class)) {
            /** @var MasterKeyFailure $recorded */
            $recorded = $container->get(MasterKeyFailure::class);
            $failure = $recorded->reason;
        }

        return new self(
            masterKeyBound: $container->has(MasterKey::class),
            encryptorBound: $container->has(EncryptorInterface::class),
            sessionEncryptionBound: $container->has(SessionEncryption::class),
            masterKeyFailure: $failure,
        );
    }
}
