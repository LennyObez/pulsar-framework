<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance;

use Pulsar\Api\Api;

/**
 * Classification levels for data sensitivity.
 *
 * Each level carries a storage rule — what a store must do before it may hold data
 * at that level — tabulated with the framework data at each level and the control
 * that satisfies it under "Data classification scheme" in
 * `docs/security/asvs-l2-matrix.md`. The floor: `Confidential` and `Restricted` are
 * never written in plaintext, and `Restricted` is sealed under a KDF subkey of its
 * own ({@see \Pulsar\Security\Crypto\SubKeyId}) rather than the default encryption key.
 *
 * The enum records a level; it enforces nothing. It is consumed by API field
 * policies, break-glass access and compliance snapshots, all of which are read
 * paths — no storage class consults it, so the rule above is upheld by review.
 *
 * Supports controls for data handling policies across GDPR, HIPAA, PCI-DSS, and SOX.
 * @api
 */
#[Api(since: '1.0.0')]
enum DataClassification: string
{
    case Public = 'public';
    case Internal = 'internal';
    case Confidential = 'confidential';
    case Restricted = 'restricted';
}
