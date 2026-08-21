<?php

declare(strict_types=1);

namespace Pulsar\Security\Crypto;

use Pulsar\Api\Api;

/**
 * Records that a master key WAS supplied and WAS rejected.
 *
 * The distinction this type exists to preserve is the one a posture report kept
 * losing: "no PULSAR_MASTER_KEY is set" and "PULSAR_MASTER_KEY is set but does
 * not parse" produce the same container — no {@see MasterKey}, no
 * {@see EncryptorInterface}, no session encrypter, no audit logger — while
 * meaning opposite things about operator intent. The first is a deployment that
 * never asked for cryptography. The second is one that asked, was refused, and
 * carried on serving requests with sessions in cleartext.
 *
 * {@see \Pulsar\Core\Wiring\SecurityWiring} binds an instance when the parse
 * fails, so the failure survives the catch block that used to end at a local
 * `$masterKey = null`, and {@see \Pulsar\Security\Posture\SecurityRuntimeBindings}
 * reads it back out for the posture report.
 *
 * The reason string is the exception message from {@see MasterKey::fromHex()},
 * which reports lengths and hex validity and never echoes key material.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class MasterKeyFailure
{
    public function __construct(
        /** Why the supplied key was rejected. Never contains key material. */
        public string $reason,
    ) {}
}
