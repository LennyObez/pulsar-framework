<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam;

use Pulsar\Api\Api;

/**
 * Detects duplicate or near-duplicate submissions.
 *
 * Uses content hashing and/or text similarity to prevent
 * repeated submissions from the same source.
 * @api
 */
#[Api(since: '1.0.0')]
interface DuplicateDetectorInterface extends AntiSpamCheckInterface {}
