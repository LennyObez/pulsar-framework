<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam;

use Pulsar\Api\Api;

/**
 * Hidden field trap for bot detection.
 *
 * A honeypot field is a hidden form input that legitimate users never fill in.
 * Bots that auto-fill all fields will populate it, revealing themselves.
 * @api
 */
#[Api(since: '1.0.0')]
interface HoneypotDetectorInterface extends AntiSpamCheckInterface {}
