<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam;

use Pulsar\Api\Api;

/**
 * Analyzes the ratio of URL content to total content.
 *
 * Submissions where URLs make up a disproportionate share of the
 * body are likely link spam.
 */
#[Api(since: '1.0.0')]
interface LinkDensityCheckerInterface extends AntiSpamCheckInterface {}
