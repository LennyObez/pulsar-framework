<?php

declare(strict_types=1);

namespace Pulsar\Queue\Attribute;

use Attribute;
use Pulsar\Api\Api;

/**
 * Marks the job payload for AEAD encryption.
 *
 * When present, the queue system encrypts the serialized payload using
 * authenticated encryption with associated data (AEAD) before storage.
 * The payload is decrypted transparently before job execution.
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Api(since: '1.0.0')]
final readonly class Encrypted {}
