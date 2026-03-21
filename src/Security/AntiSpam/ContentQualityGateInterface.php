<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam;

use Pulsar\Api\Api;

/**
 * Enforces minimum content quality standards.
 *
 * Rejects submissions that are too short, predominantly uppercase,
 * or contain excessive character repetition.
 * @api
 */
#[Api(since: '1.0.0')]
interface ContentQualityGateInterface extends AntiSpamCheckInterface {}
