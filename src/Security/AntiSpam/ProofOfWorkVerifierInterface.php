<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam;

use Pulsar\Api\Api;

/**
 * Verifies a client-side computational challenge.
 *
 * Requires the client to solve a SHA-256 puzzle before submitting,
 * making automated mass-submissions computationally expensive.
 * @api
 */
#[Api(since: '1.0.0')]
interface ProofOfWorkVerifierInterface extends AntiSpamCheckInterface {}
