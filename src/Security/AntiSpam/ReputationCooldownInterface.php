<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam;

use Pulsar\Api\Api;

/**
 * Enforces cooldown periods based on user reputation tier.
 *
 * New users face longer cooldowns between submissions while
 * established users and moderators get shorter or no cooldowns.
 */
#[Api(since: '1.0.0')]
interface ReputationCooldownInterface extends AntiSpamCheckInterface {}
