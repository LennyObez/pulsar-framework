<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam;

use Pulsar\Api\Api;

/**
 * Enforces a minimum account age before allowing submissions.
 *
 * Prevents newly created throwaway accounts from immediately
 * posting spam content.
 */
#[Api(since: '1.0.0')]
interface AccountAgeGateInterface extends AntiSpamCheckInterface {}
